<?php

namespace Tests\Feature;

use App\Models\FinanceCategory;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Transaction;
use App\Support\TransactionTitle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionTitleTest extends TestCase
{
    use RefreshDatabase;

    private function player(): Player
    {
        return Player::create(['membership_id' => '202600017', 'firstname' => 'Amine', 'lastname' => 'Benali', 'join_year' => 2026]);
    }

    private function subscriptionPayment(): Transaction
    {
        FinanceCategory::updateOrCreate(
            ['type' => 'income', 'name' => 'Subscription'],
            ['name_fr' => 'Cotisation', 'name_ar' => 'اشتراك', 'is_active' => true],
        );
        $player = $this->player();
        $line = PlayerSubscription::create([
            'player_id' => $player->id, 'label' => 'Saison', 'year' => 2026,
            'amount_owed' => 1000, 'amount_paid' => 0,
        ]);

        return Transaction::create([
            'amount' => 500, 'transaction_type' => 'income', 'category' => 'subscription', 'status' => 'Partial',
            'related_entity_type' => 'Player', 'related_entity_id' => $player->id,
            'player_subscription_id' => $line->id,
        ])->load(TransactionTitle::RELATIONS);
    }

    #[Test]
    public function a_typed_title_wins(): void
    {
        $tx = Transaction::create([
            'title' => 'Hall rent — March', 'amount' => 8000, 'transaction_type' => 'expense',
            'category' => 'rent', 'status' => 'Paid',
        ])->load(TransactionTitle::RELATIONS);

        $this->assertSame('Hall rent — March', TransactionTitle::for($tx));
    }

    #[Test]
    public function an_untitled_payment_is_labelled_in_the_viewers_language(): void
    {
        $tx = $this->subscriptionPayment();

        app()->setLocale('fr');
        $this->assertSame('Cotisation · Saison 2026 · Amine Benali', TransactionTitle::for($tx));

        // Not stored: the same row reads in Arabic after a language switch.
        app()->setLocale('ar');
        $this->assertSame('اشتراك · Saison 2026 · Amine Benali', TransactionTitle::for($tx));
    }

    #[Test]
    public function decorate_exposes_title_and_player_without_leaking_helper_relations(): void
    {
        app()->setLocale('fr');
        $array = TransactionTitle::decorate($this->subscriptionPayment())->toArray();

        $this->assertSame('Cotisation · Saison 2026 · Amine Benali', $array['display_title']);
        $this->assertSame('Amine Benali', $array['player_summary']['name']);
        $this->assertSame('202600017', $array['player_summary']['membership_id']);
        $this->assertArrayNotHasKey('related_player', $array);
        $this->assertArrayNotHasKey('player_subscription', $array);
    }

    #[Test]
    public function a_transaction_without_a_player_has_no_player_summary(): void
    {
        app()->setLocale('en');
        $tx = Transaction::create([
            'amount' => 50, 'transaction_type' => 'expense', 'category' => 'supplies', 'status' => 'Paid',
        ])->load(TransactionTitle::RELATIONS);

        $array = TransactionTitle::decorate($tx)->toArray();

        $this->assertNull($array['player_summary']);
        $this->assertSame($tx->financeCategory->localized_name, $array['display_title']);
    }

    #[Test]
    public function a_player_without_a_last_name_is_never_called_null(): void
    {
        $player = Player::create(['membership_id' => '202600019', 'firstname' => 'Yanis']);

        $this->assertSame('Yanis', $player->short_name);
    }
}
