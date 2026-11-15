<?php

namespace Tests\Feature\Help;

use App\Models\Activity;
use App\Models\ActivityFile;
use App\Models\ActivityLog;
use App\Models\ActivityPhoto;
use App\Models\ActivityType;
use App\Models\BoardMember;
use App\Models\LiteraryReport;
use App\Models\Player;
use App\Models\User;
use App\Services\LiteraryReport\ActivityChapters;
use App\Support\Season;
use Carbon\CarbonImmutable;
use Database\Seeders\HelpDemoBoardSeeder as Board;
use Database\Seeders\HelpDemoSeeder;
use Database\Seeders\HelpDemoSocialSeeder as Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HelpDemoSocialSeederTest extends TestCase
{
    use RefreshDatabase;

    private ?string $tmpRoot = null;

    protected function tearDown(): void
    {
        if ($this->tmpRoot !== null) {
            File::deleteDirectory($this->tmpRoot);
        }
        Storage::forgetDisk(['public', 'local']);
        parent::tearDown();
    }

    private function seedOn(string $day): void
    {
        $this->travelTo($day);
        $this->seed(HelpDemoSeeder::class);
    }

    private function activity(string $title): Activity
    {
        return Activity::where('title', $title)->firstOrFail();
    }

    private function person(?BoardMember $member): ?string
    {
        return $member ? $member->player->firstname.' '.$member->player->lastname : null;
    }

    /** @return list<string> */
    private function names(Activity $a): array
    {
        return $a->players()->get()->map(fn (Player $p) => $p->firstname.' '.$p->lastname)->sort()->values()->all();
    }

    #[Test]
    public function the_demo_activities_and_report_match_the_articles(): void
    {
        $this->seedOn('2026-11-18 10:00:00');
        $season = Season::current();
        $d = CarbonImmutable::today();
        $this->assertSame(2026, $season->startYear);

        $this->assertSame(7, Activity::count());
        $this->assertEqualsCanonicalizing(
            [Demo::PARENTS, Demo::TOURNAMENT, Demo::BEACH, Demo::VISIT, Demo::FIRST_AID, Demo::SWIM_CHAMP, Demo::SIGNUPS],
            Activity::pluck('title')->all(),
        );

        $day = fn (int $n) => max($d->subDays($n), $season->start())->toDateString();
        $expect = [
            Demo::PARENTS => ['تنظيمات النادي', $day(50), null, null],
            Demo::TOURNAMENT => ['المشاركات الرياضية', $day(35), $day(34), null],
            Demo::BEACH => ['الحملات التطوعية', $day(28), null, null],
            Demo::VISIT => ['الحملات التطوعية', $day(45), $day(14), Demo::DATE_LABEL],
            Demo::FIRST_AID => ['التكوينات', $day(21), null, null],
            Demo::SWIM_CHAMP => ['المشاركات الرياضية', $day(14), null, null],
            Demo::SIGNUPS => ['إعلانات النادي', $day(55), null, null],
        ];
        foreach ($expect as $title => [$type, $from, $to, $label]) {
            $a = $this->activity($title);
            $this->assertSame($type, $a->type->name, $title);
            $this->assertSame($from, $a->starts_on->toDateString(), $title);
            $this->assertSame($to, $a->ends_on?->toDateString(), $title);
            $this->assertSame($label, $a->date_label, $title);
            $this->assertGreaterThanOrEqual($season->start()->toDateString(), $a->starts_on->toDateString());
            $this->assertLessThanOrEqual($d->toDateString(), $a->starts_on->toDateString());
        }

        // Places, partners, organizer, result, cost, counts.
        $t = $this->activity(Demo::TOURNAMENT);
        $this->assertSame(['ملعب البلدية', 'الرابطة الولائية لكرة القدم', 'وصيف الدورة'], [$t->location, $t->organizer, $t->result]);
        $this->assertEquals(12000, $t->cost);
        $this->assertSame('Football', $t->branch->name);
        $b = $this->activity(Demo::BEACH);
        $this->assertSame(['شاطئ المدينة', 'جمعية البيئة المحلية', 150, 12], [$b->location, $b->partners, $b->beneficiaries_count, $b->external_volunteers_count]);
        $this->assertEquals(8500, $b->cost);
        $this->assertSame('جمعية البيئة المحلية', $b->partners);
        $this->assertSame(['مقر النادي', 'مركز التكوين المحلي'], [$this->activity(Demo::FIRST_AID)->location, $this->activity(Demo::FIRST_AID)->partners]);
        $this->assertEquals(5000, $this->activity(Demo::FIRST_AID)->cost);
        $this->assertSame('المرتبة الثالثة', $this->activity(Demo::SWIM_CHAMP)->result);
        $this->assertSame('الرابطة الولائية للسباحة', $this->activity(Demo::SWIM_CHAMP)->organizer);

        // Reports: 1-3 and 5 have one, 4, 6, 7 none.
        foreach ([Demo::PARENTS, Demo::TOURNAMENT, Demo::BEACH, Demo::FIRST_AID] as $title) {
            $this->assertNotEmpty($this->activity($title)->report, $title);
        }
        foreach ([Demo::VISIT, Demo::SWIM_CHAMP, Demo::SIGNUPS] as $title) {
            $this->assertEmpty($this->activity($title)->report, $title);
        }
        $this->assertSame(0, Activity::whereNull('description')->count());

        // Links.
        $responsible = fn (string $title) => $this->person($this->activity($title)->responsible()->with('player')->first());
        $this->assertSame(Board::PRESIDENT, $responsible(Demo::PARENTS));
        $this->assertSame(Board::SALIM, $responsible(Demo::TOURNAMENT));
        $this->assertSame(Board::LEILA, $responsible(Demo::BEACH));
        $this->assertSame(Board::FATIMA, $responsible(Demo::VISIT));
        $this->assertSame(Board::KAMEL, $responsible(Demo::FIRST_AID));
        $this->assertSame(Board::SALIM, $responsible(Demo::SWIM_CHAMP));
        $this->assertSame(Board::FATIMA, $responsible(Demo::SIGNUPS));

        $members = fn (string $title) => $this->activity($title)->boardMembers()->with('player')->get()->map(fn ($m) => $this->person($m))->sort()->values()->all();
        $this->assertEquals(collect([Board::PRESIDENT, Board::FATIMA, Board::NABIL])->sort()->values()->all(), $members(Demo::PARENTS));
        $this->assertSame([Board::SALIM], $members(Demo::TOURNAMENT));
        $this->assertEquals(collect([Board::LEILA, Board::KAMEL])->sort()->values()->all(), $members(Demo::BEACH));
        $this->assertSame([Board::FATIMA], $members(Demo::VISIT));
        $this->assertEquals(collect([Board::KAMEL, Board::NABIL])->sort()->values()->all(), $members(Demo::FIRST_AID));
        $this->assertSame([], $members(Demo::SWIM_CHAMP));
        $this->assertSame([], $members(Demo::SIGNUPS));
        // Board members of the current term only.
        $this->assertSame(
            [true],
            BoardMember::whereIn('id', \DB::table('activity_board_member')->pluck('board_member_id'))->with('term')->get()
                ->map(fn ($m) => (bool) $m->term->is_current)->unique()->values()->all(),
        );

        $players = fn (string $title) => $this->names($this->activity($title));
        $this->assertEquals(collect(['أحمد بن علي', 'ياسين بوزيد', 'زكرياء عمراني'])->sort()->values()->all(), $players(Demo::TOURNAMENT));
        $this->assertEquals(collect(['سارة بن يوسف', 'إيناس حداد', 'يوسف بوشامة', 'لينا قادري', 'شيماء مزياني'])->sort()->values()->all(), $players(Demo::BEACH));
        $this->assertEquals(collect(['أمين قاسمي', 'نور شعبان'])->sort()->values()->all(), $players(Demo::VISIT));
        $this->assertEquals(collect(['سارة بن يوسف', 'إيناس حداد', 'لينا قادري'])->sort()->values()->all(), $players(Demo::SWIM_CHAMP));
        foreach ([Demo::PARENTS, Demo::FIRST_AID, Demo::SIGNUPS] as $title) {
            $this->assertSame([], $players($title), $title);
        }

        $cats = fn (string $title) => $this->activity($title)->categories()->pluck('name')->all();
        $this->assertSame(['أقل من 14 سنة'], $cats(Demo::TOURNAMENT));
        $this->assertSame(['أصاغر'], $cats(Demo::BEACH));
        $this->assertSame(['أصاغر'], $cats(Demo::SWIM_CHAMP));
        $this->assertSame([], $cats(Demo::PARENTS));
        $this->assertSame('Swimming', $this->activity(Demo::SWIM_CHAMP)->branch->name);
        $this->assertNull($this->activity(Demo::VISIT)->branch_id);

        // No media off the throwaway storage.
        $this->assertSame(0, ActivityPhoto::count());
        $this->assertSame(0, ActivityFile::count());

        // The list's totals.
        $this->actingAs(User::where('username', HelpDemoSeeder::USERNAME)->firstOrFail())
            ->get(route('activities.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.count', 7)
                ->where('stats.beneficiaries', 235)
                ->where('stats.participants', 38));

        // One report over the current season.
        $this->assertSame(1, LiteraryReport::count());
        $report = LiteraryReport::firstOrFail();
        $this->assertSame('التقرير الأدبي للموسم 2026/2027', $report->title);
        $this->assertSame(Demo::reportTitle(), $report->title);
        $this->assertSame($season->start()->toDateString(), $report->period_from->toDateString());
        $this->assertSame($season->end()->toDateString(), $report->period_to->toDateString());
        $this->assertSame(LiteraryReport::SECTIONS, $report->sections);
        $this->assertFalse($report->include_details);
        $this->assertNotEmpty($report->introduction);
        $this->assertEmpty($report->president_word);
        $this->assertEmpty($report->note);
        $this->assertNull($report->signer_1_board_member_id);
        $this->assertNull($report->signer_2_board_member_id);

        $this->assertChapters($season);

        // The six older types stay, unused.
        $this->assertSame(11, ActivityType::count());
        $this->assertSame(6, ActivityType::whereDoesntHave('activities')->count());

        // Nothing else moves; no activity-log rows.
        $this->assertSame(0, ActivityLog::whereIn('subject_type', [Activity::class, LiteraryReport::class, ActivityPhoto::class, ActivityFile::class])->count());
        $this->assertSame(22, Player::where('archived', false)->count());
    }

    private function assertChapters(Season $season): void
    {
        $chapters = ActivityChapters::inPeriod($season->start(), $season->end());
        $this->assertSame(
            [['تنظيمات النادي', 1], ['المشاركات الرياضية', 2], ['الحملات التطوعية', 2], ['التكوينات', 1], ['إعلانات النادي', 1]],
            $chapters->map(fn (array $c) => [$c['type'], $c['activities']->count()])->all(),
        );
    }

    #[Test]
    public function a_seed_on_the_first_days_of_a_season_keeps_every_activity_in_it(): void
    {
        $this->seedOn('2027-09-03 09:00:00');
        $season = Season::current();
        $this->assertSame(2027, $season->startYear);

        $this->assertSame(7, Activity::count());
        foreach (Activity::all() as $a) {
            $this->assertGreaterThanOrEqual('2027-09-01', $a->starts_on->toDateString(), $a->title);
            $this->assertLessThanOrEqual('2027-09-03', $a->starts_on->toDateString(), $a->title);
            if ($a->ends_on) {
                $this->assertGreaterThanOrEqual($a->starts_on->toDateString(), $a->ends_on->toDateString());
            }
        }
        $this->assertSame('التقرير الأدبي للموسم 2027/2028', LiteraryReport::firstOrFail()->title);
        $this->assertChapters($season);
    }

    #[Test]
    public function on_the_throwaway_storage_the_photos_and_files_are_written(): void
    {
        $this->tmpRoot = sys_get_temp_dir().'/'.uniqid('help', true);
        $base = $this->tmpRoot.'/help-demo-storage/app';
        File::ensureDirectoryExists($base.'/public');
        File::ensureDirectoryExists($base.'/private');
        config(['filesystems.disks.public.root' => $base.'/public', 'filesystems.disks.local.root' => $base.'/private']);
        Storage::forgetDisk(['public', 'local']);

        $this->seedOn('2026-11-18 10:00:00');

        $this->assertSame(7, ActivityPhoto::count());
        $this->assertSame(3, ActivityPhoto::where('in_report', true)->count());
        $this->assertLessThanOrEqual(1, ActivityPhoto::where('in_report', true)->selectRaw('activity_id, count(*) c')->groupBy('activity_id')->get()->max('c'));
        $this->assertSame(
            [Demo::TOURNAMENT => 2, Demo::BEACH => 3, Demo::VISIT => 1, Demo::FIRST_AID => 1],
            Activity::has('photos')->withCount('photos')->orderBy('id')->pluck('photos_count', 'title')->all(),
        );
        $this->assertEqualsCanonicalizing(['الفريق قبل النهائي', 'تسليم الميداليات', 'جمع النفايات', 'فريق المتطوعين', 'الشاطئ بعد الحملة', 'جلسة مع المقيمين', 'تمرين عملي'], ActivityPhoto::pluck('caption')->all());
        foreach (ActivityPhoto::all() as $photo) {
            $this->assertFileExists($base.'/public/'.ltrim((string) $photo->getRawOriginal('path'), '/'));
            $this->assertFileExists($base.'/public/'.ltrim((string) $photo->getRawOriginal('thumb_path'), '/'));
        }
        $this->assertFalse((bool) ActivityPhoto::where('caption', 'جلسة مع المقيمين')->value('in_report'));

        $this->assertEqualsCanonicalizing(['محضر اللقاء.pdf', 'اتفاقية الشراكة.pdf'], ActivityFile::pluck('original_name')->all());
        foreach (ActivityFile::all() as $file) {
            $this->assertFileExists($base.'/private/'.ltrim((string) $file->getRawOriginal('path'), '/'));
        }
    }
}
