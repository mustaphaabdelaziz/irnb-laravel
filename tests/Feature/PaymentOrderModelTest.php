<?php

namespace Tests\Feature;

use App\Models\BoardMember;
use App\Models\PaymentOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Documents\YearlySequence;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PaymentOrderModelTest extends TestCase
{
    use RefreshDatabase;

    private function order(array $attributes = []): PaymentOrder
    {
        return PaymentOrder::create(array_merge([
            'year' => 2026, 'number' => 1, 'status' => PaymentOrder::DRAFT,
            'order_date' => '2026-11-25', 'subject' => 'Ballons', 'amount' => 16050,
        ], $attributes));
    }

    #[Test]
    public function the_sequence_restarts_each_year_and_ignores_legacy_rows(): void
    {
        $this->order(['year' => 2026, 'number' => 1]);
        $this->order(['year' => 2026, 'number' => 2]);
        $this->order(['year' => 2025, 'number' => 9]);
        $this->order(['year' => 2026, 'number' => null, 'is_legacy' => true, 'legacy_reference' => '07/2026']);

        $this->assertSame(3, DB::transaction(fn () => YearlySequence::next('payment_orders', 2026)));
        $this->assertSame(10, DB::transaction(fn () => YearlySequence::next('payment_orders', 2025)));
        $this->assertSame(1, DB::transaction(fn () => YearlySequence::next('payment_orders', 2027)));
    }

    #[Test]
    public function a_number_cannot_be_used_twice_in_a_year(): void
    {
        $this->order(['number' => 4]);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->order(['number' => 4]);
    }

    #[Test]
    public function retrying_runs_the_attempt_again_once_after_a_clash(): void
    {
        $calls = 0;
        $result = YearlySequence::retrying(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                $this->order(['number' => 1]);
                $this->order(['number' => 1]);   // clash
            }

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame(2, $calls);
    }

    #[Test]
    public function the_reference_is_number_slash_year_or_the_legacy_text(): void
    {
        $this->assertSame('03/2026', $this->order(['number' => 3])->reference);
        $this->assertSame('114/2026', $this->order(['number' => 114])->reference);
        $this->assertSame('01/2022', $this->order(['number' => null, 'is_legacy' => true, 'legacy_reference' => '01/2022', 'year' => 2022])->reference);
    }

    #[Test]
    public function the_live_beneficiary_name_follows_its_type(): void
    {
        $supplier = Supplier::create(['name' => 'Sport Shop']);
        $user = User::factory()->create(['name' => 'Treasurer User']);
        $member = BoardMember::create(['name' => 'Board Person', 'role' => 'treasurer', 'status' => 'active']);

        $this->assertSame('Sport Shop', $this->order(['number' => 1, 'beneficiary_type' => 'supplier', 'beneficiary_id' => $supplier->id])->liveBeneficiaryName());
        $this->assertSame($user->name, $this->order(['number' => 2, 'beneficiary_type' => 'user', 'beneficiary_id' => $user->id])->liveBeneficiaryName());
        $this->assertSame('Board Person', $this->order(['number' => 3, 'beneficiary_type' => 'board_member', 'beneficiary_id' => $member->id])->liveBeneficiaryName());
        $this->assertNull($this->order(['number' => 4])->liveBeneficiaryName());
    }

    #[Test]
    public function a_supplier_with_a_payment_order_is_in_use(): void
    {
        $supplier = Supplier::create(['name' => 'Sport Shop']);
        $this->assertFalse($supplier->isInUse());

        $this->order(['beneficiary_type' => 'supplier', 'beneficiary_id' => $supplier->id]);

        $this->assertTrue($supplier->fresh()->isInUse());
    }
}
