<?php

declare(strict_types=1);

namespace Tests\Unit;

use Payroad\Domain\Attempt\AttemptStatus;
use Payroad\Domain\Attempt\PaymentAttemptId;
use Payroad\Domain\Money\Currency;
use Payroad\Domain\Money\Money;
use Payroad\Domain\Payment\PaymentId;
use Payroad\Domain\PaymentFlow\Crypto\CryptoPaymentAttempt;
use Payroad\Domain\PaymentFlow\Crypto\CryptoRefund;
use Payroad\Domain\Refund\RefundId;
use Payroad\Domain\Refund\RefundStatus;
use Payroad\Port\Provider\Crypto\CryptoAttemptContext;
use Payroad\Port\Provider\Crypto\CryptoRefundContext;
use Payroad\Port\Provider\RefundWebhookResult;
use Payroad\Port\Provider\WebhookResult;
use Payroad\Provider\NOWPayments\Data\NOWPaymentsCryptoAttemptData;
use Payroad\Provider\NOWPayments\Data\NOWPaymentsCryptoRefundData;
use Payroad\Provider\NOWPayments\NOWPaymentsProvider;
use PHPUnit\Framework\TestCase;

final class NOWPaymentsProviderTest extends TestCase
{
    private const IPN_SECRET = 'test_ipn_secret';

    // ── Factory helpers ───────────────────────────────────────────────────────

    /**
     * Builds a provider backed by a fake HTTP client.
     *
     * @param array<string, array> $responses  Keyed by "$METHOD $urlSuffix"
     *                                          e.g. ['POST /payment' => [...response...]]
     */
    private function makeProvider(array $responses = []): NOWPaymentsProvider
    {
        $httpClient = function (string $method, string $url, ?array $body) use ($responses): array {
            foreach ($responses as $key => $response) {
                [$keyMethod, $keySuffix] = explode(' ', $key, 2);
                if ($keyMethod === $method && str_ends_with($url, $keySuffix)) {
                    return $response;
                }
            }
            throw new \RuntimeException("Unexpected HTTP call: {$method} {$url}");
        };

        return new NOWPaymentsProvider(
            apiKey:         'test_api_key',
            ipnSecret:      self::IPN_SECRET,
            ipnCallbackUrl: 'https://example.com/webhooks/nowpayments',
            httpClient:     $httpClient,
        );
    }

    private function makePaymentResponse(
        string $paymentId    = 'np_12345',
        string $payAddress   = 'TRxTestWallet',
        string $payCurrency  = 'usdttrc20',
        float  $payAmount    = 10.50,
    ): array {
        return [
            'payment_id'   => $paymentId,
            'pay_address'  => $payAddress,
            'pay_currency' => $payCurrency,
            'pay_amount'   => $payAmount,
        ];
    }

    private function makePayoutResponse(string $payoutId = 'payout_99'): array
    {
        return ['id' => $payoutId];
    }

    private function makeIpnPayload(array $overrides = []): array
    {
        return array_merge([
            'payment_id'     => 'np_12345',
            'payment_status' => 'finished',
            'pay_address'    => 'TRxTestWallet',
            'pay_currency'   => 'usdttrc20',
            'pay_amount'     => 10.5,
        ], $overrides);
    }

    /** Builds a valid NOWPayments IPN signature for the given payload. */
    private function makeIpnSignature(array $payload): string
    {
        ksort($payload);
        $sorted = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        return hash_hmac('sha512', $sorted, self::IPN_SECRET);
    }

    // ── supports() ────────────────────────────────────────────────────────────

    public function testSupportsNowpayments(): void
    {
        $this->assertTrue($this->makeProvider()->supports('nowpayments'));
    }

    public function testDoesNotSupportOtherProvider(): void
    {
        $this->assertFalse($this->makeProvider()->supports('stripe'));
        $this->assertFalse($this->makeProvider()->supports('binance'));
    }

    // ── initiateCryptoAttempt() ───────────────────────────────────────────────

