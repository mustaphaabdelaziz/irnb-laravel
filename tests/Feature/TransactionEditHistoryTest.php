<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\FinanceCategory;
use App\Models\FiscalYear;
use App\Models\Transaction;
use App\Models\TransactionRevision;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Support\FiscalPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionEditHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        $this->admin = User::factory()->admin()->create(['email_verified_at' => now(), 'preferred_lng' => 'en']);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        $category = FinanceCategory::updateOrCreate(['type' => 'income', 'name' => 'Donation'], ['is_active' => true]);

        return array_merge([
            'title' => 'Sponsor gift',
            'transaction_type' => 'income',
            'finance_category_id' => $category->id,
            'amount' => 3000,
            'transaction_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'status' => 'Paid',
        ], $overrides);
    }

    private function stored(): Transaction
    {
        $this->actingAs($this->admin)->post(route('transactions.store'), $this->payload())->assertSessionHasNoErrors();

        return Transaction::latest('id')->firstOrFail();
    }

    private function edits(): int
    {
        return ActivityLog::where('action', ActivityAction::TRANSACTION_UPDATED)->count();
    }

    #[Test]
    public function an_edit_is_logged_once_with_what_changed(): void
    {
        $tx = $this->stored();

        $this->actingAs($this->admin)
            ->put(route('transactions.update', $tx), $this->payload(['amount' => 4500]))
            ->assertRedirect(route('transactions.show', $tx));

        $log = ActivityLog::where('action', ActivityAction::TRANSACTION_UPDATED)->sole();
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame($tx->id, $log->subject_id);
        $this->assertNull($log->properties);

        $revision = TransactionRevision::sole();
        $this->assertSame($tx->id, $revision->transaction_id);
        $this->assertSame($log->id, $revision->activity_log_id);
        $this->assertEquals(['amount' => ['old' => 3000, 'new' => 4500]], $revision->diff);
    }

    #[Test]
    public function saving_the_form_unchanged_records_nothing(): void
    {
        $tx = $this->stored();

        $this->actingAs($this->admin)
            ->put(route('transactions.update', $tx), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $this->edits());
        $this->assertSame(0, TransactionRevision::count());
    }

    #[Test]
    public function a_title_edit_keeps_the_old_wording_out_of_the_activity_log(): void
    {
        $tx = $this->stored();

        $this->actingAs($this->admin)
            ->put(route('transactions.update', $tx), $this->payload(['title' => 'Sponsor gift — Café du Port']))
            ->assertSessionHasNoErrors();

        $this->assertEquals(
            ['title' => ['old' => 'Sponsor gift', 'new' => 'Sponsor gift — Café du Port']],
            TransactionRevision::sole()->diff,
        );
        $this->assertNull(ActivityLog::where('action', ActivityAction::TRANSACTION_UPDATED)->sole()->properties);
    }

    #[Test]
    public function a_category_change_is_stored_with_both_names(): void
    {
        $tx = $this->stored();
        $other = FinanceCategory::create(['type' => 'income', 'name' => 'Trace Sponsoring', 'is_active' => true]);

        $this->actingAs($this->admin)
            ->put(route('transactions.update', $tx), $this->payload(['finance_category_id' => $other->id]))
            ->assertSessionHasNoErrors();

        $change = TransactionRevision::sole()->diff['finance_category_id'];
        $this->assertSame($other->id, $change['new']);
        $this->assertSame($other->fresh()->localized_name, $change['new_label']);
        $this->assertNotNull($change['old_label']);
    }

    #[Test]
    public function a_new_receipt_file_is_recorded_without_its_name(): void
    {
        $tx = $this->stored();

        $this->actingAs($this->admin)
            ->put(route('transactions.update', $tx), $this->payload([
                'receipt' => UploadedFile::fake()->create('facture-secrete.pdf', 100, 'application/pdf'),
            ]))
            ->assertSessionHasNoErrors();

        $revision = TransactionRevision::sole();
        $this->assertSame(['receipt' => ['replaced' => true]], $revision->diff);
        $this->assertStringNotContainsString('facture', (string) $revision->getRawOriginal('diff'));
    }

    #[Test]
    public function an_edit_refused_by_a_closed_season_records_nothing(): void
    {
        $tx = $this->stored();
        FiscalYear::where('year', FiscalPeriod::keyForDate(now()))->update(['status' => 'closed']);

        $this->actingAs($this->admin)->from(route('transactions.edit', $tx))
            ->put(route('transactions.update', $tx), $this->payload(['amount' => 9999]))
            ->assertSessionHas('error');

        $this->assertSame(3000.0, (float) $tx->fresh()->amount);
        $this->assertSame(0, $this->edits());
        $this->assertSame(0, TransactionRevision::count());
    }
}
