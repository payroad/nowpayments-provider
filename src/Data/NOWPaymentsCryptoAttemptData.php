<?php

declare(strict_types=1);

namespace Payroad\Provider\NOWPayments\Data;

use Payroad\Domain\PaymentFlow\Crypto\CryptoAttemptData;

final class NOWPaymentsCryptoAttemptData implements CryptoAttemptData
{
    public function __construct(
        /** Wallet address the customer must send funds to. */
        private readonly string  $walletAddress,
        /** Crypto currency code (e.g. "btc", "eth", "usdttrc20"). */
        private readonly string  $payCurrency,
        /** Amount in crypto the customer must send. */
        private readonly string  $payAmount,
        /** NOWPayments-assigned payment ID (used to check status). */
        private readonly string  $nowPaymentsId,
        private readonly int     $confirmationCount    = 0,
        private readonly int     $requiredConfirmations = 1,
        private readonly ?string $memo                 = null,
        /** Amount in crypto actually received so far; non-null when partially paid. */
        private readonly ?string $actualPaidAmount     = null,
    ) {}

    public function getWalletAddress(): string       { return $this->walletAddress; }
    public function getConfirmationCount(): int      { return $this->confirmationCount; }
    public function getRequiredConfirmations(): int  { return $this->requiredConfirmations; }
    public function getPayCurrency(): string         { return $this->payCurrency; }
    public function getPayAmount(): string           { return $this->payAmount; }
    public function getNowPaymentsId(): string       { return $this->nowPaymentsId; }
    public function getMemo(): ?string               { return $this->memo; }
    public function getActualPaidAmount(): ?string   { return $this->actualPaidAmount; }

    /** NOWPayments uses wallet address directly; no hosted payment page. */
    public function getPaymentUrl(): ?string         { return null; }

    public function toArray(): array
    {
        return [
            'walletAddress'         => $this->walletAddress,
            'payCurrency'           => $this->payCurrency,
            'payAmount'             => $this->payAmount,
            'nowPaymentsId'         => $this->nowPaymentsId,
            'confirmationCount'     => $this->confirmationCount,
            'requiredConfirmations' => $this->requiredConfirmations,
            'memo'                  => $this->memo,
            'actualPaidAmount'      => $this->actualPaidAmount,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            walletAddress:          $data['walletAddress'],
            payCurrency:            $data['payCurrency'],
            payAmount:              $data['payAmount'],
            nowPaymentsId:          $data['nowPaymentsId'],
            confirmationCount:      $data['confirmationCount'] ?? 0,
            requiredConfirmations:  $data['requiredConfirmations'] ?? 1,
            memo:                   $data['memo'] ?? null,
            actualPaidAmount:       $data['actualPaidAmount'] ?? null,
        );
    }
}
