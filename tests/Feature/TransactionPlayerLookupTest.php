<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Player;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionPlayerLookupTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function paymentFor(?Player $player, array $attributes = []): Transaction
    {
        return Transaction::create(array_merge([
            'amount' => 100, 'transaction_type' => 'income', 'category' => 'donation', 'status' => 'Paid',
            'related_entity_type' => $player ? 'Player' : null,
            'related_entity_id' => $player?->id,
        ], $attributes));
    }

    /** @return list<int> */
    private function searchIds(string $search): array
    {
        $props = $this->actingAs($this->admin())->get(route('transactions.index', ['search' => $search]))
            ->assertOk()->viewData('page')['props'];

        return collect($props['transactions']['data'])->pluck('id')->sort()->values()->all();
    }

    #[Test]
    public function search_matches_the_title(): void
    {
        $hall = $this->paymentFor(null, ['title' => 'Hall rent March']);
        $this->paymentFor(null, ['title' => 'Printer ink']);

        $this->assertSame([$hall->id], $this->searchIds('hall rent'));
    }

    #[Test]
    public function search_finds_a_players_payments_by_name_in_any_order_or_membership_id(): void
    {
        $amine = Player::create(['membership_id' => '202600017', 'firstname' => 'Amine', 'lastname' => 'Benali']);
        $other = Player::create(['membership_id' => '202600018', 'firstname' => 'Yanis', 'lastname' => 'Kaci']);
        $mine = $this->paymentFor($amine);
        $this->paymentFor($other);

        $this->assertSame([$mine->id], $this->searchIds('benali amine'));
        $this->assertSame([$mine->id], $this->searchIds('202600017'));
    }

    #[Test]
    public function the_form_tells_two_players_with_the_same_name_apart(): void
    {
        $cadets = Category::create(['name' => 'Cadets']);
        $juniors = Category::create(['name' => 'Juniors']);
        Player::create(['membership_id' => '202600017', 'firstname' => 'Amine', 'lastname' => 'Benali', 'category_id' => $cadets->id, 'birthdate' => '2010-03-01']);
        Player::create(['membership_id' => '202600018', 'firstname' => 'Amine', 'lastname' => 'Benali', 'category_id' => $juniors->id, 'birthdate' => '2008-07-15']);

        $players = collect($this->actingAs($this->admin())->get(route('transactions.create'))
            ->assertOk()->viewData('page')['props']['players'])->keyBy('membership_id');

        $this->assertSame('Amine Benali', $players['202600017']['name']);
        $this->assertSame('Cadets', $players['202600017']['category']);
        $this->assertSame(2010, $players['202600017']['birth_year']);
        $this->assertSame('Juniors', $players['202600018']['category']);
        $this->assertSame(2008, $players['202600018']['birth_year']);
    }

    #[Test]
    public function a_player_without_a_last_name_is_labelled_by_first_name_only(): void
    {
        Player::create(['membership_id' => '202600019', 'firstname' => 'Yanis']);

        $players = $this->actingAs($this->admin())->get(route('transactions.create'))->viewData('page')['props']['players'];

        $this->assertSame('Yanis', $players[0]['name']);
    }

    #[Test]
    public function editing_keeps_an_archived_player_selectable(): void
    {
        $archived = Player::create(['membership_id' => '202600020', 'firstname' => 'Sami', 'lastname' => 'Z', 'archived' => true]);
        $tx = $this->paymentFor($archived);

        $players = $this->actingAs($this->admin())->get(route('transactions.edit', $tx))->viewData('page')['props']['players'];

        $this->assertContains($archived->id, collect($players)->pluck('id')->all());
    }

    #[Test]
    public function a_search_of_only_the_connector_does_not_return_every_players_payment(): void
    {
        $amine = Player::create(['membership_id' => '202600017', 'firstname' => 'Amine', 'lastname' => 'Benali']);
        $linked = $this->paymentFor($amine);
        $this->paymentFor(null, ['title' => 'Printer ink']);

        // بن alone leaves no usable token: it must not widen the search to every
        // player-linked transaction (today it does, because the id subquery matches everybody).
        $this->assertNotContains($linked->id, $this->searchIds('بن'));
    }
}
