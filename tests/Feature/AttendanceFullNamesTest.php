<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Player;
use App\Models\TrainingSession;
use App\Services\Pdf\PdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

/** Every attendance list names a player by Player::fullname (nickname, father, grandfather included). */
class AttendanceFullNamesTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private Player $player;

    private TrainingSession $training;

    protected function setUp(): void
    {
        parent::setUp();
        $u15 = $this->category();
        $this->player = $this->player($u15, ['nickname' => 'Zizou', 'father' => 'Ali', 'grandfather' => 'Omar']);
        $this->training = TrainingSession::create([
            'category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ]);
    }

    private function fullname(): string
    {
        return $this->player->fresh()->fullname;
    }

    /** Swaps mPDF for a spy; returns a reference filled with the rendered HTML. */
    private function spyPdf(): \ArrayObject
    {
        $seen = new \ArrayObject;
        $this->mock(PdfService::class, function (MockInterface $mock) use ($seen) {
            $mock->shouldReceive('stream')->once()->andReturnUsing(function (string $html) use ($seen) {
                $seen['html'] = $html;

                return response('%PDF-spy', 200, ['Content-Type' => 'application/pdf']);
            });
        });

        return $seen;
    }

    #[Test]
    public function the_full_name_has_every_part(): void
    {
        $this->assertStringContainsString('(Zizou)', $this->fullname());
        $this->assertStringContainsString('Ali', $this->fullname());
        $this->assertStringContainsString('Omar', $this->fullname());
    }

    #[Test]
    public function the_session_page_and_the_month_grid_show_full_names(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('attendance.sessions.show', $this->training))
            ->assertInertia(fn (Assert $page) => $page->where('rows.0.name', $this->fullname()));

        $this->actingAs($admin)->get(route('attendance.grid', ['category_id' => $this->training->category_id, 'month' => '2026-10']))
            ->assertInertia(fn (Assert $page) => $page->where('rows.0.name', $this->fullname()));
    }

    #[Test]
    public function the_session_sheet_prints_full_names(): void
    {
        $seen = $this->spyPdf();

        $this->actingAs($this->admin())->get(route('attendance.sheets.session', $this->training))->assertOk();

        $this->assertStringContainsString(e($this->fullname()), $seen['html']);
    }

    #[Test]
    public function the_month_sheet_prints_full_names(): void
    {
        $seen = $this->spyPdf();

        $this->actingAs($this->admin())->get(route('attendance.sheets.month', ['category_id' => $this->training->category_id, 'month' => '2026-10']))->assertOk();

        $this->assertStringContainsString(e($this->fullname()), $seen['html']);
    }
}
