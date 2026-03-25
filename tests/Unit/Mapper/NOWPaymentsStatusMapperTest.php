<?php

declare(strict_types=1);

namespace Tests\Unit\Mapper;

use Payroad\Domain\Attempt\AttemptStatus;
use Payroad\Domain\Refund\RefundStatus;
use Payroad\Provider\NOWPayments\Mapper\NOWPaymentsStatusMapper;
use PHPUnit\Framework\TestCase;

final class NOWPaymentsStatusMapperTest extends TestCase
{
    private NOWPaymentsStatusMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new NOWPaymentsStatusMapper();
    }

    // ── mapPaymentStatus ──────────────────────────────────────────────────────

    public function testWaitingMapsToPending(): void
    {
        $this->assertSame(AttemptStatus::PENDING, $this->mapper->mapPaymentStatus('waiting'));
    }

    public function testConfirmingMapsToProcessing(): void
    {
        $this->assertSame(AttemptStatus::PROCESSING, $this->mapper->mapPaymentStatus('confirming'));
    }

    public function testConfirmedMapsToProcessing(): void
    {
        $this->assertSame(AttemptStatus::PROCESSING, $this->mapper->mapPaymentStatus('confirmed'));
    }

    public function testSendingMapsToProcessing(): void
    {
        $this->assertSame(AttemptStatus::PROCESSING, $this->mapper->mapPaymentStatus('sending'));
    }

    public function testPartiallyPaidMapsToProcessing(): void
    {
        $this->assertSame(AttemptStatus::PROCESSING, $this->mapper->mapPaymentStatus('partially_paid'));
    }

    public function testFinishedMapsToSucceeded(): void
    {
        $this->assertSame(AttemptStatus::SUCCEEDED, $this->mapper->mapPaymentStatus('finished'));
    }

    public function testFailedMapsToFailed(): void
    {
        $this->assertSame(AttemptStatus::FAILED, $this->mapper->mapPaymentStatus('failed'));
    }

    public function testExpiredMapsToExpired(): void
    {
        $this->assertSame(AttemptStatus::EXPIRED, $this->mapper->mapPaymentStatus('expired'));
    }

    public function testPaymentStatusIsCaseInsensitive(): void
    {
        $this->assertSame(AttemptStatus::SUCCEEDED, $this->mapper->mapPaymentStatus('FINISHED'));
        $this->assertSame(AttemptStatus::PENDING,   $this->mapper->mapPaymentStatus('WAITING'));
    }

    public function testUnknownPaymentStatusReturnsNull(): void
    {
        $this->assertNull($this->mapper->mapPaymentStatus('refunded'));
        $this->assertNull($this->mapper->mapPaymentStatus(''));
        $this->assertNull($this->mapper->mapPaymentStatus('unknown_status'));
    }

    // ── mapPayoutStatus ───────────────────────────────────────────────────────

    public function testPayoutWaitingMapsToProcessing(): void
    {
        $this->assertSame(RefundStatus::PROCESSING, $this->mapper->mapPayoutStatus('WAITING'));
    }

    public function testPayoutSendingMapsToProcessing(): void
    {
        $this->assertSame(RefundStatus::PROCESSING, $this->mapper->mapPayoutStatus('SENDING'));
    }

    public function testPayoutSentMapsToSucceeded(): void
    {
        $this->assertSame(RefundStatus::SUCCEEDED, $this->mapper->mapPayoutStatus('SENT'));
    }

    public function testPayoutFailedMapsToFailed(): void
    {
        $this->assertSame(RefundStatus::FAILED, $this->mapper->mapPayoutStatus('FAILED'));
    }

    public function testPayoutStatusIsCaseInsensitive(): void
    {
        $this->assertSame(RefundStatus::SUCCEEDED, $this->mapper->mapPayoutStatus('sent'));
        $this->assertSame(RefundStatus::FAILED,    $this->mapper->mapPayoutStatus('failed'));
    }

    public function testUnknownPayoutStatusReturnsNull(): void
    {
        $this->assertNull($this->mapper->mapPayoutStatus('PENDING'));
        $this->assertNull($this->mapper->mapPayoutStatus(''));
        $this->assertNull($this->mapper->mapPayoutStatus('UNKNOWN'));
    }
}
