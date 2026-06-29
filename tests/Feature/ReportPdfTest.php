<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Player;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReportPdfTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    #[Test]
    public function it_generates_a_transaction_receipt_pdf(): void
    {
        $transaction = Transaction::create([
            'amount' => 2000,
            'transaction_date' => now(),
            'transaction_type' => 'income',
            'category' => 'subscription',
            'payment_method' => 'cash',
            'status' => 'Paid',
            'fiscal_year' => now()->year,
        ]);

        $response = $this->actingAs($this->admin())->get(route('transactions.receipt', $transaction));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    #[Test]
    public function it_generates_a_member_card_pdf(): void
    {
        $category = Category::create(['name' => 'Senior']);
        $player = Player::create([
            'membership_id' => '2024000001',
            'firstname' => 'Yacine',
            'lastname' => 'Saidi',
            'category_id' => $category->id,
            'join_year' => 2024,
        ]);

        $response = $this->actingAs($this->admin())->get(route('players.card', $player));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    #[Test]
    public function it_generates_a_financial_summary_pdf(): void
    {
        Transaction::create([
            'amount' => 1500,
            'transaction_date' => now(),
            'transaction_type' => 'income',
            'category' => 'donation',
            'status' => 'Paid',
            'fiscal_year' => now()->year,
        ]);

        $response = $this->actingAs($this->admin())->get(route('reports.financial', ['year' => now()->year]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }
}
