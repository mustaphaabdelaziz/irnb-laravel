<?php

namespace Tests\Feature\LiteraryReport;

use App\Models\BoardCommittee;
use App\Models\BoardMeeting;
use App\Models\BoardMember;
use App\Models\BoardRole;
use App\Models\BoardTerm;
use App\Services\LiteraryReport\Sections\BoardSection;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BoardSectionTest extends TestCase
{
    use RefreshDatabase;

    private function build(): array
    {
        return app(BoardSection::class)->build(CarbonImmutable::parse('2025-09-01'), CarbonImmutable::parse('2026-08-31'));
    }

    #[Test]
    public function it_lists_the_current_term_committees_and_meetings_of_the_period(): void
    {
        BoardRole::query()->delete();
        BoardTerm::query()->delete(); // the migration seeds a current term
        BoardRole::create(['name' => 'president', 'label' => 'الرئيس', 'level' => 'top', 'sort_order' => 1]);
        BoardRole::create(['name' => 'member', 'label' => 'عضو', 'level' => 'member', 'sort_order' => 9]);
        BoardTerm::create(['name' => '2017–2021', 'is_current' => false]);
        $term = BoardTerm::create(['name' => '2021–2025', 'is_current' => true]);
        $member = BoardMember::create(['name' => 'Ali', 'role' => 'member', 'board_term_id' => $term->id, 'status' => 'active', 'sort_order' => 1]);
        $president = BoardMember::create(['name' => 'Omar', 'role' => 'president', 'board_term_id' => $term->id, 'status' => 'active', 'sort_order' => 2]);
        BoardMember::create(['name' => 'Gone', 'role' => 'member', 'board_term_id' => $term->id, 'status' => 'former']);

        $committee = BoardCommittee::create(['name' => 'Finance']);
        $committee->members()->attach($president->id, ['position' => 'head']);
        $committee->members()->attach($member->id, ['position' => 'deputy']);

        BoardMeeting::create(['title' => 'AG', 'type' => 'general_assembly', 'meeting_date' => '2026-06-20 18:00:00', 'status' => 'held', 'decisions' => ['Budget adopted']]);
        BoardMeeting::create(['title' => 'Cancelled', 'type' => 'ordinary', 'meeting_date' => '2026-02-01 18:00:00', 'status' => 'cancelled']);
        BoardMeeting::create(['title' => 'Old', 'type' => 'ordinary', 'meeting_date' => '2025-06-01 18:00:00', 'status' => 'held']);
        BoardMeeting::create(['title' => 'Last day', 'type' => 'ordinary', 'meeting_date' => '2026-08-31 20:00:00', 'status' => 'held']);

        $data = $this->build();

        $this->assertSame('2021–2025', $data['term']);
        $this->assertSame([['name' => 'Omar', 'role' => 'الرئيس'], ['name' => 'Ali', 'role' => 'عضو']], $data['members']);
        $this->assertSame([['name' => 'Finance', 'head' => 'Omar', 'deputies' => ['Ali'], 'members_count' => 2]], $data['committees']);
        $this->assertSame(['AG', 'Last day'], array_column($data['meetings'], 'title'));
        $this->assertSame(['Budget adopted'], $data['meetings'][0]['decisions']);
        $this->assertSame('2026-06-20', $data['meetings'][0]['date']);
    }

    #[Test]
    public function no_term_gives_empty_lists(): void
    {
        BoardTerm::query()->delete(); // the migration seeds a current term
        $data = $this->build();
        $this->assertNull($data['term']);
        $this->assertSame([], $data['members']);
        $this->assertSame([], $data['meetings']);
    }
}
