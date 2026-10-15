<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use App\Models\WebsiteConfig;
use App\Services\Pdf\PdfService;
use App\Support\ClubCurrency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClubCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private function setCurrency(?string $code, ?string $symbol = null): void
    {
        $config = WebsiteConfig::singleton();
        $config->update(['settings' => ['currency' => $code, 'currencySymbol' => $symbol] + ($config->settings ?? [])]);
    }

    #[Test]
    public function the_dinar_reads_in_the_language_of_the_screen(): void
    {
        $this->setCurrency('DZD', 'DZD');

        $this->assertSame('DZD', ClubCurrency::code());
        $this->assertNull(ClubCurrency::symbol());
        $this->assertSame('د.ج', ClubCurrency::label('ar'));
        $this->assertSame('DA', ClubCurrency::label('fr'));
        $this->assertSame('DZD', ClubCurrency::label('en'));
    }

    #[Test]
    public function the_label_defaults_to_the_app_locale(): void
    {
        app()->setLocale('fr');

        $this->assertSame('DA', ClubCurrency::label());
    }

    #[Test]
    public function the_euro_and_the_dollar_use_their_sign_in_every_language(): void
    {
        $this->setCurrency('EUR', 'EUR');
        foreach (['ar', 'fr', 'en'] as $locale) {
            $this->assertSame('€', ClubCurrency::label($locale));
        }

        $this->setCurrency('USD');
        foreach (['ar', 'fr', 'en'] as $locale) {
            $this->assertSame('$', ClubCurrency::label($locale));
        }
    }

    #[Test]
    public function another_currency_shows_its_code(): void
    {
        $this->setCurrency('TND', '');

        $this->assertSame('TND', ClubCurrency::label('ar'));
    }

    #[Test]
    public function a_custom_symbol_wins_over_the_standard_sign(): void
    {
        $this->setCurrency('DZD', 'دج');

        $this->assertSame('دج', ClubCurrency::symbol());
        $this->assertSame('دج', ClubCurrency::label('fr'));
    }

    #[Test]
    public function no_settings_means_the_dinar_and_no_row_is_created(): void
    {
        $this->assertSame('DZD', ClubCurrency::code());
        $this->assertSame('DA', ClubCurrency::label('fr'));
        $this->assertSame(0, WebsiteConfig::query()->count());
    }

    #[Test]
    public function every_page_receives_the_club_currency(): void
    {
        $this->setCurrency('EUR', '€ ');

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('currency.code', 'EUR')
                ->where('currency.symbol', '€'));
    }

    #[Test]
    public function settings_save_the_currency_and_its_custom_symbol(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->put(route('settings.update'), ['settings' => ['currency' => 'EUR', 'currencySymbol' => null]])
            ->assertSessionHasNoErrors();

        $this->assertSame('€', ClubCurrency::label('ar'));

        $this->actingAs($admin)
            ->put(route('settings.update'), ['settings' => ['currency' => 'euro!', 'currencySymbol' => str_repeat('x', 20)]])
            ->assertSessionHasErrors(['settings.currency', 'settings.currencySymbol']);
    }

    #[Test]
    public function a_printed_receipt_shows_the_club_currency_label(): void
    {
        $this->setCurrency('DZD', 'DZD');
        $admin = User::factory()->admin()->create(['preferred_lng' => 'fr']);
        $transaction = Transaction::create([
            'amount' => 2000, 'transaction_date' => now(), 'transaction_type' => 'income',
            'category' => 'subscription', 'payment_method' => 'cash', 'status' => 'Paid',
        ]);

        $seen = new \ArrayObject;
        $this->mock(PdfService::class, function (MockInterface $mock) use ($seen) {
            $mock->shouldReceive('stream')->once()->andReturnUsing(function (string $html) use ($seen) {
                $seen['html'] = $html;

                return response('%PDF-spy', 200, ['Content-Type' => 'application/pdf']);
            });
        });

        $this->actingAs($admin)->get(route('transactions.receipt', $transaction))->assertOk();

        $this->assertStringContainsString('2,000.00 DA', $seen['html']);
    }
}
