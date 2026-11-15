<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\ActivityPhoto;
use App\Models\ActivityType;
use App\Models\BoardMember;
use App\Models\BoardTerm;
use App\Models\Branch;
use App\Models\Category;
use App\Models\LiteraryReport;
use App\Models\Player;
use App\Models\User;
use App\Support\Season;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The demo social activities and the literary report for the Help Center
 * social articles. Called only from the guarded HelpDemoSeeder. Models are
 * written directly (no ActivityRecorder). Every date is A(n) = max(D - n days,
 * season start), so it stays inside the current season even on a seed day in
 * early September. Photos and files exist only on the throwaway storage.
 */
class HelpDemoSocialSeeder extends Seeder
{
    public const PARENTS = 'لقاء مع أولياء اللاعبين';

    public const TOURNAMENT = 'دورة الصداقة لأقل من 14 سنة';

    public const BEACH = 'حملة تنظيف شاطئ المدينة';

    public const VISIT = 'زيارة شهرية لدار المسنين';

    public const FIRST_AID = 'تكوين في الإسعافات الأولية للمدربين';

    public const SWIM_CHAMP = 'بطولة السباحة الولائية للأصاغر';

    public const SIGNUPS = 'إعلان فتح التسجيلات للموسم';

    public const DATE_LABEL = '17 من كل شهر';

    public const CLUB_HQ = 'مقر النادي';

    private CarbonImmutable $today;

    private CarbonImmutable $seasonStart;

    private User $demo;

    /** «التقرير الأدبي للموسم 2026/2027» for the current season. */
    public static function reportTitle(): string
    {
        $s = Season::current()->startYear;

        return 'التقرير الأدبي للموسم '.$s.'/'.($s + 1);
    }