    public function testInitiateCryptoAttemptCreatesPayment(): void
    {
        $capturedBody  = null;
        $paymentId     = 'np_test_001';
        $walletAddress = 'TRxWallet001';

        $provider = new NOWPaymentsProvider(
            apiKey:         'key',
            ipnSecret:      'secret',
            ipnCallbackUrl: 'https://example.com/ipn',
            httpClient: function (string $method, string $url, ?array $body) use ($paymentId, $walletAddress, &$capturedBody): array {
                $capturedBody = $body;
                return $this->makePaymentResponse($paymentId, $walletAddress, 'usdttrc20', 10.5);
            },
        );

        $attempt = $provider->initiateCryptoAttempt(
            PaymentAttemptId::fromInt(1),
            PaymentId::fromInt(42),
            'nowpayments',
            Money::ofMinor(1000, new Currency('USD', 2)),
            new CryptoAttemptContext(network: 'usdttrc20'),
        );

        // Verify request was built correctly
        $this->assertSame('usdttrc20', $capturedBody['pay_currency']);
        $this->assertSame('usd',       $capturedBody['price_currency']);
        $this->assertSame(10.0,        $capturedBody['price_amount']);

        // Verify aggregate type
        $this->assertInstanceOf(CryptoPaymentAttempt::class, $attempt);
        $this->assertSame($paymentId, $attempt->getProviderReference());

        // Verify data
        /** @var NOWPaymentsCryptoAttemptData $data */
        $data = $attempt->getData();
        $this->assertInstanceOf(NOWPaymentsCryptoAttemptData::class, $data);
        $this->assertSame($walletAddress, $data->getWalletAddress());
        $this->assertSame('usdttrc20',    $data->getPayCurrency());
        $this->assertSame($paymentId,     $data->getNowPaymentsId());
    }

    public function testInitiateCryptoAttemptInitialStatusIsPending(): void
    {
        $provider = $this->makeProvider(['POST /payment' => $this->makePaymentResponse()]);

        $attempt = $provider->initiateCryptoAttempt(
            PaymentAttemptId::fromInt(1),
            PaymentId::fromInt(1),
            'nowpayments',
            Money::ofMinor(500, new Currency('USD', 2)),
            new CryptoAttemptContext(network: 'btc'),
        );

        $this->assertSame(AttemptStatus::PENDING, $attempt->getStatus());
    }

    public function testInitiateCryptoAttemptPassesMemoFromContext(): void
    {
        $capturedBody = null;
        $provider = new NOWPaymentsProvider(
            apiKey: 'k', ipnSecret: 's', ipnCallbackUrl: 'u',
            httpClient: function ($m, $u, $b) use (&$capturedBody): array {
                $capturedBody = $b;
                return $this->makePaymentResponse();
            },
        );

        $provider->initiateCryptoAttempt(
            PaymentAttemptId::fromInt(1),
            PaymentId::fromInt(1),
            'nowpayments',
            Money::ofMinor(100, new Currency('USD', 2)),
            new CryptoAttemptContext(network: 'xrp', memo: 'memo-tag-123'),
        );

        // Context memo is set but NOWPayments response doesn't provide payin_extra_id,
        // so the data memo should fall back to the context memo
        $data = (new NOWPaymentsProvider(
            apiKey: 'k', ipnSecret: 's', ipnCallbackUrl: 'u',
            httpClient: fn($m, $u, $b) => $this->makePaymentResponse(),
        ))->initiateCryptoAttempt(
            PaymentAttemptId::fromInt(1),
            PaymentId::fromInt(1),
            'nowpayments',
            Money::ofMinor(100, new Currency('USD', 2)),
            new CryptoAttemptContext(network: 'xrp', memo: 'memo-tag-123'),
        )->getData();

        $this->assertSame('memo-tag-123', $data->getMemo());
    }

    // ── initiateRefund() ──────────────────────────────────────────────────────

