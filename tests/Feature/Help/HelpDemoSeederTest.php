<?php

namespace Tests\Feature\Help;

use App\Models\BranchRegistration;
use App\Models\Category;
use App\Models\DocumentType;
use App\Models\Player;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebsiteConfig;
use Database\Seeders\HelpDemoSeeder;
use Database\Seeders\HelpDemoSubscriptionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HelpDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_builds_the_demo_club_used_by_the_screenshots(): void
    {
        $this->seed(HelpDemoSeeder::class);

        $this->assertSame('نادي المثال', WebsiteConfig::singleton()->club_name['ar']);
        $demo = User::where('username', HelpDemoSeeder::USERNAME)->firstOrFail();
        $this->assertTrue($demo->isSuperadmin());
        $this->assertSame('ar', $demo->preferred_lng);

        $ahmed = Player::findOrFail(1);
        $this->assertSame('أحمد', $ahmed->firstname);
        $this->assertSame('بن علي', $ahmed->lastname);
        $this->assertSame(1, $ahmed->registrations()->count());
        $this->assertNotNull($ahmed->registrations()->first()->join_date);
        // Some registrations keep only the year: the join-dates queue has rows to show.
        $this->assertTrue(BranchRegistration::whereNull('join_date')->exists());

        $this->assertGreaterThanOrEqual(20, Player::count());
        // The 2nd and 4th players (indices 1 and 3) also swim (branch-registrations article).
        $this->assertSame(2, Player::has('registrations', '>=', 2)->count());
        $this->assertGreaterThanOrEqual(3, DocumentType::count());
        // Both branches tick the required types, or the documents card is empty (documents article).
        $photo = DocumentType::where('code', DocumentType::PHOTO)->firstOrFail();
        $this->assertSame(4, $photo->copies);
        $this->assertSame(2, $photo->branches()->count());
        // Case-exact in PHP: a case-insensitive collation would match 'PHOTO' against any spelling.
        $this->assertNotContains('PHOTO', DocumentType::pluck('code')->all());
        $this->assertFalse(Category::query()->where('name', 'Senior')->exists());
    }

    #[Test]
    public function the_demo_user_can_sign_in(): void
    {
        $this->seed(HelpDemoSeeder::class);

        $this->post('/login', ['username' => HelpDemoSeeder::USERNAME, 'password' => HelpDemoSeeder::PASSWORD])
            ->assertRedirect();
        $this->assertAuthenticated();
    }

    #[Test]
    public function it_refuses_a_database_that_already_has_players(): void
    {
        $category = Category::create(['name' => 'X']);
        Player::create([
            'membership_id' => 'X-1',
            'firstname' => 'A',
            'lastname' => 'B',
            'category_id' => $category->id,
            'outstanding_debt' => 0,
        ]);

        try {
            $this->seed(HelpDemoSeeder::class);
            $this->fail('Seeder should have refused a database with players.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('empty throwaway database', $e->getMessage());
        }

        $this->assertFalse(User::where('username', HelpDemoSeeder::USERNAME)->exists());
    }

    #[Test]
    public function it_runs_only_on_the_screenshot_scripts_sqlite_file(): void
    {
        $this->assertTrue(HelpDemoSeeder::canRunOn('sqlite', 'D:\\irnb\\storage\\help-demo.sqlite'));
        $this->assertTrue(HelpDemoSeeder::canRunOn('sqlite', '/srv/app/storage/help-demo.sqlite'));

        $this->assertFalse(HelpDemoSeeder::canRunOn('sqlite', '/srv/app/database/database.sqlite'));
        $this->assertFalse(HelpDemoSeeder::canRunOn('sqlite', '/srv/app/storage/help-demo.sqlite.bak'));
        $this->assertFalse(HelpDemoSeeder::canRunOn('sqlite', null));
        $this->assertFalse(HelpDemoSeeder::canRunOn('mysql', 'help-demo.sqlite'));
        $this->assertFalse(HelpDemoSeeder::canRunOn('pgsql', 'irnb'));
    }

    #[Test]
    public function it_refuses_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(\RuntimeException::class);
        app(HelpDemoSeeder::class)->run();
    }

    #[Test]
    public function the_subscriptions_seeder_refuses_a_real_database_on_its_own(): void
    {
        $this->app['env'] = 'production';

        try {
            app(HelpDemoSubscriptionsSeeder::class)->run();
            $this->fail('The subscriptions seeder ran in production.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('throwaway database', $e->getMessage());
        }

        $this->assertSame(0, Subscription::count());
    }
}
