<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FinanceSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function finance_settings_no_longer_lists_accounts(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('finance.settings'))
            ->assertInertia(fn ($page) => $page
                ->missing('accounts')
                ->missing('branches')
                ->has('years')
                ->has('categories'));
    }
}