    public function testInitiateRefundCreatesPayoutAndRefundAggregate(): void
    {
        $payoutId     = 'payout_test_001';
        $returnAddress = 'TRxRefundTarget';

        $provider = $this->makeProvider([
            'GET /payment/np_original' => ['pay_currency' => 'usdttrc20'],
            'POST /payout'             => $this->makePayoutResponse($payoutId),
        ]);

        $refund = $provider->initiateRefund(
            id:                        RefundId::fromInt(1),
            paymentId:                 PaymentId::fromInt(10),
            originalAttemptId:         PaymentAttemptId::fromInt(5),
            providerName:              'nowpayments',
            amount:                    Money::ofMinor(500, new Currency('USD', 2)),
            originalProviderReference: 'np_original',
            context:                   new CryptoRefundContext(returnAddress: $returnAddress),
        );

        $this->assertInstanceOf(CryptoRefund::class, $refund);
        $this->assertSame($payoutId, $refund->getProviderReference());

        /** @var NOWPaymentsCryptoRefundData $data */
        $data = $refund->getData();
        $this->assertInstanceOf(NOWPaymentsCryptoRefundData::class, $data);
        $this->assertSame($returnAddress, $data->getReturnAddress());
        $this->assertSame($payoutId,      $data->getWithdrawalId());
        $this->assertNull($data->getReturnTxHash());
    }

    public function testInitiateRefundUsesNetworkOverrideFromContext(): void
    {
        $capturedPayoutBody = null;

        $provider = new NOWPaymentsProvider(
            apiKey: 'k', ipnSecret: 's', ipnCallbackUrl: 'u',
            httpClient: function (string $method, string $url, ?array $body) use (&$capturedPayoutBody): array {
                if ($method === 'GET') {
                    return ['pay_currency' => 'usdttrc20'];
                }
                $capturedPayoutBody = $body;
                return $this->makePayoutResponse();
            },
        );

        $provider->initiateRefund(
            RefundId::fromInt(1),
            PaymentId::fromInt(1),
            PaymentAttemptId::fromInt(1),
            'nowpayments',
            Money::ofMinor(100, new Currency('USD', 2)),
            'np_ref',
            new CryptoRefundContext(returnAddress: 'addr', network: 'usdterc20'),
        );

        // The network override must be used, not the original payment currency
        $this->assertSame('usdterc20', $capturedPayoutBody['currency']);
    }

    // ── parseIncomingWebhook() — payment IPN ─────────────────────────────────

    public function testParsePaymentIpnReturnsWebhookResult(): void
    {
        $payload = $this->makeIpnPayload(['payment_status' => 'finished']);
        $headers = ['x-nowpayments-sig' => $this->makeIpnSignature($payload)];

        $event = $this->makeProvider()->parseIncomingWebhook($payload, $headers);

        $this->assertInstanceOf(WebhookResult::class, $event);
        $this->assertSame('np_12345',           $event->providerReference);
        $this->assertSame(AttemptStatus::SUCCEEDED, $event->newStatus);
        $this->assertSame('finished',           $event->providerStatus);
    }

    public function testParsePaymentIpnMapsConfirmingToProcessing(): void
    {
        $payload = $this->makeIpnPayload(['payment_status' => 'confirming', 'confirmations' => 2]);
        $headers = ['x-nowpayments-sig' => $this->makeIpnSignature($payload)];

        $event = $this->makeProvider()->parseIncomingWebhook($payload, $headers);

        $this->assertInstanceOf(WebhookResult::class, $event);
        $this->assertSame(AttemptStatus::PROCESSING, $event->newStatus);
    }

    public function testParsePaymentIpnAttachesUpdatedDataWhenConfirmationsPresent(): void
    {
        $payload = $this->makeIpnPayload([
            'payment_status'   => 'confirming',
            'confirmations'    => 3,
            'required_confirmations' => 6,
        ]);
        $headers = ['x-nowpayments-sig' => $this->makeIpnSignature($payload)];

        $event = $this->makeProvider()->parseIncomingWebhook($payload, $headers);

        $this->assertInstanceOf(WebhookResult::class, $event);
        $this->assertNotNull($event->updatedSpecificData);

        /** @var NOWPaymentsCryptoAttemptData $data */
        $data = $event->updatedSpecificData;
        $this->assertSame(3, $data->getConfirmationCount());
        $this->assertSame(6, $data->getRequiredConfirmations());
    }

    public function testParsePaymentIpnReturnsNullForUnknownStatus(): void
    {
        $payload = $this->makeIpnPayload(['payment_status' => 'refunded']);
        $headers = ['x-nowpayments-sig' => $this->makeIpnSignature($payload)];

        $event = $this->makeProvider()->parseIncomingWebhook($payload, $headers);

        $this->assertNull($event);
    }

