<?php

declare(strict_types=1);

namespace Payroad\Provider\NOWPayments;

use Payroad\Domain\Attempt\PaymentAttemptId;
use Payroad\Domain\Money\Money;
use Payroad\Domain\Payment\PaymentId;
use Payroad\Domain\PaymentFlow\Crypto\CryptoPaymentAttempt;
use Payroad\Domain\PaymentFlow\Crypto\CryptoRefund;
use Payroad\Domain\Refund\RefundId;
use Payroad\Port\Provider\Crypto\CryptoAttemptContext;
use Payroad\Port\Provider\Crypto\RefundableCryptoProviderInterface;
use Payroad\Port\Provider\Crypto\CryptoRefundContext;
use Payroad\Port\Provider\RefundWebhookResult;
use Payroad\Port\Provider\WebhookEvent;
use Payroad\Port\Provider\WebhookResult;
use Payroad\Provider\NOWPayments\Data\NOWPaymentsCryptoAttemptData;
use Payroad\Provider\NOWPayments\Data\NOWPaymentsCryptoRefundData;
use Payroad\Provider\NOWPayments\Mapper\NOWPaymentsStatusMapper;

/**
 * Real NOWPayments crypto provider.
 *
 * API reference: https://documenter.getpostman.com/view/7907941/2s93JqTRWx
 *
 * Webhook verification: NOWPayments signs the IPN body with HMAC-SHA512 using
 * the IPN secret. The signature is sent in the `x-nowpayments-sig` header.
 * Verification: sort the payload keys, JSON-encode, compute HMAC-SHA512.
 */
final class NOWPaymentsProvider implements RefundableCryptoProviderInterface
{
    public const PRODUCTION_URL = 'https://api.nowpayments.io/v1';
    public const SANDBOX_URL    = 'https://api.sandbox.nowpayments.io/v1';

    /** @var callable|null  fn(string $method, string $url, ?array $body): array */
    private readonly mixed $httpClient;

    public function __construct(
        private readonly string                  $apiKey,
        private readonly string                  $ipnSecret,
        private readonly string                  $ipnCallbackUrl,
        private readonly string                  $baseUrl    = self::PRODUCTION_URL,
        private readonly NOWPaymentsStatusMapper  $mapper = new NOWPaymentsStatusMapper(),
        /**
         * Optional HTTP transport for testing.
         * Signature: fn(string $method, string $url, ?array $body): array
         * When null, the real curl implementation is used.
         */
        mixed $httpClient = null,
    ) {
        $this->httpClient = $httpClient;
    }

    // ── PaymentProviderInterface ──────────────────────────────────────────────

    public function supports(string $providerName): bool
    {
        return $providerName === 'nowpayments';
    }

    // ── CryptoProviderInterface ───────────────────────────────────────────────

    /**
     * Creates a NOWPayments invoice and returns a CryptoPaymentAttempt containing
     * the deposit wallet address, pay currency, and expected pay amount.
     *
     * The `$context->network` field maps to NOWPayments' `pay_currency` identifier
     * (e.g. 'usdttrc20', 'btc', 'eth', 'bnbbsc').
     */
    public function initiateCryptoAttempt(
        PaymentAttemptId     $id,
        PaymentId            $paymentId,
        string               $providerName,
        Money                $amount,
        CryptoAttemptContext $context,
    ): CryptoPaymentAttempt {
        $priceAmount   = bcdiv((string) $amount->getMinorAmount(), bcpow('10', (string) $amount->getCurrency()->precision, 0), $amount->getCurrency()->precision);
        $priceCurrency = strtolower($amount->getCurrency()->code);

        $response = $this->post('/payment', [
            'price_amount'        => (float) $priceAmount,
            'price_currency'      => $priceCurrency,
            'pay_currency'        => $context->network,
            'ipn_callback_url'    => $this->ipnCallbackUrl,
            'order_id'            => (string) $id,
            'order_description'   => 'Payment ' . (string) $paymentId,
        ]);

        $data = new NOWPaymentsCryptoAttemptData(
            walletAddress:         $response['pay_address'],
            payCurrency:           $response['pay_currency'],
            payAmount:             (string) ($response['pay_amount'] ?? '0'),
            nowPaymentsId:         (string) $response['payment_id'],
            confirmationCount:     0,
            requiredConfirmations: 1,
            memo:                  $response['payin_extra_id'] ?? $context->memo,
        );

        $attempt = CryptoPaymentAttempt::create($id, $paymentId, $providerName, $amount, $data);
        $attempt->setProviderReference((string) $response['payment_id']);
        $attempt->markAwaitingConfirmation('waiting');

        return $attempt;
    }

    /**
     * Initiates an on-chain payout (refund) via the NOWPayments Payouts API.
     *
     * Requires Payout API access to be enabled on the NOWPayments account.
     */
    public function initiateRefund(
        RefundId            $id,
        PaymentId           $paymentId,
        PaymentAttemptId    $originalAttemptId,
        string              $providerName,
        Money               $amount,
        string              $originalProviderReference,
        CryptoRefundContext $context,
    ): CryptoRefund {
        $amountDecimal = bcdiv((string) $amount->getMinorAmount(), bcpow('10', (string) $amount->getCurrency()->precision, 0), $amount->getCurrency()->precision);

        // Retrieve original payment to determine the pay_currency for the refund
        $original = $this->get('/payment/' . $originalProviderReference);
        $currency = $context->network ?? $original['pay_currency'];

        $response = $this->post('/payout', [
            'address'          => $context->returnAddress,
            'currency'         => $currency,
            'amount'           => (float) $amountDecimal,
            'ipn_callback_url' => $this->ipnCallbackUrl,
            'extra_id'         => (string) $id,
        ]);

        $payoutId = (string) ($response['id'] ?? $response['payout_id'] ?? '');

        $data = new NOWPaymentsCryptoRefundData(
            returnTxHash:  null,
            returnAddress: $context->returnAddress,
            withdrawalId:  $payoutId,
        );

        $refund = CryptoRefund::create($id, $paymentId, $originalAttemptId, $providerName, $amount, $data);
        $refund->setProviderReference($payoutId);

        return $refund;
    }

