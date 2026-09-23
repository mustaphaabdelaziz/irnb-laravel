<?php

namespace Tests\Feature;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentItem;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Flash messages are translation keys, not English sentences. A key that is
 * missing from the catalogs renders as raw "flash.something" in the UI, which
 * no test would otherwise catch — so the catalogs are asserted here.
 */
class FlashTranslationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> */
    private function catalog(string $locale): array
    {
        return json_decode(file_get_contents(base_path("resources/js/i18n/{$locale}.json")), true);
    }

    private function user(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    #[Test]
    public function every_flash_key_used_by_a_controller_exists_in_all_three_catalogs(): void
    {
        $files = array_merge(
            glob(app_path('Http/Controllers/*.php')),
            glob(app_path('Http/Controllers/*/*.php')),
            glob(app_path('Exceptions/Equipment/*.php')),
        );

        $used = [];
        foreach ($files as $file) {
            preg_match_all("/'(flash\.[a-z0-9_]+)'/", file_get_contents($file), $matches);
            foreach ($matches[1] as $key) {
                $used[$key] = basename($file);
            }
        }

        $this->assertNotEmpty($used, 'no flash keys found — the scan is broken');

        foreach (['en', 'fr', 'ar'] as $locale) {
            $catalog = $this->catalog($locale);
            $missing = array_diff_key($used, $catalog);

            $this->assertSame(
                [],
                $missing,
                "{$locale}.json is missing: ".implode(', ', array_keys($missing))
            );
        }
    }

    #[Test]
    public function the_three_catalogs_hold_exactly_the_same_keys(): void
    {
        $en = array_keys($this->catalog('en'));
        $fr = array_keys($this->catalog('fr'));
        $ar = array_keys($this->catalog('ar'));

        sort($en);
        sort($fr);
        sort($ar);

        $this->assertSame($en, $fr, 'fr.json has drifted from en.json');
        $this->assertSame($en, $ar, 'ar.json has drifted from en.json');
    }

    #[Test]
    public function no_controller_still_flashes_a_literal_english_sentence(): void
    {
        $files = array_merge(
            glob(app_path('Http/Controllers/*.php')),
            glob(app_path('Http/Controllers/*/*.php')),
        );

        $literals = [];
        foreach ($files as $file) {
            $tokens = token_get_all(file_get_contents($file));
            for ($i = 0, $n = count($tokens); $i < $n; $i++) {
                if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_OBJECT_OPERATOR) {
                    continue;
                }
                if (! is_array($tokens[$i + 1]) || $tokens[$i + 1][1] !== 'with') {
                    continue;
                }
                $j = $i + 2;
                if ($tokens[$j] !== '(') {
                    continue;
                }
                $j++;
                if (! is_array($tokens[$j]) || $tokens[$j][0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }
                if (! in_array(trim($tokens[$j][1], "'\""), ['success', 'error'], true)) {
                    continue;
                }
                $j++;
                if ($tokens[$j] !== ',') {
                    continue;
                }
                $j++;
                while (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }
                if (! is_array($tokens[$j]) || $tokens[$j][0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }
                $k = $j + 1;
                while (is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
                    $k++;
                }
                if ($tokens[$k] !== ')') {
                    continue;
                }

                $text = trim($tokens[$j][1], "'\"");
                if (! str_starts_with($text, 'flash.')) {
                    $literals[] = basename($file).': '.$text;
                }
            }
        }

        $this->assertSame([], $literals, "these still flash English:\n".implode("\n", $literals));
    }

    #[Test]
    public function a_flash_reaches_the_page_as_a_key(): void
    {
        $catalog = EquipmentCatalog::create(['name' => 'Dossards', 'category' => 'Apparel']);

        $this->actingAs($this->user())
            ->post(route('equipment.stock.receive'), [
                'catalog_id' => $catalog->id,
                'quantity' => 10,
                'purchase_date' => '2026-01-01',
                'condition' => 'New',
                'record_expense' => false,
            ])
            ->assertSessionHas('success', 'flash.equipment_stock_received');
    }

    #[Test]
    public function an_interpolated_flash_carries_its_parameters(): void
    {
        $player = Player::create([
            'firstname' => 'Ali', 'lastname' => 'B',
            'membership_id' => '202600123', 'join_year' => 2026,
        ]);

        $this->actingAs($this->user())
            ->post(route('players.bulkArchive'), ['ids' => [$player->id]])
            ->assertSessionHas('success', [
                'key' => 'flash.players_archived',
                'params' => ['count' => 1],
            ]);
    }

    #[Test]
    public function a_stock_error_reaches_the_page_as_a_key_with_parameters(): void
    {
        $catalog = EquipmentCatalog::create(['name' => 'Cones', 'category' => 'Training Equipment']);
        $lot = EquipmentItem::create([
            'catalog_id' => $catalog->id,
            'purchase_date' => '2026-01-01',
            'quantity' => 5,
        ]);
        $player = Player::create([
            'firstname' => 'Ali', 'lastname' => 'B',
            'membership_id' => '202600124', 'join_year' => 2026,
        ]);

        $this->actingAs($this->user())
            ->post(route('equipment.items.rent'), [
                'equipment_item_id' => $lot->id,
                'rentable_type' => 'Player',
                'rentable_id' => $player->id,
                'type' => 'rental',
                'quantity' => 9,
            ])
            ->assertSessionHas('error', [
                'key' => 'flash.stock_not_enough_available',
                'params' => ['requested' => 9, 'available' => 5],
            ]);
    }
}
