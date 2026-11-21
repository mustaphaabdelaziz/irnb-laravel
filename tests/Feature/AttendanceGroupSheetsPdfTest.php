<?php

namespace Tests\Feature;

use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Player;
use App\Models\TrainingGroup;
use App\Models\TrainingSession;
use App\Models\User;
use App\Services\Attendance\GroupMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\Support\BranchAttendanceFixtures;
use Tests\TestCase;

class AttendanceGroupSheetsPdfTest extends TestCase
{
    use AttendanceFixtures, BranchAttendanceFixtures, RefreshDatabase;

    private Branch $swim;

    private Category $minime;

    private TrainingGroup $dolphins;

    private TrainingGroup $sharks;

    private Player $a;

    private Player $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->swim = Branch::create(['name' => 'Swim']);
        $this->minime = Category::create(['name' => 'Minime', 'branch_id' => $this->swim->id]);
        $this->dolphins = TrainingGroup::create(['branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'name' => 'Dauphins']);
        $this->sharks = TrainingGroup::create(['branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'name' => 'Requins']);
        $this->a = $this->swimmer();
        $this->b = $this->swimmer();
        $membership = app(GroupMembership::class);
        $membership->add($this->dolphins, $this->a->id, null);
        $membership->add($this->sharks, $this->b->id, null);
    }

    private function swimmer(): Player
    {
        $player = $this->player($this->minime);
        $this->swim->players()->attach($player->id);

        return $player;
    }

    private function sessionOf(?TrainingGroup $group, string $date, string $start): TrainingSession
    {
        return TrainingSession::create([
            'branch_id' => $this->swim->id, 'category_id' => $this->minime->id, 'group_id' => $group?->id, 'date' => $date,
            'start_time' => $start, 'end_time' => '23:00', 'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ]);
    }

    /** @return \ArrayObject<string, string> what the month sheet sent to the PDF service, in French */
    private function monthSheet(array $query = []): \ArrayObject
    {
        $seen = $this->spyPdf();
        $this->actingAs(User::factory()->admin()->create(['preferred_lng' => 'fr']))
            ->get(route('attendance.sheets.month', $query + ['category_id' => $this->minime->id, 'month' => '2026-11', 'branch_id' => $this->swim->id]))
            ->assertOk();

        return $seen;
    }

    #[Test]
    public function the_month_sheet_prints_the_first_group_by_default_and_names_it(): void
    {
        $this->sessionOf($this->dolphins, '2026-11-21', '09:30');
        $this->sessionOf($this->sharks, '2026-11-21', '11:00');

        $seen = $this->monthSheet();

        $this->assertStringContainsString('Groupe: <b>Dauphins</b>', $seen['html']);
        $this->assertStringContainsString($this->a->fullname, $seen['html']);
        $this->assertStringNotContainsString($this->b->fullname, $seen['html']);
        $this->assertStringNotContainsString('11:00', $seen['html']);
        $this->assertSame("attendance-sheet-{$this->minime->id}-2026-11-g{$this->dolphins->id}.pdf", $seen['filename']);
    }

    #[Test]
    public function the_month_sheet_prints_the_group_asked_for(): void
    {
        $this->sessionOf($this->dolphins, '2026-11-21', '09:30');
        $this->sessionOf($this->sharks, '2026-11-21', '11:00');

        $seen = $this->monthSheet(['group_id' => $this->sharks->id]);

        $this->assertStringContainsString('Groupe: <b>Requins</b>', $seen['html']);
        $this->assertStringContainsString($this->b->fullname, $seen['html']);
        $this->assertStringNotContainsString($this->a->fullname, $seen['html']);
        $this->assertStringNotContainsString('Dauphins', $seen['html']);
    }

    #[Test]
    public function the_whole_category_entry_prints_the_sessions_with_no_group(): void
    {
        $this->sessionOf($this->dolphins, '2026-11-21', '09:30');
        $this->sessionOf(null, '2026-11-24', '17:00');

        $seen = $this->monthSheet(['group_id' => 0]);

        $this->assertStringContainsString('Groupe: <b>Toute la catégorie</b>', $seen['html']);
        $this->assertStringContainsString('17:00', $seen['html']);
        $this->assertStringNotContainsString('09:30', $seen['html']);
        $this->assertStringContainsString($this->a->fullname, $seen['html']);
        $this->assertStringContainsString($this->b->fullname, $seen['html']);
        $this->assertSame("attendance-sheet-{$this->minime->id}-2026-11-g0.pdf", $seen['filename']);
    }

    #[Test]
    public function a_category_without_groups_prints_as_before(): void
    {
        $u15 = Category::create(['name' => 'U15', 'branch_id' => $this->swim->id]);
        TrainingSession::create([
            'branch_id' => $this->swim->id, 'category_id' => $u15->id, 'date' => '2026-11-21', 'start_time' => '09:30',
            'end_time' => '11:00', 'kind' => SessionKind::Regular, 'state' => SessionState::Planned,
        ]);
        $seen = $this->spyPdf();

        $this->actingAs(User::factory()->admin()->create(['preferred_lng' => 'fr']))
            ->get(route('attendance.sheets.month', ['category_id' => $u15->id, 'month' => '2026-11', 'branch_id' => $this->swim->id]))
            ->assertOk();

        $this->assertStringNotContainsString('Groupe:', $seen['html']);
        $this->assertSame("attendance-sheet-{$u15->id}-2026-11.pdf", $seen['filename']);
    }

    #[Test]
    public function the_session_sheet_names_the_group_beside_the_category(): void
    {
        $grouped = $this->sessionOf($this->dolphins, '2026-11-21', '09:30');
        $seen = $this->spyPdf();

        $this->actingAs(User::factory()->admin()->create(['preferred_lng' => 'fr']))
            ->get(route('attendance.sheets.session', $grouped))->assertOk();

        $this->assertStringContainsString('<b>Minime · Dauphins</b>', $seen['html']);
        $this->assertStringContainsString($this->a->fullname, $seen['html']);
        $this->assertStringNotContainsString($this->b->fullname, $seen['html']);
    }

    #[Test]
    public function a_session_with_no_group_prints_its_category_alone(): void
    {
        $plain = $this->sessionOf(null, '2026-11-24', '17:00');
        $seen = $this->spyPdf();

        $this->actingAs(User::factory()->admin()->create(['preferred_lng' => 'fr']))
            ->get(route('attendance.sheets.session', $plain))->assertOk();

        $this->assertStringContainsString('<b>Minime</b>', $seen['html']);
        $this->assertStringNotContainsString('Dauphins', $seen['html']);
    }
}
