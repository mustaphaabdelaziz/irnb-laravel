<?php

namespace Tests\Feature\Help;

use App\Models\ActivityLog;
use App\Models\BoardCommittee;
use App\Models\BoardMeeting;
use App\Models\BoardMember;
use App\Models\BoardTask;
use App\Models\BoardTerm;
use App\Models\Player;
use Carbon\CarbonImmutable;
use Database\Seeders\HelpDemoBoardSeeder as Demo;
use Database\Seeders\HelpDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HelpDemoBoardSeederTest extends TestCase
{
    use RefreshDatabase;

    private ?int $migrationTermId = null;

    private function seedOn(string $day): void
    {
        $this->travelTo($day);
        // The migration's term, when this database has one, keeps its id.
        $this->migrationTermId = BoardTerm::where('is_current', true)->value('id');
        $this->seed(HelpDemoSeeder::class);
    }

    private function member(BoardTerm $term, string $fullname): ?BoardMember
    {
        return BoardMember::where('board_term_id', $term->id)->with('player')->get()
            ->first(fn ($m) => $m->player->firstname.' '.$m->player->lastname === $fullname);
    }

    private function seat(BoardTerm $term, string $committee, string $position): array
    {
        return BoardCommittee::where('name', $committee)->firstOrFail()->members()
            ->where('board_term_id', $term->id)->wherePivot('position', $position)->get()
            ->map(fn ($m) => $m->player->firstname.' '.$m->player->lastname)->all();
    }

    #[Test]
    public function the_demo_board_matches_the_articles(): void
    {
        $this->seedOn('2026-11-18 10:00:00');

        $archived = Player::where('archived', true)->get();
        $this->assertCount(9, $archived);
        $this->assertSame(22, Player::where('archived', false)->count());
        $this->assertEqualsCanonicalizing([
            Demo::PRESIDENT, Demo::SALIM, Demo::FATIMA, Demo::NABIL, Demo::RACHID,
            Demo::LEILA, Demo::KAMEL, Demo::MOURAD, Demo::MOUSTAFA,
        ], $archived->map(fn ($p) => $p->firstname.' '.$p->lastname)->all());
        $this->assertSame(0, $archived->filter(fn ($p) => $p->registrations()->exists())->count());

        $terms = BoardTerm::orderBy('start_date')->get();
        $this->assertCount(3, $terms);
        [$prev, $curr, $next] = $terms->all();
        $this->assertSame('العهدة 2021–2025', $prev->name);
        $this->assertSame('العهدة 2025–2029', $curr->name);
        $this->assertSame('العهدة 2029–2033', $next->name);
        $this->assertSame('2029-03-01', $next->start_date->toDateString());
        $this->assertNull($next->end_date);
        $this->assertSame([false, true, false], $terms->pluck('is_current')->all());
        if ($this->migrationTermId) {
            $this->assertSame($this->migrationTermId, $curr->id);
        }
        $this->assertSame($curr->id, BoardTerm::current()->id);
        $this->assertSame($prev->id, BoardTerm::current()->previous()->id);
        $this->assertSame($curr->id, $next->previous()->id);
        $this->assertSame(0, $next->members()->count());

        $this->assertSame(7, $curr->members()->where('status', 'active')->count());
        $this->assertSame(1, $curr->members()->where('status', 'former')->count());
        $this->assertSame('former', $this->member($curr, Demo::MOURAD)->status);
        $this->assertSame('vice_president', $this->member($curr, Demo::SALIM)->role);
        $this->assertNull($this->member($curr, Demo::MOUSTAFA));
        $this->assertSame('vice_president', $this->member($prev, Demo::MOUSTAFA)->role);

        $this->assertSame([Demo::SALIM], $this->seat($curr, Demo::TECHNICAL_COMMITTEE, 'head'));
        $this->assertSame([Demo::KAMEL], $this->seat($curr, Demo::TECHNICAL_COMMITTEE, 'member'));
        $this->assertSame([Demo::NABIL], $this->seat($curr, Demo::FINANCE_COMMITTEE, 'head'));
        $this->assertSame([Demo::RACHID], $this->seat($curr, Demo::FINANCE_COMMITTEE, 'deputy'));
        $this->assertSame([Demo::NABIL], $this->seat($prev, Demo::FINANCE_COMMITTEE, 'head'));
        $media = BoardCommittee::where('name', Demo::MEDIA_COMMITTEE)->firstOrFail();
        $this->assertSame(0, $media->members()->count());
        $this->assertSame([1, 2, 3], BoardCommittee::orderBy('sort_order')->pluck('sort_order')->all());
    }

    #[Test]
    public function meetings_attendance_and_tasks(): void
    {
        $this->seedOn('2026-11-18 10:00:00');
        $today = CarbonImmutable::today();

        $this->assertSame(4, BoardMeeting::count());
        $held = BoardMeeting::where('title', Demo::REGULAR_MEETING)->firstOrFail();
        $this->assertSame('held', $held->status);
        $this->assertTrue($held->meeting_date->equalTo($today->subDays(21)->setTime(18, 0)));
        $this->assertCount(3, $held->agenda);
        $this->assertCount(2, $held->decisions);
        $this->assertSame(6, $held->attendances()->where('status', 'present')->count());
        $this->assertSame(1, $held->attendances()->where('status', 'excused')->count());
        $this->assertSame(0, $held->attendances()->whereNull('member_name')->orWhereNull('member_role')->count());

        $assembly = BoardMeeting::where('title', Demo::ASSEMBLY_MEETING)->firstOrFail();
        $this->assertSame(['scheduled', 'general_assembly'], [$assembly->status, $assembly->type]);
        $this->assertTrue($assembly->meeting_date->equalTo($today->addDays(7)->setTime(18, 0)));

        $cancelled = BoardMeeting::where('title', Demo::CANCELLED_MEETING)->firstOrFail();
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame(Demo::CANCEL_REASON, $cancelled->cancel_reason);
        $this->assertNotNull($cancelled->cancelled_by_user_id);

        $old = BoardMeeting::where('title', Demo::OLD_MEETING)->firstOrFail();
        $this->assertSame('held', $old->status);
        $this->assertSame('2025-02-10', $old->meeting_date->toDateString());
        $this->assertSame(6, $old->attendances()->count());
        $this->assertSame([], $old->attendances()->whereNull('member_name')->pluck('id')->all());

        $this->assertSame(3, BoardTask::count());
        $this->assertSame(1, BoardTask::whereDate('due_date', '<', $today)->where('status', '!=', 'completed')->count());
        $grant = BoardTask::where('title', Demo::GRANT_TASK)->firstOrFail();
        $this->assertSame([40, 'high', $held->id], [$grant->progress, $grant->priority, $grant->board_meeting_id]);

        // Models are written directly: no activity entries about the board.
        $this->assertSame(0, ActivityLog::where('subject_type', 'like', '%Board%')->count());
    }

    #[Test]
    public function seeding_just_after_a_term_boundary_keeps_the_terms_in_order(): void
    {
        $this->seedOn('2027-03-02 09:00:00');

        $terms = BoardTerm::orderBy('start_date')->get();
        $this->assertCount(3, $terms);
        $this->assertSame([false, true, false], $terms->pluck('is_current')->all());
        $day = CarbonImmutable::today();
        $current = BoardTerm::current();
        $this->assertTrue($current->start_date->lte($day) && $current->end_date->gte($day));
        $this->assertSame($terms[0]->id, $current->previous()->id);
        $this->assertSame($current->id, $terms[2]->previous()->id);
        $this->assertSame(22, Player::where('archived', false)->count());
    }
}
