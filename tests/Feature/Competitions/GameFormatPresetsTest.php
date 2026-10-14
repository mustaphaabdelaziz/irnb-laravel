<?php

namespace Tests\Feature\Competitions;

use App\Models\Branch;
use App\Models\GameFormat;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GameFormatPresetsTest extends TestCase
{
    use RefreshDatabase;

    private function runPresets(): void
    {
        (require database_path('migrations/2026_10_15_100002_seed_game_format_presets.php'))->up();
    }

    #[Test]
    public function presets_exist_with_formations_whose_slots_match_the_players_on_field(): void
    {
        $this->assertSame(8, GameFormat::where('is_preset', true)->count());

        foreach (GameFormat::with('formations')->get() as $format) {
            $this->assertNotEmpty($format->formations, $format->name);
            foreach ($format->formations as $formation) {
                $this->assertCount($format->players_on_field, $formation->slots, "{$format->name} {$formation->name}");
            }
        }
        $this->assertSame(['yellow', 'red'], array_column(GameFormat::where('name', 'Football 11')->sole()->sanction_types, 'key'));
    }

    #[Test]
    public function presets_link_to_the_branch_of_their_sport_and_running_again_adds_nothing(): void
    {
        GameFormat::query()->delete();
        $football = Branch::create(['name' => 'Football']);
        $handball = Branch::create(['name' => 'كرة اليد', 'name_en' => 'Handball']);
        $position = Position::create(['name' => 'Striker', 'abbreviation' => 'ST']);

        $this->runPresets();
        $this->runPresets();

        $this->assertSame(8, GameFormat::count());
        $this->assertSame($football->id, GameFormat::where('name', 'Football 11')->value('branch_id'));
        $this->assertSame($football->id, GameFormat::where('name', 'Futsal')->value('branch_id'));
        $this->assertSame($handball->id, GameFormat::where('name', 'Handball')->value('branch_id'));
        $this->assertNull(GameFormat::where('name', 'Volleyball')->value('branch_id'));
        $this->assertSame($football->id, $position->fresh()->branch_id);
    }

    #[Test]
    public function the_volleyball_preset_finds_a_branch_named_with_the_arabic_article(): void
    {
        GameFormat::query()->delete();
        $volleyball = Branch::create(['name' => 'الكرة الطائرة']);

        $this->runPresets();

        $this->assertSame($volleyball->id, GameFormat::where('name', 'Volleyball')->value('branch_id'));
    }
}
