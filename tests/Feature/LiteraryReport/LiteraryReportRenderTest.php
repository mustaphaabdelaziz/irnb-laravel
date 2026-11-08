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
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

/** Renders the PDF HTML with one row per section, so the partials run against real data shapes. */
class LiteraryReportRenderTest extends TestCase
{
    use CompetitionFixtures;
    use RefreshDatabase;

    private int $playerSeq = 0;

    private ?string $photoDir = null;

    protected function tearDown(): void
    {
        if ($this->photoDir) {
            File::deleteDirectory(storage_path('app/public/'.$this->photoDir));
        }

        parent::tearDown();
    }

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
        $signer = BoardMember::create(['name' => 'Board Person', 'role' => 'member', 'board_term_id' => $term->id, 'status' => 'active']);

        $session = TrainingSession::create([
            'category_id' => $u15->id, 'branch_id' => $branch->id, 'date' => '2026-03-10', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ]);
        Attendance::create(['training_session_id' => $session->id, 'player_id' => $player->id, 'status' => 'present', 'category_id' => $u15->id]);

        $team = $this->team($u15, ['name' => 'Team Render']);
        $game = $this->game($team, ['date' => '2026-02-01', 'status' => GameStatus::Played, 'our_score' => 1, 'their_score' => 0]);
        GameEvent::create(['game_id' => $game->id, 'type' => 'goal', 'subtype' => 'normal', 'minute' => 10, 'player_id' => $player->id]);

        $dir = $this->photoDir = 'literary-render-'.uniqid();
        File::ensureDirectoryExists(storage_path('app/public/'.$dir));
        $activity = Activity::create([
            'title' => 'Render Activity', 'activity_type_id' => ActivityType::where('name', 'تنظيمات النادي')->value('id'), 'starts_on' => '2026-05-05',
            'organizer' => 'Render Organizer', 'result' => 'Render Result', 'date_label' => 'Render Date Label',
        ]);
        File::put(storage_path("app/public/{$dir}/p.jpg"), 'jpeg-bytes');
        $activity->photos()->create(['path' => "{$dir}/p.jpg", 'thumb_path' => "{$dir}/p.jpg", 'caption' => 'Render Caption']);

        $category = FinanceCategory::create(['type' => 'income', 'name' => 'Render Donations']);
        Transaction::create([
            'title' => 'T', 'amount' => 500, 'transaction_date' => '2026-04-01', 'transaction_type' => 'income',
            'category' => 'donation', 'payment_method' => 'cash', 'status' => 'Paid', 'finance_category_id' => $category->id,
        ]);

        $report = LiteraryReport::create([
            'title' => 'Render Report', 'period_from' => '2025-09-01', 'period_to' => '2026-08-31',
            'sections' => LiteraryReport::SECTIONS, 'president_word' => 'Render president word', 'conclusion' => 'Render outlook',
            'note' => 'Render Note', 'signer_1_board_member_id' => $signer->id,
        ]);

        $html = view('pdf.literary-report', [
            'club' => ClubHeader::data(),
            'report' => $report,
            'sections' => app(LiteraryReportBuilder::class)->build($report, $admin),
            'rtl' => true,
        ])->render();

        foreach (LiteraryReport::SECTIONS as $key) {
            if (in_array($key, ['cover', 'signatures'], true)) {
                continue; // the cover has no section heading
            }
            $this->assertStringContainsString(e(UiLang::get('literary.section.'.$key)), $html, "title of {$key}");
        }
        foreach (['Render president word', 'Render outlook', 'Board Person', 'Render Activity', 'Render Donations', $player->fresh()->fullname, 'Football', 'Term RenderTest', 'Render Organizer', 'Render Result', 'Render Date Label', 'Render Note', 'Render Caption', 'تنظيمات النادي'] as $value) {
            $this->assertStringContainsString(e($value), $html, $value);
        }
        $this->assertStringContainsString(e(trim(str_replace('{role}', '', UiLang::get('literary.pdf.signature')))), $html);
    }

    private function renderHtml(LiteraryReport $report): string
    {
        return view('pdf.literary-report', [
            'club' => ClubHeader::data(),
            'report' => $report,
            'sections' => app(LiteraryReportBuilder::class)->build($report, $this->admin()),
            'rtl' => true,
        ])->render();
    }

    #[Test]
    public function the_details_option_toggles_the_per_type_report_texts(): void
    {
        Activity::create([
            'title' => 'Detail Activity', 'activity_type_id' => ActivityType::where('name', 'تنظيمات النادي')->value('id'),
            'starts_on' => '2026-05-05', 'report' => 'Render Report Text',
        ]);
        $base = ['title' => 'Details', 'period_from' => '2025-09-01', 'period_to' => '2026-08-31', 'sections' => ['activities']];

        $off = $this->renderHtml(LiteraryReport::create($base + ['include_details' => false]));
        $this->assertStringNotContainsString(e(UiLang::get('literary.pdf.by_type')), $off);
        $this->assertStringNotContainsString('Render Report Text', $off);

        $on = $this->renderHtml(LiteraryReport::create($base + ['include_details' => true]));
        $this->assertStringContainsString(e(UiLang::get('literary.pdf.by_type')), $on);
        $this->assertStringContainsString('Render Report Text', $on);
    }

    #[Test]
    public function no_page_break_sits_directly_before_the_signatures(): void
    {
        $term = BoardTerm::create(['name' => 'Term Break', 'is_current' => true]);
        $signer = BoardMember::create(['name' => 'Break Person', 'role' => 'member', 'board_term_id' => $term->id, 'status' => 'active']);
        $html = $this->renderHtml(LiteraryReport::create([
            'title' => 'Break', 'period_from' => '2025-09-01', 'period_to' => '2026-08-31',
            'sections' => ['cover', 'intro', 'conclusion', 'signatures'], 'president_word' => 'Break word', 'conclusion' => 'Break outlook',
            'signer_1_board_member_id' => $signer->id,
        ]));

        $this->assertStringContainsString('<pagebreak', $html, 'sections after the first are separated');
        $afterWord = substr($html, strrpos($html, 'Break outlook'));
        $this->assertStringNotContainsString('<pagebreak', $afterWord);
        $this->assertStringContainsString('Break Person', $afterWord);
    }
}
