<?php

namespace Tests\Feature\Competitions;

use App\Models\GameFormat;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class GameFormatSettingsTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    private function payload(array $extra = []): array
    {
        return $extra + [
            'branch_id' => $this->sportBranch()->id, 'sport' => 'football', 'name' => 'Football 7',
            'players_on_field' => 7, 'max_bench' => 5, 'max_called_up' => 12,
            'periods_count' => 2, 'period_minutes' => 25, 'period_label' => 'half', 'scoring' => 'goals',
            'has_extra_time' => false, 'has_shootout' => false, 'active' => true,
            'sanction_types' => [['key' => 'yellow', 'ends_play' => false, 'counts_as' => 'yellow']],
        ];
    }

    #[Test]
    public function the_page_lists_presets_with_their_formations(): void
    {
        $this->actingAs($this->admin())->get(route('game-formats.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Settings/GameFormats')
                ->has('formats', 8)->has('sports')->has('branches'));
    }

    #[Test]
    public function a_format_is_created_and_validated(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('game-formats.store'), $this->payload(['max_called_up' => 5]))
            ->assertSessionHasErrors('max_called_up');
        $this->actingAs($admin)->post(route('game-formats.store'), $this->payload(['scoring' => 'sets']))
            ->assertSessionHasErrors('sets_to_win');

        $this->actingAs($admin)->post(route('game-formats.store'), $this->payload())
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'flash.game_format_created');
        $format = GameFormat::where('name', 'Football 7')->sole();
        $this->assertFalse($format->is_preset);
        $this->assertSame('yellow', $format->sanction_types[0]['key']);
    }

    #[Test]
    public function a_preset_can_be_edited_but_a_format_in_use_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $preset = GameFormat::where('name', 'Football 11')->sole();
        $this->actingAs($admin)->put(route('game-formats.update', $preset), $this->payload(['name' => 'Football 11', 'players_on_field' => 11, 'max_called_up' => 18]))
            ->assertSessionHas('success', 'flash.game_format_updated');
        $this->assertSame(18, $preset->fresh()->max_called_up);

        $team = $this->team();
        $this->actingAs($admin)->delete(route('game-formats.destroy', $team->defaultFormat))
            ->assertSessionHas('error', 'flash.game_format_in_use');
        $this->actingAs($admin)->delete(route('game-formats.destroy', $preset))
            ->assertSessionHas('success', 'flash.game_format_deleted');
        $this->assertModelMissing($preset);
    }

    #[Test]
    public function positions_belong_to_a_sport(): void
    {
        $admin = $this->admin();
        $football = $this->sportBranch('Football');
        $handball = $this->sportBranch('Handball');

        $this->actingAs($admin)->post(route('positions.store'), ['name' => 'Goalkeeper', 'abbreviation' => 'gk', 'branch_id' => $football->id])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('positions.store'), ['name' => 'Goalkeeper', 'abbreviation' => 'GK', 'branch_id' => $handball->id])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('positions.store'), ['name' => 'Keeper', 'abbreviation' => 'GK', 'branch_id' => $football->id])
            ->assertSessionHasErrors('abbreviation');

        $this->assertSame(2, Position::where('abbreviation', 'GK')->count());
        $this->actingAs($admin)->get(route('positions.index'))
            ->assertInertia(fn (Assert $page) => $page->component('Settings/Positions')->has('branches', 2));
    }
}
