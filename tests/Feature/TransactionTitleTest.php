<?php

namespace Tests\Feature;

use App\Models\FinanceCategory;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Subscription;
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

    #[Test]
    public function a_category_slug_that_collides_with_an_unrelated_ui_key_is_headlined_not_mistranslated(): void
    {
        // 'paid' is a UI key for payment status ("مدفوع" in Arabic); a finance
        // category slug that happens to collide with it must not borrow that
        // unrelated label. Deliberately not eager-loading financeCategory, so
        // categoryLabel() falls through to the free-text slug branch.
        $tx = Transaction::create([
            'amount' => 100, 'transaction_type' => 'expense', 'category' => 'paid', 'status' => 'Paid',
        ]);

        app()->setLocale('ar');
        $this->assertSame('Paid', TransactionTitle::for($tx));
    }

    #[Test]
    public function a_line_without_its_own_label_falls_back_to_the_subscriptions_name(): void
    {
        FinanceCategory::updateOrCreate(
            ['type' => 'income', 'name' => 'Subscription'],
            ['name_fr' => 'Cotisation', 'name_ar' => 'اشتراك', 'is_active' => true],
        );
        $player = $this->player();
        $subscription = Subscription::create(['name' => 'Hiver', 'year' => 2026]);
        $line = PlayerSubscription::create([
            'player_id' => $player->id, 'subscription_id' => $subscription->id, 'year' => 2026,
            'amount_owed' => 1000, 'amount_paid' => 0,
        ]);

        $tx = Transaction::create([
            'amount' => 500, 'transaction_type' => 'income', 'category' => 'subscription', 'status' => 'Partial',
            'related_entity_type' => 'Player', 'related_entity_id' => $player->id,
            'player_subscription_id' => $line->id,
        ])->load(TransactionTitle::RELATIONS);

        app()->setLocale('fr');
        $this->assertSame('Cotisation · Hiver 2026 · Amine Benali', TransactionTitle::for($tx));
    }
}
