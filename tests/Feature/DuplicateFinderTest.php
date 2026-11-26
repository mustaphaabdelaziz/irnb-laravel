<?php

namespace Tests\Feature;

use App\Models\PlayerDuplicateDecision;
use App\Services\Player\Duplicates\DuplicateFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsDuplicatePlayers;
use Tests\TestCase;

class DuplicateFinderTest extends TestCase
{
    use BuildsDuplicatePlayers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    #[Test]
    public function it_lists_scored_pairs_strongest_first_and_skips_strangers(): void
    {
        $a = $this->player(['firstname' => 'فاطمة', 'lastname' => 'بن علي', 'birthdate' => '2012-03-04', 'phones' => ['0542039525'], 'gender' => 'Female']);
        $b = $this->player(['firstname' => 'فاطمه', 'lastname' => 'بن علي', 'birthdate' => '2012-03-04', 'phones' => ['+213542039525'], 'gender' => 'Female']);
        $c = $this->player(['firstname' => 'Karim', 'lastname' => 'Haddad', 'birthdate' => '2011-01-01']);
        $d = $this->player(['firstname' => 'Karim', 'lastname' => 'Haddad', 'birthdate' => '2009-05-05']);
        $this->player(['firstname' => 'Nobody', 'lastname' => 'Else', 'birthdate' => '2001-01-01']);

        $pairs = app(DuplicateFinder::class)->pending();

        $this->assertCount(2, $pairs);
        $this->assertSame([$a->id, $b->id, 200], [$pairs[0]['a'], $pairs[0]['b'], $pairs[0]['score']]);
        $this->assertSame(['same_name', 'same_birthdate', 'same_phone'], $pairs[0]['reasons']);
        $this->assertSame([$c->id, $d->id, 80], [$pairs[1]['a'], $pairs[1]['b'], $pairs[1]['score']]);
    }

    #[Test]
    public function criteria_and_min_score_narrow_the_list(): void
    {
        $this->player(['firstname' => 'Karim', 'lastname' => 'Haddad', 'birthdate' => '2011-01-01']);
        $this->player(['firstname' => 'Karim', 'lastname' => 'Haddad', 'birthdate' => '2011-01-01']);
        $this->player(['firstname' => 'Sami', 'lastname' => 'Amrani']);
        $this->player(['firstname' => 'Sami', 'lastname' => 'Amrani']);

        $finder = app(DuplicateFinder::class);

        $this->assertCount(2, $finder->pending());
        $this->assertCount(1, $finder->pending(['name', 'birthdate']));
        $this->assertCount(1, $finder->pending([], 100));
        $this->assertCount(0, $finder->pending(['phone']));
    }

    #[Test]
    public function decided_pairs_leave_the_pending_list(): void
    {
        $a = $this->player(['firstname' => 'Sami', 'lastname' => 'Amrani']);
        $b = $this->player(['firstname' => 'Sami', 'lastname' => 'Amrani']);
        PlayerDuplicateDecision::create([
            'player_a_id' => $a->id, 'player_b_id' => $b->id, 'status' => PlayerDuplicateDecision::DISMISSED,
            'player_a_snapshot' => [], 'player_b_snapshot' => [], 'decided_at' => now(),
        ]);

        $this->assertCount(0, app(DuplicateFinder::class)->pending());
    }

    #[Test]
    public function score_pair_scores_one_pair_now(): void
    {
        $a = $this->player(['firstname' => 'Sami', 'lastname' => 'Amrani', 'birthdate' => '2010-02-02']);
        $b = $this->player(['firstname' => 'Sami', 'lastname' => 'Amrani', 'birthdate' => '2010-02-02']);

        $this->assertSame(['score' => 140, 'reasons' => ['same_name', 'same_birthdate']], app(DuplicateFinder::class)->scorePair($a->id, $b->id));
    }
}
