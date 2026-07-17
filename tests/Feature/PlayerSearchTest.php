<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerSearchTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'privileges' => ['admin'],
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // fullname renders as: "Khelifi Sami بن Mohamed Ahmed"
        Player::create([
            'membership_id' => '9000000001',
            'firstname' => 'Sami',
            'lastname' => 'Khelifi',
            'father' => 'Mohamed',
            'grandfather' => 'Ahmed',
            'is_student' => true,
            'outstanding_debt' => 0,
        ]);

        Player::create([
            'membership_id' => '9000000002',
            'firstname' => 'Yacine',
            'lastname' => 'Boudiaf',
            'father' => 'Karim',
            'is_student' => true,
            'outstanding_debt' => 0,
        ]);
    }

    private function search(string $term): array
    {
        $names = [];
        $this->actingAs($this->admin())
            ->get(route('players.index', ['search' => $term]))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$names) {
                foreach ($page->toArray()['props']['players']['data'] as $p) {
                    $names[] = $p['firstname'].' '.$p['lastname'];
                }
            });

        return $names;
    }

    #[Test]
    public function it_finds_by_lastname_alone(): void
    {
        $this->assertSame(['Sami Khelifi'], $this->search('Khelifi'));
    }

    #[Test]
    public function it_finds_by_firstname_alone(): void
    {
        $this->assertSame(['Sami Khelifi'], $this->search('Sami'));
    }

    #[Test]
    public function it_finds_by_fullname_in_lastname_firstname_order(): void
    {
        $this->assertSame(['Sami Khelifi'], $this->search('Khelifi Sami'));
    }

    #[Test]
    public function it_finds_by_fullname_in_firstname_lastname_order(): void
    {
        $this->assertSame(['Sami Khelifi'], $this->search('Sami Khelifi'));
    }

    #[Test]
    public function it_finds_by_name_plus_father_without_the_connector(): void
    {
        $this->assertSame(['Sami Khelifi'], $this->search('Sami Mohamed'));
    }

    #[Test]
    public function it_finds_by_full_name_including_the_ben_connector(): void
    {
        $this->assertSame(['Sami Khelifi'], $this->search('Khelifi Sami بن Mohamed'));
    }

    #[Test]
    public function it_finds_by_father_alone(): void
    {
        $this->assertSame(['Sami Khelifi'], $this->search('Mohamed'));
    }

    #[Test]
    public function it_finds_by_grandfather(): void
    {
        $this->assertSame(['Sami Khelifi'], $this->search('Ahmed'));
    }

    #[Test]
    public function it_still_finds_by_membership_id(): void
    {
        $this->assertSame(['Yacine Boudiaf'], $this->search('9000000002'));
    }

    #[Test]
    public function it_returns_nothing_for_a_non_matching_term(): void
    {
        $this->assertSame([], $this->search('Zzzz'));
    }

    #[Test]
    public function it_does_not_match_names_split_across_different_people(): void
    {
        // "Khelifi" (player 1) + "Karim" (player 2's father) must match nobody.
        $this->assertSame([], $this->search('Khelifi Karim'));
    }
}
