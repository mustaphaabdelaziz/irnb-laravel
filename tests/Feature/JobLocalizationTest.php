<?php

namespace Tests\Feature;

use App\Models\MemberJob;
use App\Models\Player;
use App\Models\User;
use Database\Seeders\MemberJobSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\App;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class JobLocalizationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    #[Test]
    public function a_job_stores_a_name_per_language(): void
    {
        $this->actingAs($this->admin())->post(route('jobs.store'), [
            'name' => 'Enseignant',
            'name_fr' => 'Enseignant',
            'name_ar' => 'أستاذ',
            'name_en' => 'Teacher',
        ])->assertRedirect();

        $job = MemberJob::query()->firstOrFail();

        App::setLocale('ar');
        $this->assertSame('أستاذ', $job->localized_name);

        App::setLocale('en');
        $this->assertSame('Teacher', $job->localized_name);
    }

    #[Test]
    public function the_seeded_french_names_become_the_french_column(): void
    {
        // RefreshDatabase does not run seeders, and the app's own seed run
        // (a fresh install) migrates before seeding, so the migration's
        // backfill never sees these rows. The seeder itself must set name_fr.
        $this->seed(MemberJobSeeder::class);

        $job = MemberJob::query()->where('name', 'Médecin')->first();

        $this->assertNotNull($job, 'the seeder still creates the French names');
        $this->assertSame('Médecin', $job->name_fr);
    }

    #[Test]
    public function a_job_in_use_cannot_be_deleted(): void
    {
        $job = MemberJob::create(['name' => 'Plombier', 'name_fr' => 'Plombier']);
        Player::create(['membership_id' => '202600001', 'firstname' => 'Amine', 'member_job_id' => $job->id]);

        $this->actingAs($this->admin())
            ->delete(route('jobs.destroy', $job))
            ->assertSessionHas('error', 'flash.job_in_use');

        $this->assertNotNull($job->fresh());
    }

    #[Test]
    public function an_unused_job_can_be_deleted(): void
    {
        $job = MemberJob::create(['name' => 'Plombier', 'name_fr' => 'Plombier']);

        $this->actingAs($this->admin())
            ->delete(route('jobs.destroy', $job))
            ->assertSessionHas('success', 'flash.job_deleted');

        $this->assertNull($job->fresh());
    }

    #[Test]
    public function merging_moves_every_member_then_removes_the_duplicate(): void
    {
        $keep = MemberJob::create(['name' => 'Ingénieur', 'name_fr' => 'Ingénieur']);
        $duplicate = MemberJob::create(['name' => 'ingenieur', 'name_fr' => 'ingenieur']);
        $player = Player::create(['membership_id' => '202600002', 'firstname' => 'Amine', 'member_job_id' => $duplicate->id]);

        $this->actingAs($this->admin())
            ->post(route('jobs.merge', $duplicate), ['into' => $keep->id])
            ->assertSessionHas('success', 'flash.job_merged');

        $this->assertSame($keep->id, $player->fresh()->member_job_id);
        $this->assertNull($duplicate->fresh());
    }

    #[Test]
    public function a_job_cannot_be_merged_into_itself(): void
    {
        $job = MemberJob::create(['name' => 'Ingénieur', 'name_fr' => 'Ingénieur']);

        $this->actingAs($this->admin())
            ->post(route('jobs.merge', $job), ['into' => $job->id])
            ->assertSessionHasErrors('into');
    }

    #[Test]
    public function the_import_matches_a_job_in_any_language(): void
    {
        MemberJob::create(['name' => 'Enseignant', 'name_fr' => 'Enseignant', 'name_ar' => 'أستاذ']);

        $cells = array_fill(0, 21, '');
        $cells[0] = 'Amine';
        $cells[13] = 'أستاذ'; // the job column
        $csv = "\xEF\xBB\xBF".implode("\n", [implode(',', array_fill(0, 21, 'h')), implode(',', $cells)])."\n";

        $this->actingAs($this->admin())->post(route('players.import.store'), [
            'file' => UploadedFile::fake()->createWithContent('players.csv', $csv),
        ])->assertRedirect();

        $this->assertSame('Enseignant', Player::query()->firstOrFail()->memberJob->name);
    }
}
