<?php

namespace Tests\Feature;

use App\Models\BoardTask;
use App\Models\BoardTerm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BoardTermTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function term(): BoardTerm
    {
        return BoardTerm::create([
            'name' => '2024–2028',
            'start_date' => '2024-01-01',
            'end_date' => '2028-12-31',
            'is_current' => true,
        ]);
    }

    #[Test]
    public function the_members_page_sends_term_dates_as_plain_dates(): void
    {
        $this->term();

        $props = $this->actingAs($this->admin())->get(route('board.members'))
            ->assertOk()->viewData('page')['props'];

        // <input type="date"> only accepts yyyy-MM-dd; a timestamp opens it empty.
        $this->assertSame('2024-01-01', $props['terms'][0]['start_date']);
        $this->assertSame('2028-12-31', $props['terms'][0]['end_date']);
    }

    #[Test]
    public function editing_a_term_stores_the_new_dates(): void
    {
        $term = $this->term();

        $this->actingAs($this->admin())
            ->put(route('board.terms.update', $term), [
                'name' => '2025–2029',
                'start_date' => '2025-03-01',
                'end_date' => '2029-02-28',
                'is_current' => true,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $term->refresh();
        $this->assertSame('2025–2029', $term->name);
        $this->assertSame('2025-03-01', $term->start_date->toDateString());
        $this->assertSame('2029-02-28', $term->end_date->toDateString());
    }

    #[Test]
    public function an_end_date_before_the_start_date_is_rejected_and_nothing_changes(): void
    {
        $term = $this->term();

        $this->actingAs($this->admin())
            ->put(route('board.terms.update', $term), [
                'name' => '2024–2028',
                'start_date' => '2026-01-01',
                'end_date' => '2025-01-01',
                'is_current' => true,
            ])
            ->assertSessionHasErrors('end_date');

        $this->assertSame('2024-01-01', $term->fresh()->start_date->toDateString());
    }

    #[Test]
    public function the_tasks_page_sends_due_dates_as_plain_dates(): void
    {
        BoardTask::create([
            'title' => 'Book the hall',
            'due_date' => '2026-10-05',
            'status' => 'not_started',
            'priority' => 'medium',
            'progress' => 0,
        ]);

        $props = $this->actingAs($this->admin())->get(route('board.tasks'))
            ->assertOk()->viewData('page')['props'];

        $this->assertSame('2026-10-05', $props['columns']['not_started'][0]['due_date']);
    }
}
