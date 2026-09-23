<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Player;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerPositionsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function position(string $abbr, string $name): Position
    {
        return Position::create(['abbreviation' => $abbr, 'name' => $name]);
    }

    #[Test]
    public function a_player_keeps_one_main_position_and_gains_others(): void
    {
        $mid = $this->position('MF', 'Milieu');
        $wing = $this->position('WG', 'Ailier');
        $back = $this->position('LB', 'Arrière gauche');

        $this->actingAs($this->admin())->post(route('players.store'), [
            'firstname' => 'Amine',
            'position_id' => $mid->id,
            'other_position_ids' => [$wing->id, $back->id],
        ])->assertRedirect();

        $player = Player::query()->firstOrFail();
        $this->assertSame($mid->id, $player->position_id);
        // Sorted ascending by id (wing=2, back=3): the pluck is already sorted,
        // so the expected side must be too for the comparison to mean anything.
        $this->assertSame([$wing->id, $back->id], $player->otherPositions()->orderBy('positions.id')->pluck('positions.id')->sort()->values()->all());
    }

    #[Test]
    public function the_main_position_cannot_be_repeated_among_the_others(): void
    {
        $mid = $this->position('MF', 'Milieu');

        $this->actingAs($this->admin())->post(route('players.store'), [
            'firstname' => 'Amine',
            'position_id' => $mid->id,
            'other_position_ids' => [$mid->id],
        ])->assertSessionHasErrors('other_position_ids');

        $this->assertSame(0, Player::query()->count());
    }

    #[Test]
    public function editing_replaces_the_other_positions(): void
    {
        $mid = $this->position('MF', 'Milieu');
        $wing = $this->position('WG', 'Ailier');
        $back = $this->position('LB', 'Arrière gauche');

        $player = Player::create(['membership_id' => '202600001', 'firstname' => 'Amine', 'position_id' => $mid->id]);
        $player->otherPositions()->sync([$wing->id]);

        $this->actingAs($this->admin())->put(route('players.update', $player), [
            'firstname' => 'Amine',
            'position_id' => $mid->id,
            'other_position_ids' => [$back->id],
        ])->assertRedirect();

        $this->assertSame([$back->id], $player->fresh()->otherPositions()->pluck('positions.id')->all());
    }

    #[Test]
    public function the_filter_finds_a_player_by_any_position_they_play(): void
    {
        $mid = $this->position('MF', 'Milieu');
        $wing = $this->position('WG', 'Ailier');

        $main = Player::create(['membership_id' => '202600002', 'firstname' => 'Amine', 'position_id' => $mid->id]);
        $other = Player::create(['membership_id' => '202600003', 'firstname' => 'Yanis', 'position_id' => $mid->id]);
        $other->otherPositions()->sync([$wing->id]);

        $props = $this->actingAs($this->admin())
            ->get(route('players.index', ['position_id' => $wing->id]))
            ->assertOk()->viewData('page')['props'];

        $this->assertSame(['Yanis'], collect($props['players']['data'])->pluck('firstname')->all());
    }

    #[Test]
    public function the_stats_chart_still_counts_each_player_once_by_main_position(): void
    {
        $mid = $this->position('MF', 'Milieu');
        $wing = $this->position('WG', 'Ailier');

        $player = Player::create(['membership_id' => '202600004', 'firstname' => 'Amine', 'position_id' => $mid->id]);
        $player->otherPositions()->sync([$wing->id]);

        $props = $this->actingAs($this->admin())->get(route('players.index'))->viewData('page')['props'];

        $this->assertSame(1, collect($props['positionStats'])->sum('count'));
    }

    #[Test]
    public function the_import_reads_other_positions_from_the_last_column(): void
    {
        $this->position('MF', 'Milieu');
        $this->position('WG', 'Ailier');
        $this->position('LB', 'Arrière gauche');

        $cells = array_fill(0, 21, '');
        $cells[0] = 'Amine';
        $cells[12] = 'MF';          // main position, existing column
        $cells[20] = 'WG, LB';      // other positions, appended column

        // Built with fputcsv (not a raw implode(',', ...)) so the comma inside
        // "WG, LB" is properly quoted — a naive implode would split it into two
        // fields and silently drop LB, same as a real Excel export would quote it.
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, array_fill(0, 21, 'h'));
        fputcsv($fh, $cells);
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        $this->actingAs($this->admin())->post(route('players.import.store'), [
            'file' => UploadedFile::fake()->createWithContent('players.csv', $csv),
        ])->assertRedirect();

        $player = Player::query()->firstOrFail();
        $this->assertSame('MF', $player->position->abbreviation);
        $this->assertSame(['LB', 'WG'], $player->otherPositions()->orderBy('abbreviation')->pluck('abbreviation')->all());
    }

    #[Test]
    public function the_export_carries_both_columns(): void
    {
        $mid = $this->position('MF', 'Milieu');
        $wing = $this->position('WG', 'Ailier');
        $player = Player::create(['membership_id' => '202600005', 'firstname' => 'Amine', 'position_id' => $mid->id]);
        $player->otherPositions()->sync([$wing->id]);

        $csv = $this->actingAs($this->admin())->get(route('players.export'))->assertOk()->streamedContent();

        $this->assertStringContainsString('Main position', $csv);
        $this->assertStringContainsString('Other positions', $csv);
        $this->assertStringContainsString('WG', $csv);
    }

    #[Test]
    public function an_empty_string_clears_the_other_positions_but_an_omitted_key_leaves_them(): void
    {
        $mid = $this->position('MF', 'Milieu');
        $wing = $this->position('WG', 'Ailier');

        $player = Player::create(['membership_id' => '202600006', 'firstname' => 'Amine', 'position_id' => $mid->id]);
        $player->otherPositions()->sync([$wing->id]);

        // The form (Task 10) submits '' (not an absent key) when the user
        // clears the list: ConvertEmptyStringsToNull turns it into a present
        // null, which the nullable|array rule accepts.
        $this->actingAs($this->admin())->put(route('players.update', $player), [
            'firstname' => 'Amine',
            'position_id' => $mid->id,
            'other_position_ids' => '',
        ])->assertRedirect();

        $this->assertSame([], $player->fresh()->otherPositions()->pluck('positions.id')->all());

        $player->otherPositions()->sync([$wing->id]);

        // A client that never mentions the key at all (e.g. a different
        // caller) must not wipe out what is already there.
        $this->actingAs($this->admin())->put(route('players.update', $player), [
            'firstname' => 'Amine',
            'position_id' => $mid->id,
        ])->assertRedirect();

        $this->assertSame([$wing->id], $player->fresh()->otherPositions()->pluck('positions.id')->all());
    }

    #[Test]
    public function changing_the_main_position_drops_it_from_the_others_it_previously_sat_in(): void
    {
        $mid = $this->position('MF', 'Milieu');
        $wing = $this->position('WG', 'Ailier');

        $player = Player::create(['membership_id' => '202600007', 'firstname' => 'Amine', 'position_id' => $mid->id]);
        $player->otherPositions()->sync([$wing->id]);

        // The main position moves to WG, but other_position_ids is not sent at
        // all in this request — the old "WG is one of the others" pivot row
        // would otherwise survive and duplicate the abbreviation on the card.
        $this->actingAs($this->admin())->put(route('players.update', $player), [
            'firstname' => 'Amine',
            'position_id' => $wing->id,
        ])->assertRedirect();

        $fresh = $player->fresh();
        $this->assertSame($wing->id, $fresh->position_id);
        $this->assertSame([], $fresh->otherPositions()->pluck('positions.id')->all());
    }

    #[Test]
    public function bulk_editing_the_position_drops_it_from_each_players_other_positions(): void
    {
        $mid = $this->position('MF', 'Milieu');
        $wing = $this->position('WG', 'Ailier');

        $a = Player::create(['membership_id' => '202600008', 'firstname' => 'Amine', 'position_id' => $mid->id]);
        $a->otherPositions()->sync([$wing->id]);
        $b = Player::create(['membership_id' => '202600009', 'firstname' => 'Yanis', 'position_id' => $mid->id]);
        $b->otherPositions()->sync([$wing->id]);

        $this->actingAs($this->admin())->post(route('players.bulkUpdate'), [
            'ids' => [$a->id, $b->id],
            'field' => 'position_id',
            'value' => $wing->id,
        ])->assertRedirect();

        $this->assertSame($wing->id, $a->fresh()->position_id);
        $this->assertSame([], $a->fresh()->otherPositions()->pluck('positions.id')->all());
        $this->assertSame($wing->id, $b->fresh()->position_id);
        $this->assertSame([], $b->fresh()->otherPositions()->pluck('positions.id')->all());
    }

    #[Test]
    public function the_position_filter_still_groups_correctly_alongside_another_and_ed_filter(): void
    {
        $mid = $this->position('MF', 'Milieu');
        $wing = $this->position('WG', 'Ailier');
        $u15 = Category::create(['name' => 'U15']);
        $u17 = Category::create(['name' => 'U17']);

        // Plays WG (as main) and is in U15: matches both filters.
        $match = Player::create(['membership_id' => '202600010', 'firstname' => 'Match', 'position_id' => $wing->id, 'category_id' => $u15->id]);
        // Plays WG too, but is in U17: the orWhereHas/orWhere position branch
        // must stay confined by its own closure, or this bystander would leak
        // into a category_id=U15 AND position_id=WG result.
        Player::create(['membership_id' => '202600011', 'firstname' => 'Bystander', 'position_id' => $wing->id, 'category_id' => $u17->id]);
        // In U15 but plays MF, not WG.
        Player::create(['membership_id' => '202600012', 'firstname' => 'OtherPosition', 'position_id' => $mid->id, 'category_id' => $u15->id]);

        $props = $this->actingAs($this->admin())
            ->get(route('players.index', ['position_id' => $wing->id, 'category_id' => $u15->id]))
            ->assertOk()->viewData('page')['props'];

        $this->assertSame([$match->firstname], collect($props['players']['data'])->pluck('firstname')->all());
    }

    #[Test]
    public function filtering_by_an_other_position_still_counts_the_stats_chart_under_the_main_position(): void
    {
        $mid = $this->position('MF', 'Milieu');
        $wing = $this->position('WG', 'Ailier');

        $player = Player::create(['membership_id' => '202600013', 'firstname' => 'Amine', 'position_id' => $mid->id]);
        $player->otherPositions()->sync([$wing->id]);

        // Filtering by WG (an "other", not the main position) still finds the
        // player via the list filter; the position chart groups by the raw
        // players.position_id column, so the matched player is counted under
        // their MAIN position (MF), not under WG. This is the deliberate
        // behavior documented in the task report: the chart is a main-position
        // breakdown of whoever the current filters select, not a recount of
        // "who plays WG".
        $props = $this->actingAs($this->admin())
            ->get(route('players.index', ['position_id' => $wing->id]))
            ->assertOk()->viewData('page')['props'];

        $this->assertSame(1, collect($props['positionStats'])->sum('count'));
        $this->assertSame(['Milieu'], collect($props['positionStats'])->pluck('name')->all());
    }
}
