<?php

namespace Tests\Feature\Help;

use App\Models\Category;
use App\Models\DocumentType;
use App\Models\Player;
use App\Models\User;
use App\Models\WebsiteConfig;
use Database\Seeders\HelpDemoSeeder;
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

        $this->assertGreaterThanOrEqual(20, Player::count());
        // The 2nd and 4th players (indices 1 and 3) also swim (branch-registrations article).
        $this->assertSame(2, Player::has('registrations', '>=', 2)->count());
        $this->assertGreaterThanOrEqual(3, DocumentType::count());
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
    public function it_refuses_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(\RuntimeException::class);
        app(HelpDemoSeeder::class)->run();
    }
}
