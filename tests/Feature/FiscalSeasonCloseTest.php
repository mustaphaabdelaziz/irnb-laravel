<?php

namespace Tests\Feature;

use App\Models\FiscalYear;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FiscalSeasonCloseTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_new_fiscal_year_gets_season_dates(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('finance.years.store'), ['year' => 2027, 'opening_balance' => 0])
            ->assertSessionHas('success', ['key' => 'flash.fiscal_year_created', 'params' => ['year' => '2026/2027']]);

        $fy = FiscalYear::where('year', 2027)->first();
        $this->assertSame('2026-09-01', $fy->start_date->toDateString());
        $this->assertSame('2027-08-31', $fy->end_date->toDateString());
    }

    #[Test]
    public function closing_carries_the_balance_into_the_next_season(): void
    {
        Transaction::create(['amount' => 1000, 'transaction_date' => '2026-10-01', 'transaction_type' => 'income', 'category' => 'donation', 'payment_method' => 'cash', 'status' => 'Paid']);
        $fy = FiscalYear::where('year', 2027)->first();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('finance.years.close', $fy))
            ->assertSessionHas('success', ['key' => 'flash.fiscal_year_closed', 'params' => ['year' => '2026/2027', 'next' => '2027/2028']]);

        $next = FiscalYear::where('year', 2028)->first();
        $this->assertSame('2027-09-01', $next->start_date->toDateString());
        $this->assertSame('2028-08-31', $next->end_date->toDateString());
        $this->assertEquals(1000, (float) $next->opening_balance);
    }

    #[Test]
    public function closing_never_rewrites_an_already_closed_next_season(): void
    {
        $fy = FiscalYear::create(['year' => 2027, 'start_date' => '2026-09-01', 'end_date' => '2027-08-31', 'status' => 'open', 'opening_balance' => 0]);
        FiscalYear::create(['year' => 2028, 'start_date' => '2027-09-01', 'end_date' => '2028-08-31', 'status' => 'closed', 'opening_balance' => 500, 'closing_balance' => 500]);

        $this->actingAs(User::factory()->admin()->create())->post(route('finance.years.close', $fy))
            ->assertSessionHas('success', ['key' => 'flash.fiscal_year_closed_no_carry', 'params' => ['year' => '2026/2027', 'next' => '2027/2028']]);

        $this->assertSame('closed', $fy->fresh()->status);
        $this->assertEquals(500, (float) FiscalYear::where('year', 2028)->value('opening_balance'));
    }

    #[Test]
    public function settings_offers_season_choices(): void
    {
        Carbon::setTestNow('2026-10-14');

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('finance.settings'))
            ->assertInertia(fn ($page) => $page
                ->where('seasonOptions.0', ['value' => 2028, 'label' => '2027/2028'])
                ->where('seasonOptions.1', ['value' => 2027, 'label' => '2026/2027']));
    }
}
