<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use Payroad\Provider\NOWPayments\Data\NOWPaymentsCryptoAttemptData;
use PHPUnit\Framework\TestCase;

final class NOWPaymentsCryptoAttemptDataTest extends TestCase
{
    private function makeData(array $overrides = []): NOWPaymentsCryptoAttemptData
    {
        return new NOWPaymentsCryptoAttemptData(
            walletAddress:         $overrides['walletAddress']         ?? 'TRx1234567890abcdef',
            payCurrency:           $overrides['payCurrency']           ?? 'usdttrc20',
            payAmount:             $overrides['payAmount']             ?? '10.50',
            nowPaymentsId:         $overrides['nowPaymentsId']         ?? '4987654321',
            confirmationCount:     $overrides['confirmationCount']     ?? 0,
            requiredConfirmations: $overrides['requiredConfirmations'] ?? 1,
            memo:                  $overrides['memo']                  ?? null,
        );
    }

    public function testGetters(): void
    {
        $data = $this->makeData([
            'walletAddress'         => 'TRxABCDEF',
            'payCurrency'           => 'btc',
            'payAmount'             => '0.00042',
            'nowPaymentsId'         => '123456',
            'confirmationCount'     => 2,
            'requiredConfirmations' => 3,
            'memo'                  => 'test-memo',
        ]);

        $this->assertSame('TRxABCDEF', $data->getWalletAddress());
        $this->assertSame('btc',       $data->getPayCurrency());
        $this->assertSame('0.00042',   $data->getPayAmount());
        $this->assertSame('123456',    $data->getNowPaymentsId());
        $this->assertSame(2,           $data->getConfirmationCount());
        $this->assertSame(3,           $data->getRequiredConfirmations());
        $this->assertSame('test-memo', $data->getMemo());
    }

    public function testMemoDefaultsToNull(): void
    {
        $data = $this->makeData(['memo' => null]);
        $this->assertNull($data->getMemo());
    }

    public function testToArrayContainsAllFields(): void
    {
        $data  = $this->makeData();
        $array = $data->toArray();

        $this->assertArrayHasKey('walletAddress',         $array);
        $this->assertArrayHasKey('payCurrency',           $array);
        $this->assertArrayHasKey('payAmount',             $array);
        $this->assertArrayHasKey('nowPaymentsId',         $array);
        $this->assertArrayHasKey('confirmationCount',     $array);
        $this->assertArrayHasKey('requiredConfirmations', $array);
        $this->assertArrayHasKey('memo',                  $array);
    }

    public function testFromArrayRoundtrip(): void
    {
        $original = $this->makeData([
            'walletAddress'         => '0xABCDEF',
            'payCurrency'           => 'eth',
            'payAmount'             => '0.004',
            'nowPaymentsId'         => '9988776655',
            'confirmationCount'     => 5,
            'requiredConfirmations' => 12,
            'memo'                  => null,
        ]);

        $restored = NOWPaymentsCryptoAttemptData::fromArray($original->toArray());

        $this->assertSame($original->getWalletAddress(),        $restored->getWalletAddress());
        $this->assertSame($original->getPayCurrency(),          $restored->getPayCurrency());
        $this->assertSame($original->getPayAmount(),            $restored->getPayAmount());
        $this->assertSame($original->getNowPaymentsId(),        $restored->getNowPaymentsId());
        $this->assertSame($original->getConfirmationCount(),    $restored->getConfirmationCount());
        $this->assertSame($original->getRequiredConfirmations(),$restored->getRequiredConfirmations());
        $this->assertSame($original->getMemo(),                 $restored->getMemo());
    }

    public function testFromArrayUsesDefaultsForMissingOptionalFields(): void
    {
        $data = NOWPaymentsCryptoAttemptData::fromArray([
            'walletAddress' => 'TRxXXX',
            'payCurrency'   => 'usdttrc20',
            'payAmount'     => '5.00',
            'nowPaymentsId' => '111',
        ]);

        $this->assertSame(0,    $data->getConfirmationCount());
        $this->assertSame(1,    $data->getRequiredConfirmations());
        $this->assertNull($data->getMemo());
    }
}
