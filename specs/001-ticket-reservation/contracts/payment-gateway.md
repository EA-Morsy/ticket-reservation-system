# Payment Gateway Contract — provider-independent boundary (001-ticket-reservation)

This is the single seam between reservation/business logic and any payment provider (constitution Principle VII, FR-017). Business logic (`App\Services\PaymentService`) depends **only** on this contract; concrete drivers live in `App\Payments\` and are bound through the service container (`config/payment.php` → `driver`). Swapping providers (SC-008) means adding a driver class + a config value — zero changes to business logic, and no provider-specific branching inside business logic.

> Paymob (the real adapter below) is an **implementation choice, not an assessment requirement** — the assessment requires a third-party hosted payment interface; Paymob is one concrete implementation satisfying it. When implementation begins, verify the exact Paymob transaction-callback/HMAC field format from the official Paymob documentation — do not assume all Paymob callbacks share the same field set.

## Contract

```php
namespace App\Contracts;

interface PaymentGateway
{
    /**
     * Create (or re-create) a hosted-checkout payment intent for a reservation.
     * Returns an intent carrying our payment reference and the provider's
     * hosted checkout URL. MUST NOT mutate reservation state.
     */
    public function initiate(\App\Models\Reservation $reservation): \App\Payments\PaymentIntent;

    /**
     * Verify and normalize a raw provider notification (webhook payload).
     * Returns null if the notification cannot be verified as authentic
     * (bad/missing signature) — callers MUST reject it without state change.
     * Verification, not business meaning, is this method's job.
     */
    public function verifyNotification(array $payload): ?\App\Payments\PaymentNotification;
}
```

### Value objects (immutable, provider-agnostic)

**`PaymentIntent`** — result of `initiate`:
- `reference: string` — our payment-intent reference (stored on `payments.reference`, unique)
- `hostedCheckoutUrl: string` — where the frontend redirects the user (display/handoff only; never payment truth, FR-022)

**`PaymentNotification`** — result of `verifyNotification`:
- `reference: string` — our payment reference from the provider
- `providerTransactionId: string` — provider's transaction id (idempotency anchor)
- `status: 'success'|'failed'`
- `amountMinor: int` — confirmed amount in the system's minor units
- `currency: string` — 3-letter code; must equal the configured currency
- `paidAt: ?\Carbon\CarbonInterface`

### Amount units

The contract speaks **integer minor units** (piasters, EGP 2dp) everywhere. Provider-specific units (Paymob uses centavos-equivalent integer units; some providers use decimal strings) are converted **inside the adapter**, which documents its conversion and rejects values it cannot convert losslessly (FR-030). Business logic never sees provider units.

## Drivers

| Driver | Purpose | Notes |
|---|---|---|
| `App\Payments\PaymobGateway` | real hosted-checkout adapter (Paymob, Egypt) | REST via Laravel HTTP client — **no SDK** (constitution III); HMAC-SHA512 callback verification |
| `App\Payments\FakePaymentGateway` | default/recommended driver for local runs and all tests | deterministic hosted URL; notification payload generated/signed by the same scheme, so webhook code paths are exercised end-to-end with zero credentials and zero real transactions (constitution X, FR-027) |

Binding (AppServiceProvider): `PaymentGateway::class` → `config('payment.driver')` implementation. Tests bind `FakePaymentGateway` (or a Mockery mock) on the container without touching business code (SC-008, constitution X).

## Behavioral rules the drivers must satisfy

1. `initiate` is idempotent-safe: calling it again on a retry (FR-012) creates a fresh intent/attempt row; it must never change the reservation's seats, total, or `expires_at`.
2. `verifyNotification` returns `null` for anything unverifiable; the endpoint answers 403 with no state change (FR-019).
3. Drivers never expose secrets, tokens, or raw provider credentials in returned objects, logs, or exceptions (FR-024).
4. The notification's `amountMinor`/`currency` are compared by business logic against the reservation's immutable total; a one-minor-unit mismatch never completes a reservation (FR-030).

## Fake driver control (tests & quickstart)

The `fake` driver accepts a control signal so quickstart validation and tests can drive success and failure deterministically — simulated provider behavior through the same contract, never real transactions (FR-027). The demo entry point is the planned artisan command `payments:simulate {outcome} {reference}` (`App\Console\Commands\PaymentSimulate`, active only while `PAYMENT_DRIVER=fake`): it builds a properly signed notification and delivers it to the same webhook endpoint the real provider uses. Tests drive the fake directly through the container binding.
