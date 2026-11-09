<?php

namespace Tests\Feature;

use App\Models\BoardCommittee;
use App\Models\BoardMember;
use App\Models\BoardTerm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BoardTermCopyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private BoardTerm $previous;

    private BoardTerm $new;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create(['email_verified_at' => now()]);
        BoardTerm::query()->update(['is_current' => false]);
        $this->previous = BoardTerm::create(['name' => 'prev', 'start_date' => '2021-03-01', 'is_current' => false]);
        $this->new = BoardTerm::create(['name' => 'next', 'start_date' => '2025-03-01', 'is_current' => true]);
    }

    private function member(BoardTerm $term, string $name, string $role, string $status = 'active'): BoardMember
    {
        return BoardMember::create([
            'board_term_id' => $term->id, 'name' => $name, 'role' => $role, 'status' => $status, 'sort_order' => 1,
        ]);
    }

    private function seedPrevious(): void
    {
        $this->member($this->previous, 'A', 'president');
        $b = $this->member($this->previous, 'B', 'member');
        $this->member($this->previous, 'F', 'member', 'former');
        $committee = BoardCommittee::create(['name' => 'C']);
        $committee->members()->attach($b->id, ['position' => 'head']);
    }

    #[Test]
    public function an_empty_term_copies_the_previous_terms_active_members_roles_and_seats(): void
    {
        $this->seedPrevious();

        $this->actingAs($this->admin)->post(route('board.terms.copy-previous', $this->new))
            ->assertSessionHas('success', 'flash.term_members_copied');

        $copied = BoardMember::where('board_term_id', $this->new->id)->get();
        $this->assertEqualsCanonicalizing(['A', 'B'], $copied->pluck('name')->all());
        $this->assertSame('president', $copied->firstWhere('name', 'A')->role);
        $this->assertSame('head', $copied->firstWhere('name', 'B')->committees()->first()->pivot->position);
        $this->assertSame(3, BoardMember::where('board_term_id', $this->previous->id)->count());
    }

    #[Test]
    public function a_term_with_members_is_refused(): void
    {
        $this->seedPrevious();
        $this->member($this->new, 'X', 'member');

        $this->actingAs($this->admin)->post(route('board.terms.copy-previous', $this->new))
            ->assertSessionHas('error', 'flash.term_not_empty');

        $this->assertSame(1, BoardMember::where('board_term_id', $this->new->id)->count());
    }

    #[Test]
    public function nothing_to_copy_is_refused(): void
    {
        $this->member($this->previous, 'F', 'member', 'former');

        $this->actingAs($this->admin)->post(route('board.terms.copy-previous', $this->new))
            ->assertSessionHas('error', 'flash.term_nothing_to_copy');

        $this->assertSame(0, BoardMember::where('board_term_id', $this->new->id)->count());
    }

    #[Test]
    public function previous_follows_start_dates_then_ids(): void
    {
        BoardTerm::query()->delete();
        $x = BoardTerm::create(['name' => 'X', 'start_date' => '2017-03-01']);
        $y = BoardTerm::create(['name' => 'Y', 'start_date' => '2021-03-01']);
        $z = BoardTerm::create(['name' => 'Z', 'start_date' => '2025-03-01']);
        $w = BoardTerm::create(['name' => 'W']);

        $this->assertSame($y->id, $z->previous()->id);
        $this->assertSame($x->id, $y->previous()->id);
        $this->assertNull($x->previous());
        $this->assertSame($z->id, $w->previous()->id);
    }

    #[Test]
    public function the_members_page_offers_the_copy_only_on_an_empty_term(): void
    {
        $this->seedPrevious();

        $this->actingAs($this->admin)->get(route('board.members', ['term' => $this->new->id]))
            ->assertInertia(fn ($page) => $page
                ->where('previousTerm.name', 'prev')
                ->where('previousTerm.count', 2));

        $this->actingAs($this->admin)->get(route('board.members', ['term' => $this->previous->id]))
            ->assertInertia(fn ($page) => $page->where('previousTerm', null));
    }
}
