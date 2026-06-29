<?php

namespace Tests\Unit\Services;

use App\Services\Finance\ResolvePaymentStatusService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResolvePaymentStatusServiceTest extends TestCase
{
    #[Test]
    public function it_returns_exempt_when_marked_exempt(): void
    {
        $service = new ResolvePaymentStatusService;

        $status = $service->handle(0, 100, true);

        $this->assertSame('Exempt', $status);
    }

    #[Test]
    public function it_returns_paid_for_fully_paid_amounts(): void
    {
        $service = new ResolvePaymentStatusService;

        $this->assertSame('Paid', $service->handle(100, 100));
        $this->assertSame('Paid', $service->handle(120, 100));
    }

    #[Test]
    public function it_returns_partial_for_partial_payments(): void
    {
        $service = new ResolvePaymentStatusService;

        $status = $service->handle(50, 100);

        $this->assertSame('Partial', $status);
    }

    #[Test]
    public function it_returns_unpaid_for_zero_paid_amount(): void
    {
        $service = new ResolvePaymentStatusService;

        $status = $service->handle(0, 100);

        $this->assertSame('Unpaid', $status);
    }
}
