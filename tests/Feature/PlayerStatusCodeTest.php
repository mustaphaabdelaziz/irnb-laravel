<?php

namespace Tests\Feature;

use App\Models\PlayerStatus;
use App\Services\Player\RegisterPlayerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerStatusCodeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_built_in_statuses_carry_stable_codes(): void
    {
        $this->assertSame(
            ['registered', 'retired', 'paused', 'left', 'unclear', 'sanctioned'],
            PlayerStatus::orderBy('sort_order')->pluck('code')->all(),
        );
    }

    #[Test]
    public function a_new_player_defaults_to_registered_even_after_the_status_is_renamed(): void
    {
        $registered = PlayerStatus::where('code', 'registered')->firstOrFail();
        $registered->update(['name' => 'مسجل', 'name_ar' => 'مسجل']);

        $player = app(RegisterPlayerService::class)->handle([
            'firstname' => 'Amine',
            'join_year' => 2026,
        ]);

        $this->assertSame($registered->id, $player->status_id);
    }
}
