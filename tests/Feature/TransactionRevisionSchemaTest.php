<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Transaction;
use App\Models\TransactionRevision;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivityRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionRevisionSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function tx(): Transaction
    {
        return Transaction::create([
            'amount' => 200, 'transaction_type' => 'income', 'category' => 'donation', 'status' => 'Paid',
        ]);
    }

    #[Test]
    public function a_revision_belongs_to_its_transaction_and_its_log(): void
    {
        $tx = $this->tx();
        $log = ActivityRecorder::record(User::factory()->admin()->create(), ActivityAction::TRANSACTION_UPDATED, $tx);

        $revision = TransactionRevision::create([
            'transaction_id' => $tx->id,
            'activity_log_id' => $log->id,
            'diff' => ['amount' => ['old' => 200, 'new' => 300]],
        ]);

        $this->assertTrue($tx->revisions()->sole()->is($revision));
        $this->assertTrue($log->fresh()->revision->is($revision));
        $this->assertEquals(['amount' => ['old' => 200, 'new' => 300]], $revision->fresh()->diff);
        $this->assertNotNull($revision->fresh()->created_at);
    }

    #[Test]
    public function revisions_are_deleted_with_their_transaction(): void
    {
        $tx = $this->tx();
        TransactionRevision::create(['transaction_id' => $tx->id, 'diff' => ['title' => ['old' => 'a', 'new' => 'b']]]);

        $tx->delete();

        $this->assertSame(0, TransactionRevision::count());
    }

    #[Test]
    public function a_revision_outlives_its_activity_log(): void
    {
        $tx = $this->tx();
        $log = ActivityRecorder::record(null, ActivityAction::TRANSACTION_UPDATED, $tx);
        $revision = TransactionRevision::create(['transaction_id' => $tx->id, 'activity_log_id' => $log->id, 'diff' => []]);

        ActivityLog::whereKey($log->id)->delete();

        $this->assertNull($revision->fresh()->activity_log_id);
    }

    #[Test]
    public function deleting_the_original_unlinks_its_replacement(): void
    {
        $original = $this->tx();
        $replacement = $this->tx();
        $replacement->forceFill(['replaces_transaction_id' => $original->id])->save();

        $this->assertTrue($replacement->fresh()->replaces->is($original));
        $this->assertTrue($original->replacements()->sole()->is($replacement));

        $original->delete();

        $this->assertNull($replacement->fresh()->replaces_transaction_id);
    }
}