    public function run(): void
    {
        HelpDemoSeeder::assertThrowawayDatabase();

        $this->today = CarbonImmutable::today();
        $this->seasonStart = Season::current()->start();
        $this->demo = User::where('username', HelpDemoSeeder::USERNAME)->firstOrFail();

        $term = BoardTerm::where('is_current', true)->firstOrFail();
        $board = fn (string $name): BoardMember => BoardMember::where('board_term_id', $term->id)->with('player')->get()
            ->first(fn (BoardMember $m) => $m->player->firstname.' '.$m->player->lastname === $name)
            ?? throw new RuntimeException("No board member {$name}");
        $football = Branch::where('name', 'Football')->firstOrFail();
        $swimming = Branch::where('name', 'Swimming')->firstOrFail();
        $category = fn (Branch $branch, string $name): Category => Category::where('branch_id', $branch->id)->where('name', $name)->firstOrFail();
        $players = fn (array $names): array => array_map(
            fn (string $name) => Player::where('archived', false)->get()
                ->first(fn (Player $p) => $p->firstname.' '.$p->lastname === $name)?->id
                ?? throw new RuntimeException("No demo player {$name}"),
            $names,
        );
        $type = fn (string $name): int => ActivityType::where('name', $name)->firstOrFail()->id;

        $president = $board(HelpDemoBoardSeeder::PRESIDENT);
        $salim = $board(HelpDemoBoardSeeder::SALIM);
        $fatima = $board(HelpDemoBoardSeeder::FATIMA);
        $nabil = $board(HelpDemoBoardSeeder::NABIL);
        $leila = $board(HelpDemoBoardSeeder::LEILA);
        $kamel = $board(HelpDemoBoardSeeder::KAMEL);

        // [fields, categories, players, board members, photos [scene, variant, caption, in_report], file name]
        $rows = [
            [[
                'title' => self::PARENTS, 'activity_type_id' => $type('تنظيمات النادي'),
                'starts_on' => $this->ago(50), 'location' => self::CLUB_HQ,
                'responsible_board_member_id' => $president->id,
                'beneficiaries_count' => 40, 'external_volunteers_count' => 0,
                'description' => 'لقاء تعريفي مع أولياء اللاعبين حول برنامج الموسم.',
                'report' => 'حضر اللقاء أولياء اللاعبين من مختلف الفئات. عُرض برنامج الموسم والتزامات النادي. وجُمعت اقتراحات الأولياء.',
            ], [], [], [$president, $fatima, $nabil], [], 'محضر اللقاء.pdf'],
            [[
                'title' => self::TOURNAMENT, 'activity_type_id' => $type('المشاركات الرياضية'),
                'starts_on' => $this->ago(35), 'ends_on' => $this->ago(34), 'location' => 'ملعب البلدية',
                'organizer' => 'الرابطة الولائية لكرة القدم', 'result' => 'وصيف الدورة',
                'branch_id' => $football->id, 'responsible_board_member_id' => $salim->id,
                'beneficiaries_count' => 0, 'external_volunteers_count' => 0, 'cost' => 12000,
                'description' => 'شارك فريق أقل من 14 سنة في دورة ودية على مدى يومين.',
                'report' => 'بلغ الفريق المباراة النهائية بعد فوزين متتاليين. وانتهت الدورة بحصول النادي على المركز الثاني. أشاد المنظمون بسلوك اللاعبين.',
            ], [$category($football, 'أقل من 14 سنة')], $players(['أحمد بن علي', 'ياسين بوزيد', 'زكرياء عمراني']), [$salim], [
                ['field', 0, 'الفريق قبل النهائي', true], ['field', 1, 'تسليم الميداليات', false],
            ], null],
            [[
                'title' => self::BEACH, 'activity_type_id' => $type('الحملات التطوعية'),
                'starts_on' => $this->ago(28), 'location' => 'شاطئ المدينة', 'partners' => 'جمعية البيئة المحلية',
                'branch_id' => $swimming->id, 'responsible_board_member_id' => $leila->id,
                'beneficiaries_count' => 150, 'external_volunteers_count' => 12, 'cost' => 8500,
                'description' => 'حملة تطوعية لتنظيف الشاطئ بمشاركة سباحي النادي.',
                'report' => 'نظّف المتطوعون الشاطئ وجمعوا كمية كبيرة من النفايات. شارك فيها سباحو فئة الأصاغر مع أعضاء من الجمعية. وتقرر تكرار الحملة كل موسم.',
            ], [$category($swimming, 'أصاغر')], $players(['سارة بن يوسف', 'إيناس حداد', 'يوسف بوشامة', 'لينا قادري', 'شيماء مزياني']), [$leila, $kamel], [
                ['beach', 0, 'جمع النفايات', false], ['beach', 1, 'فريق المتطوعين', true], ['beach', 2, 'الشاطئ بعد الحملة', false],
            ], 'اتفاقية الشراكة.pdf'],
            [[
                'title' => self::VISIT, 'activity_type_id' => $type('الحملات التطوعية'),
                'starts_on' => $this->ago(45), 'ends_on' => $this->ago(14), 'date_label' => self::DATE_LABEL,
                'location' => 'دار المسنين', 'responsible_board_member_id' => $fatima->id,
                'beneficiaries_count' => 30, 'external_volunteers_count' => 3,
                'description' => 'زيارة شهرية يقضي فيها لاعبو النادي وقتًا مع المقيمين.',
            ], [], $players(['أمين قاسمي', 'نور شعبان']), [$fatima], [
                ['hall', 1, 'جلسة مع المقيمين', false],
            ], null],
            [[
                'title' => self::FIRST_AID, 'activity_type_id' => $type('التكوينات'),
                'starts_on' => $this->ago(21), 'location' => self::CLUB_HQ, 'partners' => 'مركز التكوين المحلي',
                'responsible_board_member_id' => $kamel->id,
                'beneficiaries_count' => 15, 'external_volunteers_count' => 1, 'cost' => 5000,
                'description' => 'تكوين ليوم واحد للمدربين على الإسعافات الأولية.',
                'report' => 'تدرّب المدربون على التعامل مع الإصابات الشائعة في الملعب. أشرف على التكوين مختص من مركز التكوين المحلي. وتسلّم كل مشارك شهادة حضور.',
            ], [], [], [$kamel, $nabil], [
                ['hall', 0, 'تمرين عملي', true],
            ], null],
            [[
                'title' => self::SWIM_CHAMP, 'activity_type_id' => $type('المشاركات الرياضية'),
                'starts_on' => $this->ago(14), 'location' => 'المسبح نصف الأولمبي',
                'organizer' => 'الرابطة الولائية للسباحة', 'result' => 'المرتبة الثالثة',
                'branch_id' => $swimming->id, 'responsible_board_member_id' => $salim->id,
                'beneficiaries_count' => 0, 'external_volunteers_count' => 0,
                'description' => 'شاركت سباحات فئة الأصاغر في البطولة الولائية.',
            ], [$category($swimming, 'أصاغر')], $players(['سارة بن يوسف', 'إيناس حداد', 'لينا قادري']), [], [], null],
            [[
                'title' => self::SIGNUPS, 'activity_type_id' => $type('إعلانات النادي'),
                'starts_on' => $this->ago(55), 'location' => 'صفحة النادي',
                'responsible_board_member_id' => $fatima->id,
                'beneficiaries_count' => 0, 'external_volunteers_count' => 0,
                'description' => 'إعلان على صفحة النادي عن فتح التسجيلات للموسم الجديد.',
            ], [], [], [], [], null],
        ];

        foreach ($rows as [$fields, $categories, $playerIds, $members, $photos, $file]) {
            $activity = Activity::create($fields + ['created_by_user_id' => $this->demo->id]);
            $activity->categories()->sync(array_map(fn (Category $c) => $c->id, $categories));
            $activity->players()->sync($playerIds);
            $activity->boardMembers()->sync(array_map(fn (BoardMember $m) => $m->id, $members));

            foreach ($photos as $i => [$scene, $variant, $caption, $inReport]) {
                if ($image = HelpDemoImages::photo($activity, $scene, $variant)) {
                    ActivityPhoto::create($image + [
                        'activity_id' => $activity->id,
                        'caption' => $caption,
                        'in_report' => $inReport,
                        'sort_order' => $i + 1,
                    ]);
                }
            }
            if ($file !== null && ($attrs = HelpDemoImages::pdf($activity, $file))) {
                $activity->files()->create($attrs);
            }
        }

        $season = Season::current();
        LiteraryReport::create([
            'title' => self::reportTitle(),
            'period_from' => $season->start()->toDateString(),
            'period_to' => $season->end()->toDateString(),
            'sections' => LiteraryReport::SECTIONS,
            'include_details' => false,
            'introduction' => 'يعرض هذا التقرير ما أنجزه النادي هذا الموسم. ويشمل أنشطته الاجتماعية والرياضية وتكويناته.',
            'created_by_user_id' => $this->demo->id,
        ]);
    }

    /** A(n): n days before the seed day, never before the season starts. */
    private function ago(int $days): string
    {
        return max($this->today->subDays($days), $this->seasonStart)->toDateString();
    }
}
