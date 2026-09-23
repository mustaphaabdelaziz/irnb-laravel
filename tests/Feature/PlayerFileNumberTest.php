<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\User;
use App\Models\WebsiteConfig;
use App\Services\Player\FileNumber;
use App\Services\Player\RegisterPlayerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerFileNumberTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function register(string $firstname, int $joinYear = 2026): Player
    {
        return app(RegisterPlayerService::class)->handle([
            'firstname' => $firstname,
            'join_year' => $joinYear,
        ]);
    }

    #[Test]
    public function a_registered_player_gets_the_next_file_number(): void
    {
        $first = $this->register('Amine');
        $second = $this->register('Yanis');

        $this->assertSame(1, $first->fresh()->file_number);
        $this->assertSame(2, $second->fresh()->file_number);
    }

    #[Test]
    public function a_number_is_never_reused_after_a_player_leaves(): void
    {
        $this->register('Amine');
        $second = $this->register('Yanis');
        $second->forceFill(['archived' => true])->save();

        $this->assertSame(3, $this->register('Sami')->fresh()->file_number);
    }

    #[Test]
    public function both_identifiers_survive_a_join_year_change(): void
    {
        $player = $this->register('Amine', 2024);
        $membership = $player->membership_id;
        $fileNumber = $player->fresh()->file_number;

        $this->actingAs($this->admin())
            ->put(route('players.update', $player), ['firstname' => 'Amine', 'join_year' => 2025])
            ->assertRedirect();

        $player->refresh();
        $this->assertSame($membership, $player->membership_id, 'the card and the folder carry this number');
        $this->assertSame($fileNumber, $player->file_number);
        $this->assertSame(2025, $player->join_year);
    }

    #[Test]
    public function the_search_finds_a_player_by_file_number_with_or_without_leading_zeros(): void
    {
        // Three 1999 players soak up file numbers 1-3 and membership ids
        // 199900001-199900003, so the target lands on file number 4 with
        // membership id 199800001 (join year 1998, its own first member).
        // Neither the target's own membership id nor any of the other
        // players' membership ids contains the digit '4' anywhere, so
        // 'search("4")'/'search("0004")' can only find the target through
        // the file_number branch of scopeSearch — the membership_id LIKE
        // match cannot produce this result by coincidence.
        $this->register('Amine', 1999);
        $this->register('Yanis', 1999);
        $this->register('Sami', 1999);
        $target = $this->register('Nadia', 1998); // file number 4, membership id 199800001

        $this->assertSame([$target->id], Player::query()->search('4')->pluck('id')->all());
        $this->assertSame([$target->id], Player::query()->search('0004')->pluck('id')->all());
    }

    #[Test]
    public function a_file_number_is_shown_padded_and_sits_in_a_drawer(): void
    {
        $this->assertSame('0123', FileNumber::format(123));
        $this->assertSame('', FileNumber::format(null));

        // Default drawer holds 100 files.
        $this->assertSame(1, FileNumber::drawer(1));
        $this->assertSame(1, FileNumber::drawer(100));
        $this->assertSame(2, FileNumber::drawer(101));

        $config = WebsiteConfig::singleton();
        $config->update(['settings' => [...$config->settings, 'fileDrawerSize' => 50]]);

        $this->assertSame(2, FileNumber::drawer(51));
    }

    #[Test]
    public function the_export_carries_the_file_number(): void
    {
        $this->register('Amine');

        $csv = $this->actingAs($this->admin())->get(route('players.export'))
            ->assertOk()->streamedContent();

        $this->assertStringContainsString('File number', $csv);
        $this->assertStringContainsString('0001', $csv);
    }

    #[Test]
    public function the_player_page_receives_the_file_number(): void
    {
        $player = $this->register('Amine');

        $props = $this->actingAs($this->admin())->get(route('players.show', $player))
            ->assertOk()->viewData('page')['props'];

        $this->assertSame(1, $props['player']['file_number']);
    }

    #[Test]
    public function the_player_page_loads_everything_it_shows(): void
    {
        $player = $this->register('Amine');

        $props = $this->actingAs($this->admin())->get(route('players.show', $player))
            ->assertOk()->viewData('page')['props'];

        foreach (['status', 'wilaya', 'other_positions', 'member_job', 'emergency_contacts'] as $key) {
            $this->assertArrayHasKey($key, $props['player'], $key.' is not loaded');
        }
    }

    /**
     * The desktop build runs `migrate` on every boot. SQLite does not roll
     * back DDL, so if up() ever partially failed after the column-add
     * committed, the migration would stay unrecorded and the next boot
     * would re-run up() — which must not throw "duplicate column name" (or
     * "index already exists") when the column/index are already there.
     */
    #[Test]
    public function up_can_be_re_run_on_an_already_migrated_database_without_throwing(): void
    {
        $migration = require database_path('migrations/2026_09_23_100002_add_file_number_to_players.php');

        // RefreshDatabase already ran this migration once for this test; call
        // it again to simulate the next boot re-running an unrecorded up().
        $migration->up();

        $this->assertTrue(Schema::hasColumn('players', 'file_number'));
    }

    /**
     * Simulates the exact partial state a mid-loop failure would leave
     * behind: the column (and its unique index) already exist, some rows
     * are already numbered, and others are still null. Re-running up() must
     * only number the null rows, continuing after the current max — never
     * touching or renumbering rows that already have a number.
     */
    #[Test]
    public function up_only_numbers_rows_still_missing_a_file_number_continuing_after_the_max(): void
    {
        $migration = require database_path('migrations/2026_09_23_100002_add_file_number_to_players.php');

        $numbered = Player::create(['membership_id' => '202600001', 'firstname' => 'A', 'join_year' => 2024]);
        $stillNull = Player::create(['membership_id' => '202600002', 'firstname' => 'B', 'join_year' => 2025]);

        // Pretend the migration died after numbering the first row.
        $numbered->forceFill(['file_number' => 5])->save();
        DB::table('players')->where('id', $stillNull->id)->update(['file_number' => null]);

        $migration->up();

        $this->assertSame(5, $numbered->fresh()->file_number, 'an existing number is never renumbered');
        $this->assertSame(6, $stillNull->fresh()->file_number, 'the null row is numbered continuing after the max');
    }

    #[Test]
    public function down_drops_the_column_only_when_present(): void
    {
        $migration = require database_path('migrations/2026_09_23_100002_add_file_number_to_players.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('players', 'file_number'));

        // Calling down() again must not throw even though the column is gone.
        $migration->down();
        $this->assertFalse(Schema::hasColumn('players', 'file_number'));
    }
}
