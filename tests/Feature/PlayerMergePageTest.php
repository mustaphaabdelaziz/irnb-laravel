<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Player\Merge\PlayerMerger;
use App\Services\Player\Merge\PreMergeBackup;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\BuildsDuplicatePlayers;
use Tests\TestCase;

class PlayerMergePageTest extends TestCase
{
    use BuildsDuplicatePlayers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function merger(): User
    {
        return User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create(['permissions' => ['players' => ['view', 'merge']]])->id]);
    }

    private function backupWorks(): void
    {
        $this->mock(PreMergeBackup::class, fn (MockInterface $mock) => $mock->shouldReceive('run')->once()->andReturn('backup.zip'));
    }

    #[Test]
    public function merge_routes_need_the_merge_right(): void
    {
        $this->assertSame(['players', 'merge'], PermissionMap::resolve('players.duplicates.merge'));
        $this->assertSame(['players', 'merge'], PermissionMap::resolve('players.duplicates.merge.store'));
    }

    #[Test]
    public function the_page_compares_both_with_the_older_record_kept_by_default(): void
    {
        $older = $this->player(['firstname' => 'Sami', 'lastname' => 'Amrani', 'father' => null]);
        $newer = $this->player(['firstname' => 'Sammy', 'lastname' => 'Amrani', 'father' => 'Ali']);
        $this->registration($newer, $this->branch());

        $this->actingAs($this->merger())
            ->get(route('players.duplicates.merge', ['a' => $newer->id, 'b' => $older->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Players/MergeDuplicates')
                ->where('keeper.id', $older->id)
                ->where('duplicate.id', $newer->id)
                ->where('counts.branch_player', 1)
                ->where('viewOnly', false)
                ->has('fields', 15)
                ->where('fields', fn ($fields) => ! collect($fields)->contains('field', 'licence_number')));
    }

    #[Test]
    public function keeper_can_be_swapped_and_view_mode_passes_through(): void
    {
        $a = $this->player();
        $b = $this->player();

        $this->actingAs($this->merger())
            ->get(route('players.duplicates.merge', ['a' => $a->id, 'b' => $b->id, 'keeper' => $b->id, 'view' => 1]))
            ->assertInertia(fn (Assert $page) => $page->where('keeper.id', $b->id)->where('viewOnly', true));
    }

    #[Test]
    public function a_vanished_player_sends_back_to_the_list(): void
    {
        $a = $this->player();

        $this->actingAs($this->merger())
            ->get(route('players.duplicates.merge', ['a' => $a->id, 'b' => 999999]))
            ->assertRedirect(route('players.duplicates.index'))
            ->assertSessionHas('error', 'flash.dup.gone');
    }

    #[Test]
    public function confirming_backs_up_merges_logs_and_opens_the_kept_player(): void
    {
        $keeper = $this->player(['firstname' => 'Sami', 'lastname' => 'Amrani']);
        $duplicate = $this->player(['firstname' => 'Sammy', 'lastname' => 'Amrani']);
        $this->backupWorks();
        $user = $this->merger();

        $this->actingAs($user)
            ->post(route('players.duplicates.merge.store'), ['a' => $keeper->id, 'b' => $duplicate->id, 'keeper' => $keeper->id, 'choices' => ['firstname' => 'duplicate']])
            ->assertRedirect(route('players.show', $keeper))
            ->assertSessionHas('success', 'flash.dup.merged');

        $this->assertDatabaseMissing('players', ['id' => $duplicate->id]);
        $this->assertSame('Sammy', $keeper->fresh()->firstname);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id, 'action' => ActivityAction::PLAYERS_MERGED, 'subject_id' => $keeper->id,
        ]);
    }

    #[Test]
    public function a_failed_backup_merges_nothing(): void
    {
        $keeper = $this->player();
        $duplicate = $this->player();
        $this->mock(PreMergeBackup::class, fn (MockInterface $mock) => $mock->shouldReceive('run')->once()->andThrow(new RuntimeException('No backup folder has been chosen yet.')));

        $this->actingAs($this->merger())
            ->post(route('players.duplicates.merge.store'), ['a' => $keeper->id, 'b' => $duplicate->id, 'keeper' => $keeper->id])
            ->assertSessionHas('error', 'flash.dup.backup_failed');

        $this->assertDatabaseHas('players', ['id' => $duplicate->id]);
    }

    #[Test]
    public function a_failed_merge_reports_and_changes_nothing(): void
    {
        $keeper = $this->player();
        $duplicate = $this->player();
        $this->backupWorks();
        $this->mock(PlayerMerger::class, fn (MockInterface $mock) => $mock->shouldReceive('merge')->once()->andThrow(new RuntimeException('boom')));

        $this->actingAs($this->merger())
            ->post(route('players.duplicates.merge.store'), ['a' => $keeper->id, 'b' => $duplicate->id, 'keeper' => $keeper->id])
            ->assertSessionHas('error', 'flash.dup.merge_failed');

        $this->assertDatabaseHas('players', ['id' => $duplicate->id]);
    }

    #[Test]
    public function the_keeper_must_be_one_of_the_pair(): void
    {
        $a = $this->player();
        $b = $this->player();
        $c = $this->player();

        $this->actingAs($this->merger())
            ->post(route('players.duplicates.merge.store'), ['a' => $a->id, 'b' => $b->id, 'keeper' => $c->id])
            ->assertSessionHasErrors('keeper');
    }
}
