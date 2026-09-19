# Ticket Reservation API

Laravel API for reserving event seats with fixed 30-minute holds, MySQL row locking, Sanctum tokens, and hosted-payment webhooks.

## Requirements

- PHP 8.3+ with `pdo_mysql`, `mbstring`, `openssl`, and `curl`
- Composer
- MySQL for development, general tests, and concurrency tests. Docker is not required.

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Set `DB_CONNECTION=mysql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE=ticket_reservation`, `DB_USERNAME`, and `DB_PASSWORD` in `.env`. Create `ticket_reservation` for development and `ticket_reservation_test` for tests, then run:

```bash
php artisan migrate --seed
php artisan serve
```

The seeder creates two events and demo users `ahmed@example.com` and `sara@example.com`, both using password `password`.

## API

All routes are JSON under `/api`.

| Method | Endpoint | Access |
| --- | --- | --- |
| POST | `/register`, `/login` | public |
| GET | `/me` | Bearer token |
| POST | `/logout` | Bearer token |
| GET | `/events`, `/events/{event}`, `/events/{event}/seats` | public |
| POST | `/reservations` | Bearer token |
| GET | `/reservations`, `/reservations/{reservation}` | owner only |
| POST | `/reservations/{reservation}/pay` | owner only |
| POST | `/payments/webhook` | signed provider notification |

Register with `{"name":"Eman","email":"eman@example.com","password":"secret123","password_confirmation":"secret123"}`. The response returns a token shown once; send it as `Authorization: Bearer <token>`.

Create a reservation with `{"event_id":1,"seat_numbers":["A1","A2"]}`. The server calculates the total and returns a pending reservation that expires exactly 30 minutes later. If any seat is unavailable, the complete request fails with `409 SEAT_UNAVAILABLE` and no partial hold is written.

Start checkout with `POST /api/reservations/{id}/pay`. The default `PAYMENT_DRIVER=fake` returns a fake hosted URL and a reference. Complete it locally with:

```bash
php artisan payments:simulate success <payment_reference>
php artisan payments:simulate failure <payment_reference>
```

The webhook, rather than a browser redirect, is payment truth. Failed payment keeps the original hold. A retry before expiry creates a fresh payment attempt. A late successful notification completes only when all original seats are still free; otherwise the payment is recorded successful and the reservation remains expired and uncompleted. Refunds are outside this assessment's scope.

## Trying Paymob

To use Paymob instead of the fake gateway, add your Paymob credentials to `.env` and change `PAYMENT_DRIVER` to lowercase `paymob`:

```dotenv
PAYMENT_DRIVER=paymob
PAYMOB_SECRET_KEY=your_paymob_secret_key
PAYMOB_PUBLIC_KEY=your_paymob_public_key
PAYMOB_INTEGRATION_ID=your_payment_integration_id
PAYMOB_HMAC_SECRET=your_paymob_hmac_secret
PAYMOB_BASE_URL=https://accept.paymob.com
```

Use the credentials and integration ID for the same Paymob account and environment; use test credentials when experimenting. Add `PAYMOB_SECRET_KEY` explicitly if it is missing from your copied `.env.example`. The current hosted-checkout adapter uses the **secret key**, not `PAYMOB_API_KEY`.

`config/payment.php` already reads `PAYMENT_DRIVER` from `.env` and registers the `paymob` gateway, so no PHP configuration or business-logic changes are needed. Reload the configuration after editing `.env`:

```bash
php artisan config:clear
```

For an end-to-end payment test, also configure Paymob's transaction callback to send notifications to your application's publicly reachable HTTPS endpoint at `/api/payments/webhook`. A local-only address cannot receive Paymob callbacks; use a public development tunnel or a deployed test environment. The configured HMAC secret must match the one used to sign those notifications.

Create a new reservation, call `POST /api/reservations/{id}/pay`, and open `data.hosted_checkout_url` from the response to try checkout. The reservation is completed only after a matching, verified webhook is received, subject to the original hold and seat-availability rules. Valid credentials, an enabled Paymob integration, and working callback delivery are required; adding credentials alone does not verify the complete payment flow. The `payments:simulate` command is only for `PAYMENT_DRIVER=fake`.

To switch back to local simulation, set `PAYMENT_DRIVER=fake` and clear the configuration again.

## Errors

Known application errors use `{"success":false,"message":"...","error":{"code":"FORBIDDEN"}}`. Validation errors use `error.code=VALIDATION_ERROR` with field messages in `error.details`; seat conflicts include `error.details.seats`. See the [current response contract and its limits](docs/architecture.md#7-security-money-and-api-contracts).

| Case | Status |
| --- | --- |
| Missing or invalid token | 401 `UNAUTHENTICATED` |
| Another user's reservation | 403 `FORBIDDEN` |
| Missing event, reservation, or payment | 404 `NOT_FOUND` |
| Seat conflict | 409 `SEAT_UNAVAILABLE` |
| Completed or expired payment attempt | 409 `PAYMENT_NOT_ALLOWED` |
| Invalid input | 422 `VALIDATION_ERROR` with field messages in `error.details` |

## Design

See [Architecture and Design Decisions](docs/architecture.md) for the Spec Kit artifact links, request lifecycle, race-condition handling, webhook idempotency, Strategy and Factory patterns, and implementation limitations.

- Seat availability is derived from completed reservations and active pending holds. No flag or cleanup job can become stale.
- Reservation creation locks candidate MySQL seat rows, checks availability inside that transaction, then writes every requested seat or none.
- Integer EGP minor units are stored; API prices use exact two-decimal strings.
- `PaymentGateway` isolates payment providers. `FakePaymentGateway` is the local/test default; Paymob is an optional hosted-checkout adapter. Automated tests never call real payment services.
- Events and seats are seed-only. There is no frontend, admin, CRUD for events/seats, notifications, coupons, refunds, queues, schedulers, Redis, or Docker requirement.

## Verification

```bash
composer test
php artisan test -c phpunit-concurrency.xml
vendor/bin/pint --dirty --format agent
```

The normal suite and dedicated parallel-process concurrency suite both use `ticket_reservation_test` on MySQL. AI assistance was used for implementation and documentation; the test suite verifies the behavior described here.
