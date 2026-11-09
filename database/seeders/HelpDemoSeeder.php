<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Category;
use App\Models\DocumentType;
use App\Models\Player;
use App\Models\User;
use App\Models\WebsiteConfig;
use App\Services\Player\MembershipNumber;
use App\Services\Player\RegistrationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * The fake club the Help Center screenshots are taken on (scripts/help-shots.mjs).
 * Runs only on the script's throwaway SQLite file — never on real data.
 * أحمد بن علي is created first so he is player 1: resources/help/shots.js uses /players/1.
 */
class HelpDemoSeeder extends Seeder
{
    public const USERNAME = 'demo';

    public const PASSWORD = 'demo-help-2026';

    /** [firstname, lastname, father, grandfather, gender, birthdate, branch key, category name] */
    private const PLAYERS = [
        ['أحمد', 'بن علي', 'محمد', 'الطاهر', 'Male', '2012-03-14', 'foot', 'أقل من 14 سنة'],
        ['ياسين', 'بوزيد', 'عمر', 'سعيد', 'Male', '2011-07-02', 'foot', 'أقل من 14 سنة'],
        ['أمين', 'قاسمي', 'رشيد', 'الهادي', 'Male', '2010-01-22', 'foot', 'أقل من 17 سنة'],
        ['رياض', 'حمداني', 'كمال', 'صالح', 'Male', '2009-11-05', 'foot', 'أقل من 17 سنة'],
        ['إسلام', 'مرابط', 'نور الدين', 'أحمد', 'Male', '2013-05-19', 'foot', 'أقل من 12 سنة'],
        ['أيوب', 'شريف', 'مراد', 'بلقاسم', 'Male', '2014-02-11', 'foot', 'أقل من 12 سنة'],
        ['عبد الرحمن', 'بلعيد', 'فريد', 'يوسف', 'Male', '2010-09-30', 'foot', 'أقل من 17 سنة'],
        ['زكرياء', 'عمراني', 'سفيان', 'عيسى', 'Male', '2012-12-08', 'foot', 'أقل من 14 سنة'],
        ['وليد', 'سعداوي', 'حسين', 'علي', 'Male', '2005-04-17', 'foot', 'أكابر'],
        ['هشام', 'بوعلام', 'جمال', 'مصطفى', 'Male', '2003-08-25', 'foot', 'أكابر'],
        ['مريم', 'زروقي', 'عبد القادر', 'محمد', 'Female', '2013-06-03', 'swim', 'براعم'],
        ['سارة', 'بن يوسف', 'ناصر', 'الطيب', 'Female', '2012-10-21', 'swim', 'أصاغر'],
        ['إيناس', 'حداد', 'كريم', 'عمر', 'Female', '2011-03-09', 'swim', 'أصاغر'],
        ['آية', 'بلحاج', 'مالك', 'سليمان', 'Female', '2014-01-15', 'swim', 'براعم'],
        ['نور', 'شعبان', 'لطفي', 'إبراهيم', 'Female', '2010-05-28', 'swim', 'أشبال'],
        ['آدم', 'ميهوبي', 'خالد', 'رابح', 'Male', '2013-09-12', 'swim', 'براعم'],
        ['يوسف', 'بوشامة', 'هارون', 'عمار', 'Male', '2011-11-30', 'swim', 'أصاغر'],
        ['لينا', 'قادري', 'عادل', 'الحاج', 'Female', '2012-07-07', 'swim', 'أصاغر'],
        ['محمد', 'عيساوي', 'بشير', 'المختار', 'Male', '2009-02-19', 'swim', 'أشبال'],
        ['ريم', 'بن عمر', 'توفيق', 'قدور', 'Female', '2014-04-24', 'swim', 'براعم'],
        ['أنس', 'لعريبي', 'منير', 'حمزة', 'Male', '2010-12-02', 'foot', 'أقل من 17 سنة'],
        ['شيماء', 'مزياني', 'رضا', 'عبد الله', 'Female', '2011-08-16', 'swim', 'أصاغر'],
    ];

    /** The screenshot script's throwaway file (storage/help-demo.sqlite), nothing else. */
    public static function canRunOn(string $driver, ?string $database): bool
    {
        return $driver === 'sqlite'
            && $database !== null
            && str_ends_with(str_replace('\\', '/', $database), '/help-demo.sqlite');
    }

