<?php

declare(strict_types=1);

namespace Payroad\Provider\NOWPayments\Mapper;

use Payroad\Domain\Attempt\AttemptStatus;
use Payroad\Domain\Refund\RefundStatus;

/**
 * Maps NOWPayments payment/payout status strings to domain status enums.
 *
 * Payment statuses (from NOWPayments docs):
 *   waiting        – awaiting the customer's on-chain transfer
 *   confirming     – payment detected; accumulating block confirmations
 *   confirmed      – sufficient confirmations received
 *   sending        – NOWPayments is forwarding funds to the merchant
 *   partially_paid – less than the required amount was received
 *   finished       – payment fully settled
 *   failed         – payment failed
 *   expired        – invoice expired before payment arrived
 *   refunded       – funds returned to the payer (refund flow)
 *
 * Payout statuses (refund flow):
 *   WAITING        – payout queued
 *   SENDING        – payout transaction broadcast
 *   SENT           – payout confirmed on-chain
 *   FAILED         – payout failed
 */
final class NOWPaymentsStatusMapper
{
    /** @return AttemptStatus|null  null = ignore this status update */
    public function mapPaymentStatus(string $status): ?AttemptStatus
    {
        return match (strtolower($status)) {
            'waiting'        => AttemptStatus::PENDING,
            'confirming',
            'confirmed',
            'sending',
            'partially_paid' => AttemptStatus::PROCESSING,
            'finished'       => AttemptStatus::SUCCEEDED,
            'failed'         => AttemptStatus::FAILED,
            'expired'        => AttemptStatus::EXPIRED,
            default          => null,
        };
    }

    /** @return RefundStatus|null  null = ignore this payout status update */
    public function mapPayoutStatus(string $status): ?RefundStatus
    {
        return match (strtoupper($status)) {
            'WAITING', 'SENDING' => RefundStatus::PROCESSING,
            'SENT'               => RefundStatus::SUCCEEDED,
            'FAILED'             => RefundStatus::FAILED,
            default              => null,
        };
    }
}
