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

    public function run(): void
    {
        if (app()->isProduction() || Player::query()->exists()) {
            throw new \RuntimeException('HelpDemoSeeder only runs on an empty throwaway database (scripts/help-shots.mjs).');
        }

        $this->call(DatabaseSeeder::class);

        // Drop the default English categories so only the Arabic demo ones show in screenshots.
        Category::query()->whereIn('name', ['Senior', 'Junior', 'Cadet', 'Minime', 'Ecole'])->delete();

        WebsiteConfig::singleton()->update([
            'club_name' => ['ar' => 'نادي المثال', 'fr' => 'Club Exemple', 'en' => 'Example Club'],
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

        foreach ([['PHOTO', 'صورة شمسية'], ['BIRTH', 'شهادة الميلاد'], ['MEDICAL', 'شهادة طبية']] as [$code, $name]) {
            DocumentType::create(['code' => $code, 'name' => $name, 'name_ar' => $name, 'is_required' => true]);
        }

        $registrations = app(RegistrationService::class);
        foreach (self::PLAYERS as $i => [$first, $last, $father, $grandfather, $gender, $birth, $branchKey, $categoryName]) {
            $player = Player::create([
                'membership_id' => MembershipNumber::generateUnique(2024),
                'firstname' => $first,
                'lastname' => $last,
                'father' => $father,
                'grandfather' => $grandfather,
                'gender' => $gender,
                'birthdate' => $birth,
                'join_year' => 2024,
                'outstanding_debt' => 0,
            ]);
            $registrations->register($player, [
                'branch_id' => $branches[$branchKey]->id,
                'category_id' => $categories[$branchKey][$categoryName]->id,
            ]);
            // Two players also swim, so the branch-registrations article has a real example.
            if (in_array($i, [1, 3], true)) {
                $registrations->register($player, [
                    'branch_id' => $branches['swim']->id,
                    'category_id' => $categories['swim']['أشبال']->id,
                ]);
            }
        }
    }
}
