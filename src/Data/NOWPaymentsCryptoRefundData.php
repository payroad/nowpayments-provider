<?php

declare(strict_types=1);

namespace Payroad\Provider\NOWPayments\Data;

use Payroad\Port\Provider\Crypto\CryptoRefundData;

final class NOWPaymentsCryptoRefundData implements CryptoRefundData
{
    public function __construct(
        private readonly ?string $returnTxHash   = null,
        private readonly ?string $returnAddress  = null,
        private readonly ?string $withdrawalId   = null,
    ) {}

    public function getReturnTxHash(): ?string  { return $this->returnTxHash; }
    public function getReturnAddress(): ?string { return $this->returnAddress; }
    public function getWithdrawalId(): ?string  { return $this->withdrawalId; }

    public function toArray(): array
    {
        return [
            'returnTxHash'  => $this->returnTxHash,
            'returnAddress' => $this->returnAddress,
            'withdrawalId'  => $this->withdrawalId,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            returnTxHash:  $data['returnTxHash']  ?? null,
            returnAddress: $data['returnAddress'] ?? null,
            withdrawalId:  $data['withdrawalId']  ?? null,
        );
    }
}
