<?php

namespace Tests\Feature\Dashboard;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Branch;
use App\Models\Player;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DashboardDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    private function player(array $attributes = []): Player
    {
        $this->sequence++;

        return Player::create([
            'membership_id' => '20266'.str_pad((string) $this->sequence, 4, '0', STR_PAD_LEFT),
            'firstname' => 'Member'.$this->sequence,
            'lastname' => 'Docs',
            'birthdate' => '1990-01-01',
            ...$attributes,
        ]);
    }

    private function userWith(array $permissions): User
    {
        return User::factory()->create([
            'privileges' => ['user'],
            'role_id' => Role::factory()->create(['permissions' => $permissions])->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    /** The members tab payload, as the tab's partial reload receives it. */
    private function members(User $user, array $query = []): array
    {
        return $this->actingAs($user)
            ->get(route('dashboard', $query), [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
                'X-Inertia-Partial-Component' => 'Dashboard',
                'X-Inertia-Partial-Data' => 'members',
            ])
            ->assertOk()
            ->json('props.members');
    }

    #[Test]
    public function the_members_tab_reports_the_documents_of_current_members_as_of_today(): void
    {
        $longAgo = $this->player();
        Player::query()->whereKey($longAgo->id)->update(['created_at' => '2020-01-01 00:00:00']);
        $this->player(['archived' => true]);
        $this->player(['status_id' => Player::leftStatusId(), 'left_at' => '2026-01-10']);

        // "This month" does not narrow it: completion is a snapshot of today.
        $documents = $this->members(User::factory()->admin()->create(), ['range' => 'month'])['documents'];

        $this->assertSame(1, $documents['summary']['players_total']);
        $this->assertSame(3, $documents['summary']['required']);
        $this->assertSame(0, $documents['summary']['received']);
        $this->assertSame(3, $documents['summary']['missing']);
        $this->assertEquals(0, $documents['summary']['pct']);
        $this->assertCount(4, $documents['summary']['by_type']);
        $this->assertCount(1, $documents['by_category']);
        $this->assertNull($documents['by_category'][0]['category_id']);
    }

    #[Test]
    public function the_branch_filter_narrows_the_members_counted(): void
    {
        $football = Branch::create(['name' => 'Football']);
        $handball = Branch::create(['name' => 'Handball']);
        $this->player()->branches()->attach($football->id);
        $this->player()->branches()->attach($football->id);
        $this->player()->branches()->attach($handball->id);
        $admin = User::factory()->admin()->create();

        $this->assertSame(3, $this->members($admin)['documents']['summary']['players_total']);
        $this->assertSame(2, $this->members($admin, ['branch' => $football->id])['documents']['summary']['players_total']);
        $this->assertSame(1, $this->members($admin, ['branch' => $handball->id])['documents']['summary']['players_total']);
    }

    #[Test]
    public function without_documents_view_the_members_tab_carries_no_documents(): void
    {
        $this->player();

        $members = $this->members($this->userWith(['players' => ['view']]));
        $this->assertArrayHasKey('documents', $members);
        $this->assertNull($members['documents']);

        $allowed = $this->members($this->userWith(['players' => ['view'], 'documents' => ['view']]));
        $this->assertSame(1, $allowed['documents']['summary']['players_total']);
    }
}
