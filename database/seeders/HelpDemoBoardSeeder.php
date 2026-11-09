<?php

namespace Database\Seeders;

use App\Models\BoardCommittee;
use App\Models\BoardMeeting;
use App\Models\BoardMember;
use App\Models\BoardTask;
use App\Models\BoardTerm;
use App\Models\MeetingAttendance;
use App\Models\Player;
use App\Models\User;
use App\Services\Player\MembershipNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * The demo board for the Help Center board articles. Called only from the
 * guarded HelpDemoSeeder. Models are written directly: no ActivityRecorder
 * entries, because the users articles count them. Dates hang off the seed day D;
 * Y is the year of D minus 20 months, so the current term always contains D.
 */
class HelpDemoBoardSeeder extends Seeder
{
    public const PRESIDENT = 'عبد المجيد بوخالفة';

    public const SALIM = 'سليم حمادي';

    public const FATIMA = 'فاطمة الزهراء بن صالح';

    public const NABIL = 'نبيل عثماني';

    public const RACHID = 'رشيد مقران';

    public const LEILA = 'ليلى بوعزة';

    public const KAMEL = 'كمال درارجة';

    public const MOURAD = 'مراد زيتوني';

    public const MOUSTAFA = 'مصطفى قريشي';

    public const FINANCE_COMMITTEE = 'اللجنة المالية';

    public const TECHNICAL_COMMITTEE = 'اللجنة التقنية';

    public const MEDIA_COMMITTEE = 'لجنة الإعلام';

    public const REGULAR_MEETING = 'اجتماع المكتب العادي';

    public const ASSEMBLY_MEETING = 'اجتماع تحضير الجمعية العامة';

    public const CANCELLED_MEETING = 'لقاء مع الممولين';

    public const OLD_MEETING = 'اجتماع نهاية العهدة';

    public const CANCEL_REASON = 'تعذّر حضور أغلب الأعضاء';

    public const GRANT_TASK = 'تحضير ملف منحة البلدية';

    public const LEASE_TASK = 'تجديد عقد كراء القاعة';

    public const REPORT_TASK = 'إعداد التقرير الأدبي';

    /** [first name, last name, gender, birth year, phone] */
    private const PLAYERS = [
        'president' => ['عبد المجيد', 'بوخالفة', 'Male', 1968, '0661234501'],
        'salim' => ['سليم', 'حمادي', 'Male', 1975, '0661234502'],
        'fatima' => ['فاطمة الزهراء', 'بن صالح', 'Female', 1980, '0661234503'],
        'nabil' => ['نبيل', 'عثماني', 'Male', 1972, '0661234504'],
        'rachid' => ['رشيد', 'مقران', 'Male', 1978, '0661234505'],
        'leila' => ['ليلى', 'بوعزة', 'Female', 1985, '0661234506'],
        'kamel' => ['كمال', 'درارجة', 'Male', 1983, '0661234507'],
        'mourad' => ['مراد', 'زيتوني', 'Male', 1970, '0661234508'],
        'moustafa' => ['مصطفى', 'قريشي', 'Male', 1960, '0661234509'],
    ];

    private CarbonImmutable $today;

    private User $demo;

    public function run(): void
    {
        HelpDemoSeeder::assertThrowawayDatabase();

        $this->today = CarbonImmutable::today();
        $this->demo = User::where('username', HelpDemoSeeder::USERNAME)->firstOrFail();
        $y = $this->today->subMonths(20)->year;

        // The migration inserts one current term; keep its id when it is there.
        $current = BoardTerm::where('is_current', true)->first() ?? new BoardTerm;
        $current->fill([
            'name' => 'العهدة '.$y.'–'.($y + 4),
            'start_date' => "$y-03-01",
            'end_date' => ($y + 4).'-02-28',
            'is_current' => true,
        ])->save();
        // Any other term would break "exactly three".
        BoardTerm::whereKeyNot($current->id)->delete();

        $previous = BoardTerm::create([
            'name' => 'العهدة '.($y - 4).'–'.$y,
            'start_date' => ($y - 4).'-03-01',
            'end_date' => "$y-02-28",
            'is_current' => false,
        ]);
        // The next term stays empty: its copy button shows.
        BoardTerm::create([
            'name' => 'العهدة '.($y + 4).'–'.($y + 8),
            'start_date' => ($y + 4).'-03-01',
            'end_date' => null,
            'is_current' => false,
        ]);

        $finance = BoardCommittee::create(['name' => self::FINANCE_COMMITTEE, 'sort_order' => 1]);
        $technical = BoardCommittee::create(['name' => self::TECHNICAL_COMMITTEE, 'sort_order' => 2]);
        BoardCommittee::create(['name' => self::MEDIA_COMMITTEE, 'sort_order' => 3]);

        $players = [];
        $seq = 1;
        foreach (self::PLAYERS as $key => [$first, $last, $gender, $birthYear, $phone]) {
            $players[$key] = Player::create([
                'membership_id' => MembershipNumber::format(2015, $seq++),
                'firstname' => $first,
                'lastname' => $last,
                'gender' => $gender,
                'birthdate' => $birthYear.'-06-15',
                'join_year' => 2015,
                'phones' => [$phone],
                'archived' => true,
                'outstanding_debt' => 0,
            ]);
        }

        // [player key, role, status, committee, position]
        $prevRows = [
            ['president', 'president', 'active', null, null],
            ['moustafa', 'vice_president', 'active', null, null],
            ['salim', 'member', 'active', null, null],
            ['fatima', 'secretary', 'active', null, null],
            ['nabil', 'treasurer', 'active', $finance, 'head'],
            ['rachid', 'member', 'active', null, null],
            ['mourad', 'member', 'former', null, null],
        ];
        $currRows = [
            ['president', 'president', 'active', null, null],
            ['salim', 'vice_president', 'active', $technical, 'head'],
            ['fatima', 'secretary', 'active', null, null],
            ['nabil', 'treasurer', 'active', $finance, 'head'],
            ['rachid', 'member', 'active', $finance, 'deputy'],
            ['leila', 'member', 'active', null, null],
            ['kamel', 'member', 'active', $technical, 'member'],
            ['mourad', 'member', 'former', null, null],
        ];

        $prevMembers = $this->members($previous, $prevRows, $players);
        $currMembers = $this->members($current, $currRows, $players);

        $this->oldMeeting($y, $prevMembers);
        $held = $this->regularMeeting($currMembers);
        $this->upcomingMeetings();

        BoardTask::create([
            'title' => self::GRANT_TASK,
            'board_member_id' => $currMembers['nabil']->id,
            'board_meeting_id' => $held->id,
            'due_date' => $this->today->addDays(10)->toDateString(),
            'status' => 'in_progress',
            'progress' => 40,
            'priority' => 'high',
            'created_by_user_id' => $this->demo->id,
        ]);
        BoardTask::create([
            'title' => self::LEASE_TASK,
            'board_member_id' => $currMembers['salim']->id,
            'due_date' => $this->today->subDays(3)->toDateString(),
            'status' => 'not_started',
            'progress' => 0,
            'priority' => 'medium',
            'created_by_user_id' => $this->demo->id,
        ]);
        BoardTask::create([
            'title' => self::REPORT_TASK,
            'board_member_id' => $currMembers['fatima']->id,
            'due_date' => $this->today->addDays(20)->toDateString(),
            'status' => 'in_progress',
            'progress' => 20,
            'priority' => 'medium',
            'created_by_user_id' => $this->demo->id,
        ]);
    }

