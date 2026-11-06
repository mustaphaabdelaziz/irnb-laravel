<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\Category;
use App\Models\FinanceAccount;
use App\Models\User;
use App\Models\WebsiteConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** `migrate:fresh --seed` ends where creating the first branch through the app would. */
class FreshInstallSeedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_seeded_fresh_install_has_owned_categories_a_main_branch_and_passes_verify(): void
    {
        $this->seed();

        $this->assertGreaterThan(0, Category::count());
        $this->assertSame(0, Category::whereNull('branch_id')->count());
        $this->assertNotNull(WebsiteConfig::singleton()->main_branch_id);
        $this->assertSame(Branch::orderBy('id')->value('id'), (int) WebsiteConfig::singleton()->main_branch_id);
        $this->assertSame(0, Branch::whereNull('code')->count());
        $this->assertTrue(FinanceAccount::exists(), 'each branch gets its coffre');
        $this->assertNotNull(User::first()->username, 'the seeded admin can log in by username');

        $this->artisan('registrations:verify')->assertExitCode(0);
    }
}
