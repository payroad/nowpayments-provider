<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use Payroad\Provider\NOWPayments\Data\NOWPaymentsCryptoRefundData;
use PHPUnit\Framework\TestCase;

final class NOWPaymentsCryptoRefundDataTest extends TestCase
{
    public function testGetters(): void
    {
        $data = new NOWPaymentsCryptoRefundData(
            returnTxHash:  'abc123txhash',
            returnAddress: 'TRxReturnAddress',
            withdrawalId:  'payout_999',
        );

        $this->assertSame('abc123txhash',    $data->getReturnTxHash());
        $this->assertSame('TRxReturnAddress',$data->getReturnAddress());
        $this->assertSame('payout_999',      $data->getWithdrawalId());
    }

    public function testAllFieldsDefaultToNull(): void
    {
        $data = new NOWPaymentsCryptoRefundData();

        $this->assertNull($data->getReturnTxHash());
        $this->assertNull($data->getReturnAddress());
        $this->assertNull($data->getWithdrawalId());
    }

    public function testToArrayContainsAllKeys(): void
    {
        $data  = new NOWPaymentsCryptoRefundData('tx', 'addr', 'id');
        $array = $data->toArray();

        $this->assertArrayHasKey('returnTxHash',  $array);
        $this->assertArrayHasKey('returnAddress', $array);
        $this->assertArrayHasKey('withdrawalId',  $array);

        $this->assertSame('tx',   $array['returnTxHash']);
        $this->assertSame('addr', $array['returnAddress']);
        $this->assertSame('id',   $array['withdrawalId']);
    }

    public function testFromArrayRoundtrip(): void
    {
        $original = new NOWPaymentsCryptoRefundData('hash_xyz', 'addr_xyz', 'withdrawal_xyz');
        $restored = NOWPaymentsCryptoRefundData::fromArray($original->toArray());

        $this->assertSame($original->getReturnTxHash(),  $restored->getReturnTxHash());
        $this->assertSame($original->getReturnAddress(), $restored->getReturnAddress());
        $this->assertSame($original->getWithdrawalId(),  $restored->getWithdrawalId());
    }

    public function testFromArrayWithNullValues(): void
    {
        $data = NOWPaymentsCryptoRefundData::fromArray([]);

        $this->assertNull($data->getReturnTxHash());
        $this->assertNull($data->getReturnAddress());
        $this->assertNull($data->getWithdrawalId());
    }
}
