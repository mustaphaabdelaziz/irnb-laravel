<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BranchFeatureTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['privileges' => ['admin'], 'email_verified_at' => now()]);
    }

    private function player(string $membershipId): Player
    {
        return Player::create(['firstname' => 'P'.$membershipId, 'membership_id' => $membershipId, 'join_year' => 2024]);
    }

    #[Test]
    public function members_can_be_assigned_from_the_branches_menu(): void
    {
        $branch = Branch::create(['name' => 'Swimming']);
        $a = $this->player('202400001');
        $b = $this->player('202400002');

        $this->actingAs($this->admin())
            ->post(route('branches.players.sync', $branch), ['player_ids' => [$a->id, $b->id], 'join_date' => '2024-01-15'])
            ->assertRedirect();

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $branch->fresh()->players->pluck('id')->all());

        // Re-syncing replaces the set; an empty array marks everyone as left.
        $this->actingAs($this->admin())
            ->post(route('branches.players.sync', $branch), ['player_ids' => []])
            ->assertRedirect();
        $left = PlayerStatus::where('code', 'left')->value('id');
        $this->assertSame([$left, $left], $branch->registrations()->pluck('status_id')->all());
    }

    #[Test]
    public function the_branches_page_exposes_players_and_member_ids(): void
    {
        $branch = Branch::create(['name' => 'Football']);
        $p = $this->player('202400003');
        $branch->players()->sync([$p->id]);

        $this->actingAs($this->admin())
            ->get(route('branches.index'))
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Branches')
                ->has('players', 1)
                ->has('branches.0.players', 1));
    }

    #[Test]
    public function it_creates_a_branch_with_locale_names(): void
    {
        $this->actingAs($this->admin())
            ->post(route('branches.store'), [
                'name' => 'Swimming',
                'name_ar' => 'السباحة',
                'name_fr' => 'Natation',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('branches', ['name' => 'Swimming', 'name_ar' => 'السباحة']);
    }

    #[Test]
    public function creating_a_player_syncs_selected_branches(): void
    {
        $swim = Branch::create(['name' => 'Swimming', 'code' => 'SW']);
        $foot = Branch::create(['name' => 'Football', 'code' => 'FB']);

        $this->actingAs($this->admin())
            ->post(route('players.store'), [
                'firstname' => 'Ali', 'lastname' => 'Test',
                'registrations' => [
                    ['branch_id' => $swim->id, 'join_date' => '2024-01-15'],
                    ['branch_id' => $foot->id, 'join_date' => '2024-01-15'],
                ],
            ])
            ->assertRedirect();

        $player = Player::firstOrFail();
        $this->assertEqualsCanonicalizing([$swim->id, $foot->id], $player->branches->pluck('id')->all());
    }

    #[Test]
    public function updating_a_player_marks_a_dropped_branch_left_instead_of_deleting_it(): void
    {
        $swim = Branch::create(['name' => 'Swimming', 'code' => 'SW']);
        $foot = Branch::create(['name' => 'Football', 'code' => 'FB']);
        $player = Player::create(['firstname' => 'Ali', 'membership_id' => '202400001', 'join_year' => 2024, 'is_student' => true]);
        $player->branches()->sync([$swim->id]);

        $this->actingAs($this->admin())
            ->put(route('players.update', $player), [
                'firstname' => 'Ali', 'lastname' => 'Test',
                'registrations' => [['branch_id' => $foot->id, 'join_date' => '2024-01-15']],
            ])
            ->assertRedirect();

        // Both registrations remain; the dropped one is marked left.
        $this->assertEqualsCanonicalizing([$swim->id, $foot->id], $player->registrations()->pluck('branch_id')->all());
        $this->assertSame(Player::leftStatusId(), $player->registrations()->where('branch_id', $swim->id)->value('status_id'));
        $this->assertNotSame(Player::leftStatusId(), $player->registrations()->where('branch_id', $foot->id)->value('status_id'));
    }

    /**
     * Once the club has branches, every player must belong to one. PlayerForm.vue
     * sends '' when the user clears every branch (forceFormData drops empty
     * arrays), so both '' and an omitted key are rejected and the player's
     * branches stay as they were.
     */
    #[Test]
    public function clearing_every_branch_is_rejected_and_leaves_them(): void
    {
        $swim = Branch::create(['name' => 'Swimming', 'code' => 'SW']);
        $player = Player::create(['firstname' => 'Ali', 'membership_id' => '202400004', 'join_year' => 2024]);
        $player->branches()->sync([$swim->id]);

        $this->actingAs($this->admin())->put(route('players.update', $player), [
            'firstname' => 'Ali', 'lastname' => 'Test',
            'join_year' => 2024,
            'registrations' => '',
        ])->assertSessionHasErrors(['registrations']);

        $this->put(route('players.update', $player), [
            'firstname' => 'Ali', 'lastname' => 'Test',
            'join_year' => 2024,
        ])->assertSessionHasErrors(['registrations']);

        $this->assertSame([$swim->id], $player->fresh()->branches->pluck('id')->all());
    }

    #[Test]
    public function the_list_can_be_filtered_by_branch(): void
    {
        $swim = Branch::create(['name' => 'Swimming']);
        $inSwim = Player::create(['firstname' => 'A', 'membership_id' => '202400001', 'join_year' => 2024]);
        $notInSwim = Player::create(['firstname' => 'B', 'membership_id' => '202400002', 'join_year' => 2024]);
        $inSwim->branches()->sync([$swim->id => ['membership_id' => '202400001']]);

        $this->actingAs($this->admin())
            ->get(route('players.export', ['branch_id' => $swim->id]))
            ->assertOk();

        $content = $this->get(route('players.export', ['branch_id' => $swim->id, 'format' => 'csv']))->streamedContent();
        $this->assertStringContainsString('202400001', $content);
        $this->assertStringNotContainsString('202400002', $content);
    }

    #[Test]
    public function a_branch_is_created_with_its_own_logo(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())
            ->post(route('branches.store'), [
                'name' => 'Swimming',
                'logo' => UploadedFile::fake()->image('swim.png', 400, 400),
            ])
            ->assertRedirect();

        $branch = Branch::where('name', 'Swimming')->firstOrFail();
        $this->assertStringStartsWith('/media/branches/', $branch->logo_url);
        Storage::disk('public')->assertExists($branch->logo_filename);
        // The disk path is internal; pages only get the URL.
        $this->assertArrayNotHasKey('logo_filename', $branch->toArray());
    }

    #[Test]
    public function replacing_a_branch_logo_deletes_the_old_file(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('branches.store'), [
            'name' => 'Swimming',
            'logo' => UploadedFile::fake()->image('old.png'),
        ]);
        $branch = Branch::where('name', 'Swimming')->firstOrFail();
        $old = $branch->logo_filename;

        $this->actingAs($admin)
            ->post(route('branches.update', $branch), [
                '_method' => 'put',
                'name' => 'Swimming',
                'logo' => UploadedFile::fake()->image('new.png'),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $branch->refresh();
        $this->assertNotSame($old, $branch->logo_filename);
        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($branch->logo_filename);
    }

    #[Test]
    public function a_failed_save_keeps_the_old_logo_and_drops_the_new_upload(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('branches.store'), [
            'name' => 'Swimming',
            'logo' => UploadedFile::fake()->image('old.png'),
        ]);
        $branch = Branch::where('name', 'Swimming')->firstOrFail();
        $old = $branch->logo_filename;

        Branch::updating(fn () => throw new \RuntimeException('database down'));

        $this->actingAs($admin)
            ->post(route('branches.update', $branch), [
                '_method' => 'put',
                'name' => 'Swimming',
                'logo' => UploadedFile::fake()->image('new.png'),
            ])
            ->assertServerError();

        $this->assertSame($old, $branch->fresh()->logo_filename);
        $this->assertSame([$old], Storage::disk('public')->files('branches'));
    }

    #[Test]
    public function a_branch_logo_can_be_removed_and_is_kept_when_not_touched(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('branches.store'), [
            'name' => 'Swimming',
            'logo' => UploadedFile::fake()->image('swim.png'),
        ]);
        $branch = Branch::where('name', 'Swimming')->firstOrFail();
        $file = $branch->logo_filename;

        // Renaming without a file keeps the logo.
        $this->actingAs($admin)->put(route('branches.update', $branch), ['name' => 'Natation']);
        $this->assertSame($file, $branch->fresh()->logo_filename);

        $this->actingAs($admin)
            ->put(route('branches.update', $branch), ['name' => 'Natation', 'remove_logo' => true])
            ->assertSessionHasNoErrors();

        $branch->refresh();
        $this->assertNull($branch->logo_url);
        $this->assertNull($branch->logo_filename);
        Storage::disk('public')->assertMissing($file);
    }

    #[Test]
    public function deleting_a_branch_deletes_its_logo(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('branches.store'), [
            'name' => 'Swimming',
            'logo' => UploadedFile::fake()->image('swim.png'),
        ]);
        $branch = Branch::where('name', 'Swimming')->firstOrFail();
        $file = $branch->logo_filename;

        $this->actingAs($admin)->delete(route('branches.destroy', $branch))->assertRedirect();

        Storage::disk('public')->assertMissing($file);
    }

    #[Test]
    public function a_branch_logo_must_be_an_image(): void
    {
        $this->actingAs($this->admin())
            ->post(route('branches.store'), [
                'name' => 'Swimming',
                'logo' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
            ])
            ->assertSessionHasErrors(['logo']);

        $this->assertDatabaseMissing('branches', ['name' => 'Swimming']);
    }

    #[Test]
    public function the_player_page_carries_each_branch_logo(): void
    {
        $branch = Branch::create(['name' => 'Swimming', 'logo_url' => '/media/branches/swim.png', 'logo_filename' => 'branches/swim.png']);
        $player = $this->player('202400009');
        $player->branches()->sync([$branch->id]);

        $this->actingAs($this->admin())
            ->get(route('players.show', $player))
            ->assertInertia(fn ($page) => $page
                ->where('player.branches.0.logo_url', '/media/branches/swim.png')
                ->missing('player.branches.0.logo_filename'));
    }
}
