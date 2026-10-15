<?php

namespace Tests\Feature;

use App\Models\FinanceCategory;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WebsiteConfig;
use App\Support\ClubTimezone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClubTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private function setTimezone(?string $timezone): void
    {
        $config = WebsiteConfig::singleton();
        $config->update(['settings' => ['timezone' => $timezone] + ($config->settings ?? [])]);
    }

    #[Test]
    public function the_app_runs_in_algiers_time_out_of_the_box(): void
    {
        $this->assertSame('Africa/Algiers', config('app.timezone'));
        $this->assertSame('Africa/Algiers', date_default_timezone_get());
        $this->assertSame('Africa/Algiers', now()->timezoneName);
    }

    #[Test]
    public function the_app_follows_the_timezone_chosen_in_settings(): void
    {
        $this->setTimezone('Europe/Paris');

        ClubTimezone::apply();

        $this->assertSame('Europe/Paris', ClubTimezone::name());
        $this->assertSame('Europe/Paris', config('app.timezone'));
        $this->assertSame('Europe/Paris', now()->timezoneName);
    }

    #[Test]
    public function an_unknown_or_empty_timezone_falls_back_to_algiers(): void
    {
        foreach (['Mars/Olympus', '', null] as $value) {
            $this->setTimezone($value);

            ClubTimezone::apply();

            $this->assertSame('Africa/Algiers', now()->timezoneName, var_export($value, true));
        }
    }

    #[Test]
    public function reading_the_timezone_never_creates_the_settings_row(): void
    {
        $this->assertSame('Africa/Algiers', ClubTimezone::name());
        $this->assertSame(0, WebsiteConfig::query()->count());
    }

    #[Test]
    public function settings_reject_a_timezone_that_does_not_exist(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('settings.update'), ['settings' => ['timezone' => 'Mars/Olympus']])
            ->assertSessionHasErrors('settings.timezone');

        $this->actingAs($admin)
            ->put(route('settings.update'), ['settings' => ['timezone' => 'Europe/Paris']])
            ->assertSessionHasNoErrors();

        $this->assertSame('Europe/Paris', WebsiteConfig::singleton()->settings['timezone']);
    }

    #[Test]
    public function an_entry_at_half_past_midnight_in_algiers_on_september_first_opens_the_new_season(): void
    {
        // 00:30 in Algiers is still 31 August in UTC: on a UTC clock this entry
        // was booked on the 31st, in the season that had just closed.
        Carbon::setTestNow(Carbon::create(2026, 9, 1, 0, 30, 0, 'Africa/Algiers'));
        $admin = User::factory()->admin()->create();
        $category = FinanceCategory::firstOrCreate(['type' => 'income', 'name' => 'Donations'], ['is_active' => true]);

        $this->actingAs($admin)->post(route('transactions.store'), [
            'title' => 'Midnight gift', 'amount' => 500, 'transaction_type' => 'income',
            'finance_category_id' => $category->id, 'payment_method' => 'cash', 'status' => 'Paid',
        ])->assertSessionHasNoErrors();

        $transaction = Transaction::where('title', 'Midnight gift')->firstOrFail();
        $this->assertSame(2027, $transaction->fiscal_year);
        $this->assertSame('2026-09-01', $transaction->toArray()['transaction_date']);
    }

    #[Test]
    public function a_dateless_entry_is_dated_by_the_club_clock_not_the_database_clock(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 9, 1, 0, 30, 0, 'Africa/Algiers'));

        $transaction = Transaction::create([
            'amount' => 100, 'transaction_type' => 'income', 'category' => 'donation',
            'payment_method' => 'cash', 'status' => 'Paid',
        ]);

        $this->assertSame('2026-09-01', $transaction->fresh()->transaction_date->toDateString());
        $this->assertSame(2027, $transaction->fresh()->fiscal_year);
    }
}
