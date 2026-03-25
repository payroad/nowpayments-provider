# payroad/nowpayments-provider

NOWPayments crypto payment provider for the [Payroad](https://github.com/payroad/payroad-core) platform.

## Features

- Crypto deposit address generation via NOWPayments API
- Confirmation counting without premature status transitions
- HMAC-SHA512 webhook signature verification
- On-chain refund via NOWPayments Payout API (`RefundableCryptoProviderInterface`)
- Pluggable HTTP transport for easy testing

## Requirements

- PHP 8.2+
- `payroad/payroad-core`

## Installation

```bash
composer require payroad/nowpayments-provider
```

## Configuration

```yaml
# config/packages/payroad.yaml
payroad:
  providers:
    nowpayments:
      factory: Payroad\Provider\NOWPayments\NOWPaymentsProviderFactory
      api_key:      '%env(NOWPAYMENTS_API_KEY)%'
      ipn_secret:   '%env(NOWPAYMENTS_IPN_SECRET)%'
      callback_url: '%env(NOWPAYMENTS_IPN_CALLBACK_URL)%'
      base_url:     '%env(NOWPAYMENTS_BASE_URL)%'
```

## Payment flow

```
Customer                                Backend                 NOWPayments
────────────────────────────────────────────────────────────────────────────
POST /api/payments/crypto/initiate
  ← { depositAddress, payCurrency, payAmount }
Customer sends crypto to address
                                                          POST /webhooks/nowpayments
                                                            payment_status: confirmed
                                                              → Payment SUCCEEDED
```

## Implemented interfaces

| Interface | Description |
|-----------|-------------|
| `CryptoProviderInterface` | Deposit address generation, webhook parsing |
| `RefundableCryptoProviderInterface` | On-chain refund via NOWPayments Payout API |
