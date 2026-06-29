<?php

namespace App\Services\Finance;

class ResolvePaymentStatusService
{
    public function handle(float $amountPaid, float $amountOwed, bool $isExempt = false): string
    {
        if ($isExempt) {
            return 'Exempt';
        }

        if ($amountPaid >= $amountOwed && $amountPaid > 0) {
            return 'Paid';
        }

        if ($amountPaid > 0 && $amountPaid < $amountOwed) {
            return 'Partial';
        }

        return 'Unpaid';
    }
}