    private const REFUSAL = 'HelpDemoSeeder only runs on an empty throwaway database (scripts/help-shots.mjs).';

    /** Shared by the sub-seeders: refuses production and any database but the script's own file. */
    public static function assertThrowawayDatabase(): void
    {
        $connection = config('database.default');
        $onDemoFile = self::canRunOn(
            (string) config("database.connections.{$connection}.driver"),
            config("database.connections.{$connection}.database"),
        );

        // The test suite runs on its own in-memory database, so it is allowed too.
        if (app()->isProduction() || (! $onDemoFile && ! app()->runningUnitTests())) {
            throw new RuntimeException(self::REFUSAL);
        }
    }

    public function run(): void
    {
        self::assertThrowawayDatabase();

        if (Player::query()->exists()) {
            throw new RuntimeException(self::REFUSAL);
        }

        $this->call(DatabaseSeeder::class);

        // Drop the default English categories so only the Arabic demo ones show in screenshots.
        Category::query()->whereIn('name', ['Senior', 'Junior', 'Cadet', 'Minime', 'Ecole'])->delete();

        WebsiteConfig::singleton()->update([
            'club_name' => ['ar' => 'نادي المثال', 'fr' => 'Club Exemple', 'en' => 'Example Club'],
            'club_short_name' => 'نادي المثال',
        ]);

        User::create([
            'name' => 'مستخدم تجريبي',
            'username' => self::USERNAME,
            'email' => 'demo@example.invalid',
            'password' => Hash::make(self::PASSWORD),
            'firstname' => 'كريم',
            'lastname' => 'المدرب',
            'gender' => 'Male',
            'phones' => [],
            'is_user' => true,
            'is_active' => true,
            'approved' => true,
            'privileges' => ['superadmin', 'admin'],
            'preferred_lng' => 'ar',
            'email_verified_at' => now(),
        ]);

        $branches = [
            'foot' => Branch::where('name', 'Football')->firstOrFail(),
            'swim' => Branch::where('name', 'Swimming')->firstOrFail(),
        ];
        $categories = [];
        foreach (self::PLAYERS as $row) {
            [, , , , , , $branchKey, $categoryName] = $row;
            $categories[$branchKey][$categoryName] ??= Category::create([
                'name' => $categoryName,
                'branch_id' => $branches[$branchKey]->id,
            ]);
        }

        // The migration seeds the document types but ticks them for no branch (the
        // branches come later, from the seeder): tick the required ones for both,
        // and ask four photos so the documents article can show copies.
        $required = DocumentType::query()->where('is_required', true)->pluck('id');
        foreach ($branches as $branch) {
            $branch->documentTypes()->syncWithoutDetaching($required);
        }
        DocumentType::query()->where('code', DocumentType::PHOTO)->update(['copies' => 4]);

        $registrations = app(RegistrationService::class);
        foreach (self::PLAYERS as $i => [$first, $last, $father, $grandfather, $gender, $birth, $branchKey, $categoryName]) {
            // Numbered by row: a dated swimming card can take over a player's ID, so
            // generateUnique() (which reads players only) would hand out one twice.
            $player = Player::create([
                'membership_id' => MembershipNumber::format(2024, $i + 1),
                'firstname' => $first,
                'lastname' => $last,
                'father' => $father,
                'grandfather' => $grandfather,
                'gender' => $gender,
                'birthdate' => $birth,
                'join_year' => 2024,
                'outstanding_debt' => 0,
            ]);
            // Every third player keeps only the join year, so the join-dates queue has rows.
            $registrations->register($player, [
                'branch_id' => $branches[$branchKey]->id,
                'category_id' => $categories[$branchKey][$categoryName]->id,
                'join_date' => $i % 3 === 2 ? null : sprintf('2024-09-%02d', $i + 1),
            ]);
            // Two players also swim, so the branch-registrations article has a real example.
            if (in_array($i, [1, 3], true)) {
                $registrations->register($player, [
                    'branch_id' => $branches['swim']->id,
                    'category_id' => $categories['swim']['أشبال']->id,
                    'join_date' => '2025-10-01',
                ]);
            }
        }

        $this->call(HelpDemoSubscriptionsSeeder::class);
        $this->call(HelpDemoAttendanceSeeder::class);
        $this->call(HelpDemoFinanceSeeder::class);
        $this->call(HelpDemoUsersSeeder::class);
    }
}
