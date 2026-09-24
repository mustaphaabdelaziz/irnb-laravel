<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Player;
use App\Models\Role;
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
        return User::factory()->admin()->create(['email_verified_at' => now()]);
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

    #[Test]
    public function it_generates_an_academic_report_pdf_for_a_student_with_a_20_year_and_a_10_year(): void
    {
        $player = Player::create([
            'membership_id' => '2024000077',
            'firstname' => 'Amel',
            'lastname' => 'Haddad',
            'is_student' => true,
        ]);

        $secondary = $player->academicYears()->create([
            'academic_year' => 2025,
            'education_level' => 'secondary',
            'institution' => 'Lycée Ibn Khaldoun',
            'field_of_study' => 'Terminale',
        ]);
        $secondary->records()->create(['period' => 'T1', 'gpa' => 12.75, 'certificate' => 'encouragement']);
        $secondary->records()->create(['period' => 'T2', 'gpa' => 15.50]);

        $primary = $player->academicYears()->create([
            'academic_year' => 2024,
            'education_level' => 'primary',
            'institution' => 'École El Fath',
        ]);
        $primary->records()->create(['period' => 'T1', 'gpa' => 4.5]);
        $primary->records()->create(['period' => 'T2', 'gpa' => 8, 'certificate' => 'excellence']);
        $primary->records()->create(['period' => 'T3', 'gpa' => 7]);

        $response = $this->actingAs($this->admin())->get(route('players.academic-report', $player));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    #[Test]
    public function academic_report_is_not_found_for_a_worker(): void
    {
        $player = Player::create(['membership_id' => '2024000078', 'firstname' => 'W', 'lastname' => 'K', 'is_student' => false]);

        $this->actingAs($this->admin())->get(route('players.academic-report', $player))->assertNotFound();
    }

    #[Test]
    public function academic_report_needs_only_players_view(): void
    {
        $viewer = User::factory()->create([
            'privileges' => ['user'], 'approved' => true, 'email_verified_at' => now(),
            'role_id' => Role::factory()->create(['permissions' => ['players' => ['view']]])->id,
        ]);
        $player = Player::create(['membership_id' => '2024000079', 'firstname' => 'V', 'lastname' => 'K', 'is_student' => true]);

        $this->actingAs($viewer)->get(route('players.academic-report', $player))->assertOk();
    }

    #[Test]
    public function academic_report_renders_for_a_student_with_no_years_yet(): void
    {
        $player = Player::create(['membership_id' => '2024000080', 'firstname' => 'N', 'lastname' => 'Y', 'is_student' => true]);

        $response = $this->actingAs($this->admin())->get(route('players.academic-report', $player));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }
}
