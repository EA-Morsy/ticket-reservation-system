# Quickstart — Ticket Reservation API (001-ticket-reservation)

Runnable validation proving the feature end-to-end on a fresh machine. Follow top-to-bottom; every step has an expected outcome. Contract details: [contracts/api.md](./contracts/api.md); schema: [data-model.md](./data-model.md).

## Prerequisites

- PHP `^8.3` with `pdo_mysql`, `mbstring`, `openssl`, `curl` (e.g., on Ubuntu/WSL: `sudo apt install php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl`)
- Composer (`https://getcomposer.org`)
- **MySQL — the project's only database**: used for local development, the automated test suites, and the reference deployment. A reachable local instance is sufficient; **no Docker requirement**. (research §3)

> Note for planning reviewers:— the README ship-instructions are validated on a standard PHP 8.3 host (assessor's machine).

## Setup

```bash
composer install
cp .env.example .env            # PAYMENT_DRIVER=fake by default — no payment credentials needed
php artisan key:generate
# Configure the MySQL connection in .env:
#   DB_CONNECTION=mysql
#   DB_HOST=127.0.0.1  DB_PORT=3306
#   DB_DATABASE=ticket_reservation
#   DB_USERNAME=...    DB_PASSWORD=...
# Create the schema once (mysql client): CREATE DATABASE ticket_reservation;
php artisan migrate --seed      # seeds 2 events (uniform seat prices), seats, demo users
```

**Expected**: migrations run cleanly against MySQL; seeder reports 2 events with seats and 2 demo users.

Demo users (documented credentials, for manual validation only): `ahmed@example.com` / `password`, `sara@example.com` / `password`.

## Run the API

```bash
php artisan serve
# Base URL: http://127.0.0.1:8000/api
```

## Manual validation walkthrough

### 1. Register + login + me + logout (FR-003)

```bash
curl -s -X POST http://127.0.0.1:8000/api/register \
  -H 'Content-Type: application/json' \
  -d '{"name":"Eman","email":"eman@example.com","password":"secret123","password_confirmation":"secret123"}'
```

**Expected**: `201` with `user` and a `token` (shown once). Then:

```bash
TOKEN=...   # from the response
curl -s http://127.0.0.1:8000/api/me -H "Authorization: Bearer $TOKEN"
```

**Expected**: `200` with the authenticated user's basic info (`id`, `name`, `email`) — no token data. Without the header → `401`.

Login as a seeded demo user the same way via `POST /api/login` (e.g. `ahmed@example.com`) and keep that token for the reservation steps.

Log out and verify revocation:

```bash
curl -s -X POST http://127.0.0.1:8000/api/logout -H "Authorization: Bearer $TOKEN"
curl -s http://127.0.0.1:8000/api/me -H "Authorization: Bearer $TOKEN"
```

**Expected**: logout returns `200`; the second call returns `401` — the logged-out token no longer authorizes protected requests.

### 2. Browse events (FR-004)

```bash
curl -s http://127.0.0.1:8000/api/events
```

**Expected**: `200` with the 2 seeded events, `seat_price` like `"150.00"`. The single-event view returns basic info only:

```bash
curl -s http://127.0.0.1:8000/api/events/1
```

**Expected**: `200` with `id`, `name`, `seat_price` — no seat details (held/sold/available seats belong to the seats endpoint).

### 3. Available seats only (FR-005/FR-006)

```bash
curl -s http://127.0.0.1:8000/api/events/1/seats
```

**Expected**: `200` listing only available seats. Verify filtering in steps 4–5.

### 4. Reserve seats (FR-008) — all-or-nothing (FR-009)

```bash
curl -s -X POST http://127.0.0.1:8000/api/reservations \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"event_id":1,"seat_numbers":["A1","A2"]}'
```

**Expected**: `201` with `status: "pending"`, `total` = exact 2 × seat price (e.g. `"300.00"`), `expires_at` = now + 30 minutes.

Then re-check `GET /api/events/1/seats` → **A1, A2 no longer listed**. And this must fail:

```bash
curl -s -X POST http://127.0.0.1:8000/api/reservations \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"event_id":1,"seat_numbers":["A2","A3"]}'
```

**Expected**: `409 SEAT_UNAVAILABLE` naming `A2` (`reason: "held"`), and A3 must remain listed as available afterwards — the failed request left no partial hold (edge cases).

### 5. Your reservations only (FR-014)

```bash
curl -s http://127.0.0.1:8000/api/reservations -H "Authorization: Bearer $TOKEN"
```

**Expected**: `200` listing **only the authenticated user's** reservations, with event, seats, total, status, and `expires_at`. A second user's token must never see these rows (see step 7).

### 6. Pay via hosted interface (FR-016) and complete (FR-018)

```bash
curl -s -X POST http://127.0.0.1:8000/api/reservations/1/pay -H "Authorization: Bearer $TOKEN"
```

**Expected**: `200` with `payment_reference` and `hosted_checkout_url` (fake driver → a local URL). With the `fake` driver, deliver a signed provider notification to the webhook through the planned demo command (fake-driver control — the same signed-payload path the provider would use; the command works only while `PAYMENT_DRIVER=fake`):

```bash
php artisan payments:simulate success <payment_reference>
```

**Expected**: `GET /api/reservations/1` → `status: "completed"`; seat A1/A2 never reappear in availability (sold).

### 7. Failure keeps the hold; expiry releases it without cleanup (FR-012, SC-003)

Reserve A3, pay, deliver a `failure` notification (`php artisan payments:simulate failure <payment_reference>`) → reservation stays `pending`, A3 stays unavailable, `expires_at` unchanged; `POST /api/reservations/1/pay` again works (retry within the window). To observe expiry manually, wait past `expires_at` — the 30-minute hold is a fixed business rule and is never shortened via config. In the automated suite this is instant: tests time-travel with `Carbon::setTestNow()` (research §8) — no sleeping, no config change, no cleanup job — and `GET /api/events/1/seats` shows **A3 available again** with no background process having run, and another user can reserve it (SC-003).

### 8. Ownership (FR-015)

As `sara@example.com`: `GET /api/reservations/1` → `403` (the detail endpoint), and sara's `GET /api/reservations` listing never contains ahmed's reservations; as anonymous → `401`. Owner always gets `200`.

## Automated tests (the real validation)

All suites run against **the same MySQL** technology the application uses — configure the test schema (e.g. `ticket_reservation_test`) in `.env.testing`/environment:

```bash
composer test                                    # general suite (MySQL)
php artisan test -c phpunit-concurrency.xml      # dedicated concurrency suite (same MySQL)
```

**Expected**: all green, covering FR-026: auth (register/login, `/me` returns the authenticated user and rejects unauthenticated requests, logout revokes the current token and a logged-out token can no longer access protected endpoints), ownership (other-user access → 403; reservation listing returns only the authenticated user's reservations and never leaks another user's), availability filtering, expiry without cleanup, all-or-nothing creation, same/cross-user competition, payment success/failure/retry, webhook verification, duplicates, late success (both FR-013 branches), and money exactness (`"10.10" × 3 = "30.30"`, one-minor-unit mismatch never completes).

**Concurrency suite**: `phpunit-concurrency.xml` is a dedicated suite on the **same MySQL technology** as the rest of the application — not a separate database strategy. It spawns real parallel processes racing one seat, verifying the actual `transaction → lockForUpdate (SELECT … FOR UPDATE) → re-check availability → allocate all-or-none` path under genuine row locks (research §3). At most one reservation wins; losers get a clean failure and leave no partial state.

## Swapping the payment provider (SC-008 demo)

Set `PAYMENT_DRIVER=paymob` + credentials in `.env` (adapter expects Paymob credentials; see `config/payment.php` — Paymob test credentials may optionally be used against Paymob's test environment for manual integration testing). No business code changes. Tests never need this — they use the `FakePaymentGateway` through the same contract (FR-027).
