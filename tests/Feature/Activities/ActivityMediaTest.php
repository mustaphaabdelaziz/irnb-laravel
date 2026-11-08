<?php

namespace Tests\Feature\Activities;

use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\ActivityType;
use App\Models\Role;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Storage\PrivateFileStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ActivityMediaTest extends TestCase
{
    use RefreshDatabase;

    private Activity $activity;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake(PrivateFileStorage::DISK);
        $this->activity = Activity::create(['title' => 'Camp', 'activity_type_id' => ActivityType::first()->id, 'starts_on' => '2026-07-05']);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function uploadPhotos(int $count = 1)
    {
        $photos = array_map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg", 2400, 1600), range(1, $count));

        return $this->post(route('activities.photos.store', $this->activity), ['photos' => $photos]);
    }

    #[Test]
    public function photos_are_stored_in_two_sizes(): void
    {
        $this->actingAs($this->admin());
        $this->uploadPhotos(2)->assertSessionHas('success', 'flash.activity_photos_added');

        $photos = $this->activity->photos()->get();
        $this->assertCount(2, $photos);
        foreach ($photos as $photo) {
            Storage::disk('public')->assertExists($photo->path);
            Storage::disk('public')->assertExists($photo->thumb_path);
            $this->assertStringStartsWith('/media/activities/'.$this->activity->id.'/', $photo->url);
            [$width] = getimagesizefromstring(Storage::disk('public')->get($photo->thumb_path));
            $this->assertSame(400, $width);
        }
        $this->assertSame([1, 2], $photos->pluck('sort_order')->all());
    }

    #[Test]
    public function only_images_are_accepted(): void
    {
        $this->actingAs($this->admin())
            ->post(route('activities.photos.store', $this->activity), ['photos' => [UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')]])
            ->assertSessionHasErrors('photos.0');
    }

    #[Test]
    public function marking_a_report_photo_moves_the_mark(): void
    {
        $this->actingAs($this->admin());
        $this->uploadPhotos(3);
        [$first, $second] = $this->activity->photos()->get()->all();

        $this->put(route('activities.photos.update', [$this->activity, $first]), ['in_report' => true, 'caption' => 'Opening'])
            ->assertSessionHas('success', 'flash.activity_photo_updated');
        $this->put(route('activities.photos.update', [$this->activity, $second]), ['in_report' => true])
            ->assertSessionHas('success', 'flash.activity_photo_updated');

        $this->assertSame([$second->id], $this->activity->photos()->where('in_report', true)->pluck('id')->all());
        $this->assertSame('Opening', $first->fresh()->caption);

        $this->put(route('activities.photos.update', [$this->activity, $second]), ['in_report' => false]);
        $this->assertSame(0, $this->activity->photos()->where('in_report', true)->count());
    }

    #[Test]
    public function deleting_a_photo_removes_its_files(): void
    {
        $this->actingAs($this->admin());
        $this->uploadPhotos();
        $photo = $this->activity->photos()->first();

        $this->delete(route('activities.photos.destroy', [$this->activity, $photo]))
            ->assertSessionHas('success', 'flash.activity_photo_deleted');

        $this->assertModelMissing($photo);
        Storage::disk('public')->assertMissing($photo->path);
        Storage::disk('public')->assertMissing($photo->thumb_path);
    }

    #[Test]
    public function a_photo_of_another_activity_is_not_reachable(): void
    {
        $this->actingAs($this->admin());
        $this->uploadPhotos();
        $photo = $this->activity->photos()->first();
        $other = Activity::create(['title' => 'Other', 'activity_type_id' => ActivityType::first()->id, 'starts_on' => '2026-01-01']);

        $this->delete(route('activities.photos.destroy', [$other, $photo]))->assertNotFound();
    }

    #[Test]
    public function files_are_private_and_served_to_viewers_only(): void
    {
        $this->actingAs($this->admin())
            ->post(route('activities.files.store', $this->activity), ['file' => UploadedFile::fake()->create('report.pdf', 200, 'application/pdf')])
            ->assertSessionHas('success', 'flash.activity_file_uploaded');

        $file = $this->activity->files()->sole();
        $this->assertSame('report.pdf', $file->original_name);
        Storage::disk(PrivateFileStorage::DISK)->assertExists($file->path);
        Storage::disk('public')->assertMissing($file->path);

        $this->get(route('activities.files.show', [$this->activity, $file]))->assertOk();

        $nobody = User::factory()->create(['role_id' => Role::factory()->create(['permissions' => []])->id]);
        $this->actingAs($nobody)->get(route('activities.files.show', [$this->activity, $file]))->assertForbidden();
    }

    #[Test]
    public function deleting_a_file_removes_it_from_disk(): void
    {
        $this->actingAs($this->admin())
            ->post(route('activities.files.store', $this->activity), ['file' => UploadedFile::fake()->create('a.docx', 50)]);
        $file = $this->activity->files()->sole();

        $this->delete(route('activities.files.destroy', [$this->activity, $file]))
            ->assertSessionHas('success', 'flash.activity_file_deleted');

        $this->assertModelMissing($file);
        Storage::disk(PrivateFileStorage::DISK)->assertMissing($file->path);
    }

    #[Test]
    public function deleting_the_activity_removes_photos_and_files(): void
    {
        $this->actingAs($this->admin());
        $this->uploadPhotos();
        $this->post(route('activities.files.store', $this->activity), ['file' => UploadedFile::fake()->create('a.pdf', 50, 'application/pdf')]);
        $photo = $this->activity->photos()->first();
        $file = $this->activity->files()->first();

        $this->delete(route('activities.destroy', $this->activity));

        Storage::disk('public')->assertMissing($photo->path);
        Storage::disk('public')->assertMissing($photo->thumb_path);
        Storage::disk(PrivateFileStorage::DISK)->assertMissing($file->path);
    }

    /** activity_updated log rows, as [subject_type, subject_id] pairs. */
    private function updateLogs(): array
    {
        return ActivityLog::where('action', ActivityAction::ACTIVITY_UPDATED)->orderBy('id')->get()
            ->map(fn (ActivityLog $log) => [$log->subject_type, (int) $log->subject_id])->all();
    }

    #[Test]
    public function photo_and_file_changes_are_logged_against_the_activity(): void
    {
        $this->actingAs($this->admin());
        $subject = [$this->activity->getMorphClass(), $this->activity->id];

        $this->uploadPhotos()->assertSessionHas('success');
        $photo = $this->activity->photos()->first();
        $this->put(route('activities.photos.update', [$this->activity, $photo]), ['caption' => 'Opening'])->assertSessionHas('success');
        $this->delete(route('activities.photos.destroy', [$this->activity, $photo]))->assertSessionHas('success');
        $this->post(route('activities.files.store', $this->activity), ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])->assertSessionHas('success');
        $file = $this->activity->files()->sole();
        $this->delete(route('activities.files.destroy', [$this->activity, $file]))->assertSessionHas('success');

        $this->assertSame(array_fill(0, 5, $subject), $this->updateLogs());
    }

    #[Test]
    public function a_viewer_cannot_change_photos_or_files(): void
    {
        $viewer = User::factory()->create(['role_id' => Role::factory()->create(['permissions' => ['activities' => ['view']]])->id]);

        $this->actingAs($viewer)
            ->post(route('activities.photos.store', $this->activity), ['photos' => [UploadedFile::fake()->image('p.jpg')]])
            ->assertForbidden();
        $this->actingAs($viewer)
            ->post(route('activities.files.store', $this->activity), ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])
            ->assertForbidden();
    }
}
