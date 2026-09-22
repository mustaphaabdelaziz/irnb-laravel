<?php

namespace Tests\Feature;

use App\Models\FinanceCategory;
use App\Models\Player;
use App\Models\Transaction;
use App\Models\User;
use App\Support\TransactionTitle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionTitleFlowTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now(), 'preferred_lng' => 'en']);
    }

    private function player(): Player
    {
        return Player::create(['membership_id' => '202600017', 'firstname' => 'Amine', 'lastname' => 'Benali', 'join_year' => 2026]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        $category = FinanceCategory::updateOrCreate(['type' => 'income', 'name' => 'Donation'], ['is_active' => true]);

        return array_merge([
            'title' => 'Sponsor gift — Café du Port',
            'transaction_type' => 'income',
            'finance_category_id' => $category->id,
            'amount' => 3000,
            'transaction_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'status' => 'Paid',
        ], $overrides);
    }

    #[Test]
    public function a_transaction_cannot_be_created_without_a_title(): void
    {
        $this->actingAs($this->admin())
            ->post(route('transactions.store'), $this->payload(['title' => '']))
            ->assertSessionHasErrors('title');

        $this->assertSame(0, Transaction::count());
    }

    #[Test]
    public function the_title_is_stored_and_listed(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('transactions.store'), $this->payload())->assertRedirect();

        $this->assertSame('Sponsor gift — Café du Port', Transaction::firstOrFail()->title);

        $props = $this->actingAs($admin)->get(route('transactions.index'))->assertOk()->viewData('page')['props'];
        $this->assertSame('Sponsor gift — Café du Port', $props['transactions']['data'][0]['display_title']);
        $this->assertNull($props['transactions']['data'][0]['player_summary']);
    }

    #[Test]
    public function an_unknown_player_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post(route('transactions.store'), $this->payload([
                'related_entity_type' => 'Player',
                'related_entity_id' => 999999,
            ]))
            ->assertSessionHasErrors('related_entity_id');
    }

    #[Test]
    public function a_linked_player_is_listed_with_the_transaction(): void
    {
        $admin = $this->admin();
        $player = $this->player();

        $this->actingAs($admin)->post(route('transactions.store'), $this->payload([
            'related_entity_type' => 'Player',
            'related_entity_id' => $player->id,
        ]))->assertRedirect();

        $props = $this->actingAs($admin)->get(route('transactions.index'))->viewData('page')['props'];
        $this->assertSame('202600017', $props['transactions']['data'][0]['player_summary']['membership_id']);
    }

    #[Test]
    public function editing_an_untitled_transaction_prefills_the_generated_label(): void
    {
        $tx = Transaction::create([
            'amount' => 200, 'transaction_type' => 'income', 'category' => 'donation', 'status' => 'Paid',
        ]);

        $props = $this->actingAs($this->admin())->get(route('transactions.edit', $tx))
            ->assertOk()->viewData('page')['props'];

        app()->setLocale('en');
        $this->assertSame(
            TransactionTitle::for($tx->fresh()->load(TransactionTitle::RELATIONS)),
            $props['transaction']['display_title'],
        );
        $this->assertNotSame('', $props['transaction']['display_title']);
    }

    #[Test]
    public function updating_requires_the_title_too(): void
    {
        $tx = Transaction::create([
            'amount' => 200, 'transaction_type' => 'income', 'category' => 'donation', 'status' => 'Paid',
        ]);

        $this->actingAs($this->admin())
            ->put(route('transactions.update', $tx), $this->payload(['title' => '']))
            ->assertSessionHasErrors('title');
    }

    #[Test]
    public function the_player_page_labels_payments_by_title(): void
    {
        $admin = $this->admin();
        $player = $this->player();

        $this->actingAs($admin)->post(route('players.transactions.store', $player), [
            'category' => 'donation',
            'amount' => 500,
            'payment_method' => 'cash',
        ])->assertRedirect();

        $props = $this->actingAs($admin)->get(route('players.show', $player))->assertOk()->viewData('page')['props'];
        $this->assertStringContainsString('Amine Benali', $props['transactions'][0]['display_title']);
    }

    #[Test]
    public function the_import_reads_an_optional_title_column(): void
    {
        $csv = "\xEF\xBB\xBF".implode("\n", [
            'Date,Type,Category,Amount,Status,Payment Method,Description,Title',
            '2026-01-15,income,donation,1000,Paid,cash,,Hall rent refund',
            '2026-01-16,income,donation,400,Paid,cash,,',
        ])."\n";

        $this->actingAs($this->admin())->post(route('transactions.import.store'), [
            'file' => UploadedFile::fake()->createWithContent('transactions.csv', $csv),
        ])->assertRedirect();

        $this->assertSame(['Hall rent refund', null], Transaction::orderBy('id')->pluck('title')->all());
    }
}
