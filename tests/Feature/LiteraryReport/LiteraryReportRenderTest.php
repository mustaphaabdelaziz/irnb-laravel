<?php

namespace Tests\Feature\LiteraryReport;

use App\Enums\GameStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Activity;
use App\Models\ActivityType;
use App\Models\Attendance;
use App\Models\BoardMember;
use App\Models\BoardTerm;
use App\Models\FinanceCategory;
use App\Models\GameEvent;
use App\Models\LiteraryReport;
use App\Models\TrainingSession;
use App\Models\Transaction;
use App\Services\LiteraryReport\LiteraryReportBuilder;
use App\Services\Pdf\ClubHeader;
use App\Support\UiLang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

/** Renders the PDF HTML with one row per section, so the partials run against real data shapes. */
class LiteraryReportRenderTest extends TestCase
{
    use CompetitionFixtures;
    use RefreshDatabase;

    private int $playerSeq = 0;

    #[Test]
    public function every_section_partial_renders_its_title_and_data(): void
    {
        $admin = $this->admin();
        $u15 = $this->category('U15');
        $branch = $this->sportBranch('Football');
        $player = $this->player($u15, ['firstname' => 'Riyad', 'lastname' => 'Mahrez']);
        $player->branches()->attach($branch->id);

        BoardTerm::query()->update(['is_current' => false]);
        $term = BoardTerm::create(['name' => 'Term RenderTest', 'is_current' => true]);
        BoardMember::create(['name' => 'Board Person', 'role' => 'member', 'board_term_id' => $term->id, 'status' => 'active']);

        $session = TrainingSession::create([
            'category_id' => $u15->id, 'branch_id' => $branch->id, 'date' => '2026-03-10', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ]);
        Attendance::create(['training_session_id' => $session->id, 'player_id' => $player->id, 'status' => 'present', 'category_id' => $u15->id]);

        $team = $this->team($u15, ['name' => 'Team Render']);
        $game = $this->game($team, ['date' => '2026-02-01', 'status' => GameStatus::Played, 'our_score' => 1, 'their_score' => 0]);
        GameEvent::create(['game_id' => $game->id, 'type' => 'goal', 'subtype' => 'normal', 'minute' => 10, 'player_id' => $player->id]);

        Activity::create([
            'title' => 'Render Activity', 'activity_type_id' => ActivityType::query()->value('id'), 'starts_on' => '2026-05-05',
        ]);

        $category = FinanceCategory::create(['type' => 'income', 'name' => 'Render Donations']);
        Transaction::create([
            'title' => 'T', 'amount' => 500, 'transaction_date' => '2026-04-01', 'transaction_type' => 'income',
            'category' => 'donation', 'payment_method' => 'cash', 'status' => 'Paid', 'finance_category_id' => $category->id,
        ]);

        $report = LiteraryReport::create([
            'title' => 'Render Report', 'period_from' => '2025-09-01', 'period_to' => '2026-08-31',
            'sections' => LiteraryReport::SECTIONS, 'president_word' => 'Render president word', 'conclusion' => 'Render outlook',
        ]);

        $html = view('pdf.literary-report', [
            'club' => ClubHeader::data(),
            'report' => $report,
            'sections' => app(LiteraryReportBuilder::class)->build($report, $admin),
            'rtl' => true,
        ])->render();

        foreach (LiteraryReport::SECTIONS as $key) {
            if ($key === 'cover') {
                continue; // the cover has no section heading
            }
            $this->assertStringContainsString(e(UiLang::get('literary.section.'.$key)), $html, "title of {$key}");
        }
        foreach (['Render president word', 'Render outlook', 'Board Person', 'Render Activity', 'Render Donations', $player->fresh()->fullname, 'Football', 'Term RenderTest'] as $value) {
            $this->assertStringContainsString(e($value), $html, $value);
        }
    }
}