    // ── Webhooks ──────────────────────────────────────────────────────────────

    /**
     * Verifies the NOWPayments IPN signature and maps the event to a WebhookEvent.
     *
     * IPN signature verification (HMAC-SHA512):
     *  1. Parse the JSON body into an array.
     *  2. Sort the array keys alphabetically (ksort).
     *  3. JSON-encode the sorted array.
     *  4. Compute HMAC-SHA512 using the IPN secret.
     *  5. Compare (constant-time) with the `x-nowpayments-sig` header value.
     *
     * NOWPayments sends payment IPNs (payment_id + payment_status) and
     * payout IPNs (payout_id + status) via the same callback URL.
     * We distinguish them by checking which key is present.
     */
    public function parseIncomingWebhook(array $payload, array $headers): ?WebhookEvent
    {
        $this->verifySignature($payload, $headers);

        // Payout IPN (refund flow) — keyed by payout_id
        if (isset($payload['payout_id'])) {
            $newStatus = $this->mapper->mapPayoutStatus((string) ($payload['status'] ?? ''));
            if ($newStatus === null) {
                return null;
            }

            $txHash = $payload['hash'] ?? $payload['tx_hash'] ?? null;
            $updatedData = $txHash !== null
                ? new NOWPaymentsCryptoRefundData(
                    returnTxHash:  $txHash,
                    returnAddress: $payload['address'] ?? null,
                    withdrawalId:  (string) $payload['payout_id'],
                )
                : null;

            return new RefundWebhookResult(
                providerReference:   (string) $payload['payout_id'],
                newStatus:           $newStatus,
                providerStatus:      (string) ($payload['status'] ?? ''),
                updatedSpecificData: $updatedData,
            );
        }

        // Payment IPN (attempt flow) — keyed by payment_id
        if (!isset($payload['payment_id'])) {
            return null;
        }

        $newStatus = $this->mapper->mapPaymentStatus((string) ($payload['payment_status'] ?? ''));
        if ($newStatus === null) {
            return null;
        }

        // Build updated attempt data from the IPN payload when confirmations are reported
        $confirmations = isset($payload['confirmations']) ? (int) $payload['confirmations'] : null;
        $updatedData   = null;
        if ($confirmations !== null) {
            $updatedData = new NOWPaymentsCryptoAttemptData(
                walletAddress:         $payload['pay_address'] ?? '',
                payCurrency:           $payload['pay_currency'] ?? '',
                payAmount:             (string) ($payload['pay_amount'] ?? '0'),
                nowPaymentsId:         (string) $payload['payment_id'],
                confirmationCount:     $confirmations,
                requiredConfirmations: (int) ($payload['required_confirmations'] ?? 1),
                memo:                  $payload['payin_extra_id'] ?? null,
            );
        }

        return new WebhookResult(
            providerReference:   (string) $payload['payment_id'],
            newStatus:           $newStatus,
            providerStatus:      (string) ($payload['payment_status'] ?? ''),
            updatedSpecificData: $updatedData,
        );
    }

    // ── HTTP helpers ──────────────────────────────────────────────────────────

    private function get(string $path): array
    {
        return $this->request('GET', $path, null);
    }

    private function post(string $path, array $body): array
    {
        return $this->request('POST', $path, $body);
    }

    private function request(string $method, string $path, ?array $body): array
    {
        $url = $this->baseUrl . $path;

        if ($this->httpClient !== null) {
            return ($this->httpClient)($method, $url, $body);
        }


        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'x-api-key: ' . $this->apiKey,
                'Content-Type: application/json',
            ],
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }

        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr !== '') {
            throw new \RuntimeException("NOWPayments HTTP error: {$curlErr}");
        }

        $decoded = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);

        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = $decoded['message'] ?? $decoded['error'] ?? (string) $raw;
            throw new \RuntimeException("NOWPayments API error {$httpCode}: {$msg}");
        }

        return $decoded;
    }

    // ── Signature verification ────────────────────────────────────────────────

    private function verifySignature(array $payload, array $headers): void
    {
        $sig = $headers['x-nowpayments-sig'] ?? $headers['X-NowPayments-Sig'] ?? null;
        if (is_array($sig)) {
            $sig = $sig[0] ?? null;
        }

        if ($sig === null || $sig === '') {
            throw new \InvalidArgumentException('Missing NOWPayments IPN signature header.');
        }

        ksort($payload);
        $sorted   = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $expected = hash_hmac('sha512', $sorted, $this->ipnSecret);

        if (!hash_equals($expected, strtolower((string) $sig))) {
            throw new \InvalidArgumentException('Invalid NOWPayments IPN signature.');
        }
    }
}
