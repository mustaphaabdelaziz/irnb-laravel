<?php

namespace Tests\Feature;

use App\Models\PlayerDuplicateDecision;
use App\Services\Player\Duplicates\PlayerCards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsDuplicatePlayers;
use Tests\TestCase;

class PlayerDuplicateDecisionTest extends TestCase
{
    use BuildsDuplicatePlayers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    #[Test]
    public function ordered_pair_puts_the_smaller_id_first(): void
    {
        $this->assertSame([3, 9], PlayerDuplicateDecision::orderedPair(9, 3));
        $this->assertSame([3, 9], PlayerDuplicateDecision::orderedPair(3, 9));
    }

    #[Test]
    public function cards_show_full_name_branch_ids_and_archived(): void
    {
        $player = $this->player(['firstname' => 'فاطمة', 'lastname' => 'بن علي', 'birthdate' => '2012-03-04', 'archived' => true]);
        $this->registration($player, $this->branch(), ['membership_id' => 'FB-2024-00012']);

        $card = PlayerCards::for([$player->id])[$player->id];

        $this->assertSame($player->id, $card['id']);
        $this->assertSame($player->fullname, $card['name']);
        $this->assertSame(['FB-2024-00012'], $card['membership_ids']);
        $this->assertSame('2012-03-04', $card['birthdate']);
        $this->assertTrue($card['archived']);
    }

    #[Test]
    public function a_card_without_registrations_falls_back_to_the_player_membership_id(): void
    {
        $player = $this->player(['membership_id' => '2024000007']);

        $this->assertSame(['2024000007'], PlayerCards::for([$player->id])[$player->id]['membership_ids']);
    }

    #[Test]
    public function deleting_a_player_blanks_its_side_and_keeps_the_snapshot(): void
    {
        $a = $this->player();
        $b = $this->player();
        $decision = PlayerDuplicateDecision::create([
            'player_a_id' => $a->id, 'player_b_id' => $b->id, 'status' => PlayerDuplicateDecision::MERGED,
            'player_a_snapshot' => PlayerDuplicateDecision::snapshot($a),
            'player_b_snapshot' => PlayerDuplicateDecision::snapshot($b),
            'kept_player_id' => $a->id, 'reasons' => ['same_name'], 'score' => 80, 'decided_at' => now(),
        ]);

        DB::table('players')->where('id', $b->id)->delete();

        $decision->refresh();
        $this->assertNull($decision->player_b_id);
        $this->assertSame($b->fullname, $decision->player_b_snapshot['name']);
        $this->assertSame(['same_name'], $decision->reasons);
    }
}