    // ── parseIncomingWebhook() — payout IPN ──────────────────────────────────

    public function testParsePayoutIpnReturnsRefundWebhookResult(): void
    {
        $payload = [
            'payout_id' => 'payout_001',
            'status'    => 'SENT',
            'address'   => 'TRxRefundAddress',
            'hash'      => 'tx_hash_abc',
        ];
        $headers = ['x-nowpayments-sig' => $this->makeIpnSignature($payload)];

        $event = $this->makeProvider()->parseIncomingWebhook($payload, $headers);

        $this->assertInstanceOf(RefundWebhookResult::class, $event);
        $this->assertSame('payout_001',         $event->providerReference);
        $this->assertSame(RefundStatus::SUCCEEDED, $event->newStatus);
        $this->assertSame('SENT',               $event->providerStatus);
    }

    public function testParsePayoutIpnAttachesUpdatedDataWithTxHash(): void
    {
        $payload = [
            'payout_id' => 'payout_002',
            'status'    => 'SENT',
            'address'   => 'TRxAddr',
            'hash'      => 'on_chain_hash_xyz',
        ];
        $headers = ['x-nowpayments-sig' => $this->makeIpnSignature($payload)];

        $event = $this->makeProvider()->parseIncomingWebhook($payload, $headers);

        $this->assertInstanceOf(RefundWebhookResult::class, $event);
        $this->assertNotNull($event->updatedSpecificData);

        /** @var NOWPaymentsCryptoRefundData $data */
        $data = $event->updatedSpecificData;
        $this->assertSame('on_chain_hash_xyz', $data->getReturnTxHash());
        $this->assertSame('TRxAddr',           $data->getReturnAddress());
    }

    public function testParsePayoutIpnReturnsNullForUnknownStatus(): void
    {
        $payload = ['payout_id' => 'p1', 'status' => 'UNKNOWN'];
        $headers = ['x-nowpayments-sig' => $this->makeIpnSignature($payload)];

        $this->assertNull($this->makeProvider()->parseIncomingWebhook($payload, $headers));
    }

    public function testParseIpnReturnsNullWhenNeitherPaymentNorPayoutKey(): void
    {
        $payload = ['some_other_key' => 'value'];
        $headers = ['x-nowpayments-sig' => $this->makeIpnSignature($payload)];

        $this->assertNull($this->makeProvider()->parseIncomingWebhook($payload, $headers));
    }

    // ── Signature verification ────────────────────────────────────────────────

    public function testInvalidSignatureThrows(): void
    {
        $payload = $this->makeIpnPayload();
        $headers = ['x-nowpayments-sig' => 'invalid_signature_value'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid NOWPayments IPN signature');

        $this->makeProvider()->parseIncomingWebhook($payload, $headers);
    }

    public function testMissingSignatureHeaderThrows(): void
    {
        $payload = $this->makeIpnPayload();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing NOWPayments IPN signature header');

        $this->makeProvider()->parseIncomingWebhook($payload, []);
    }

    public function testSignatureVerificationIsSortOrderIndependent(): void
    {
        // Payload arrives with keys in arbitrary order
        $payload = [
            'pay_amount'     => 10.5,
            'payment_status' => 'finished',
            'pay_currency'   => 'usdttrc20',
            'payment_id'     => 'np_12345',
            'pay_address'    => 'TRxTestWallet',
        ];

        // Signature is computed on the sorted version — must still verify regardless of arrival order
        ksort($payload);
        $sorted    = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $signature = hash_hmac('sha512', $sorted, self::IPN_SECRET);

        // Shuffle the payload back to unsorted order
        $unsorted = [
            'pay_amount'     => 10.5,
            'payment_status' => 'finished',
            'pay_currency'   => 'usdttrc20',
            'payment_id'     => 'np_12345',
            'pay_address'    => 'TRxTestWallet',
        ];

        $event = $this->makeProvider()->parseIncomingWebhook(
            $unsorted,
            ['x-nowpayments-sig' => $signature],
        );

        $this->assertInstanceOf(WebhookResult::class, $event);
    }
}