    /** @return array<string, BoardMember> keyed by player key */
    private function members(BoardTerm $term, array $rows, array $players): array
    {
        $members = [];
        foreach ($rows as $i => [$key, $role, $status, $committee, $position]) {
            $player = $players[$key];
            $members[$key] = BoardMember::create([
                'player_id' => $player->id,
                'board_term_id' => $term->id,
                'name' => $player->fullname,
                'role' => $role,
                'status' => $status,
                'term_start' => $term->start_date,
                'term_end' => $term->end_date,
                'sort_order' => $i + 1,
            ]);
            if ($committee) {
                $members[$key]->committees()->attach($committee->id, ['position' => $position]);
            }
        }

        return $members;
    }

    private function attend(BoardMeeting $meeting, BoardMember $member, string $status): void
    {
        MeetingAttendance::create([
            'board_meeting_id' => $meeting->id,
            'board_member_id' => $member->id,
            'member_name' => $member->name,
            'member_role' => $member->role,
            'status' => $status,
        ]);
    }

    private function oldMeeting(int $y, array $prevMembers): void
    {
        $meeting = BoardMeeting::create([
            'title' => self::OLD_MEETING,
            'type' => 'ordinary',
            'meeting_date' => CarbonImmutable::parse("$y-02-10 18:00:00"),
            'location' => 'مقر النادي',
            'status' => 'held',
            'created_by_user_id' => $this->demo->id,
        ]);
        foreach ($prevMembers as $member) {
            if ($member->status === 'active') {
                $this->attend($meeting, $member, 'present');
            }
        }
    }

    private function regularMeeting(array $members): BoardMeeting
    {
        $meeting = BoardMeeting::create([
            'title' => self::REGULAR_MEETING,
            'type' => 'ordinary',
            'meeting_date' => $this->today->subDays(21)->setTime(18, 0),
            'location' => 'مقر النادي',
            'agenda' => ['مراجعة ميزانية الموسم', 'تحضير البطولة الشتوية', 'متفرقات'],
            'status' => 'held',
            'minutes' => 'ناقش المكتب ميزانية الموسم وصادق عليها. تقرر تحضير ملف منحة البلدية.',
            'decisions' => ['المصادقة على ميزانية الموسم', 'تكليف أمين المال بملف منحة البلدية'],
            'created_by_user_id' => $this->demo->id,
        ]);
        foreach ($members as $key => $member) {
            if ($member->status === 'active') {
                $this->attend($meeting, $member, $key === 'rachid' ? 'excused' : 'present');
            }
        }

        return $meeting;
    }

    private function upcomingMeetings(): void
    {
        BoardMeeting::create([
            'title' => self::ASSEMBLY_MEETING,
            'type' => 'general_assembly',
            'meeting_date' => $this->today->addDays(7)->setTime(18, 0),
            'location' => 'قاعة البلدية',
            'agenda' => ['التقرير الأدبي', 'التقرير المالي'],
            'status' => 'scheduled',
            'created_by_user_id' => $this->demo->id,
        ]);
        BoardMeeting::create([
            'title' => self::CANCELLED_MEETING,
            'type' => 'extraordinary',
            'meeting_date' => $this->today->subDays(7)->setTime(17, 0),
            'status' => 'cancelled',
            'cancelled_at' => $this->today->subDays(9)->setTime(10, 0),
            'cancelled_by_user_id' => $this->demo->id,
            'cancel_reason' => self::CANCEL_REASON,
            'created_by_user_id' => $this->demo->id,
        ]);
    }
}
