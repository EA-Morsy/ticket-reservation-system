# Implementation Plan: Ticket Reservation API (001-ticket-reservation)

**Branch**: `001-ticket-reservation` (repo currently tracks all work on `main`; branch creation was blocked by a WSL git `safe.directory` restriction, documented in the specify step) | **Date**: 2026-09-18 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/001-ticket-reservation/spec.md`

## Summary

Backend-only REST API for a ticket reservation assessment: users register/authenticate, browse seeded events, view only currently-available seats, create an all-or-nothing 30-minute reservation on one event's seats, and pay through a third-party **hosted** payment page; verified provider notifications complete (or fail) the reservation with idempotent, expiry-aware semantics. Technical approach (from research): Laravel 13 on PHP 8.3 with Sanctum token auth, **MySQL as the sole relational database** across local development, the automated test suites, and the reference deployment, availability always derived from stored reservation rows + `expires_at` (no cleanup dependency), allocation performed in one transaction (lock candidate seat rows → re-check availability → allocate all or none) with real row-level locks on that same MySQL engine — verified by a dedicated concurrency suite using real parallel processes, and a provider-independent `PaymentGateway` contract with a Paymob hosted-checkout adapter and a fake driver for local runs and tests. Money is stored as integer minor units (EGP, 2 decimals).

## Technical Context

**Language/Version**: PHP `^8.3` (matches `composer.json`; — install steps in [quickstart.md](./quickstart.md))

**Primary Dependencies**: Laravel `^13.17`; Laravel Sanctum (first-party API token auth, added via `php artisan install:api`); MySQL server (the sole database — reachable local instance, **no Docker requirement**); PHPUnit `^12.5` + Mockery (already in `require-dev`); Laravel HTTP client for the provider's REST API (**no payment SDK** — Principle III/IX of the constitution)

**Storage**: **MySQL as the only relational database** — used for local development, the general PHPUnit suite, the concurrency suite, and the production/reference deployment. A reachable local MySQL instance is sufficient; **no Docker requirement**. One database strategy, one engine: `lockForUpdate()` compiles to real `SELECT … FOR UPDATE` everywhere the application runs ([research.md §3](./research.md)). **No message broker, no Redis** (constitution Principles I/III).

**Testing**: PHPUnit via `composer test`, **all suites against MySQL** (`RefreshDatabase` per test against a dedicated MySQL test schema); the dedicated concurrency suite (`phpunit-concurrency.xml`) uses the **same MySQL technology** with real parallel OS processes (Symfony `Process`) racing the same seat — verifying the actual `transaction → lockForUpdate → re-check availability → allocate all-or-none` path under genuine row locks; payment provider simulated via a `FakePaymentGateway` bound to the `PaymentGateway` contract; webhook scenarios use crafted, correctly-signed payloads (Principles IX–X).

**Target Platform**: Linux (WSL Ubuntu) dev machine; runs with `php artisan serve` — a plain PHP 8.3 host.

**Project Type**: HTTP API service (backend only — constitution Principle II).

**Performance Goals**: none imposed by the assessment; correctness (concurrency safety, expiry semantics, idempotency) is the evaluated dimension.

**Constraints**: single fixed currency (EGP, 2-decimal minor units — `config/payment.php`); 30-minute fixed hold duration; must run end-to-end without real payment credentials (default payment driver is `fake`); all state transitions enforced server-side; no background scheduler is required for correctness (availability never depends on a job having run — Principle V);MySQL is the sole relational database for local development,
the general automated test suite, the dedicated concurrency suite,
and the production/reference deployment.

**Scale/Scope**: 12 endpoints (4 auth: register, login, logout, me; 3 events; 3 reservations incl. the owner's listing; 2 payments), 6 business tables (users, events, seats, reservations, reservation_seat, payments) plus Sanctum's `personal_access_tokens`, 1 payment adapter + 1 fake driver + a fake-driver demo command, seed data (2 events, a handful of seats each, demo users), test suite covering FR-026 behaviors plus the concurrency suite.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-checked after Phase 1 design — status unchanged.*

| Principle | Status | Evidence / Notes |
|---|---|---|
| I. Scope Discipline | ✅ PASS | No admin, no notifications, no refunds, no coupons, no multi-currency, no roles beyond ownership, no broker/Redis/K8s. Every planned artifact traces to a spec FR or constitution principle. Seed-only events/seats (FR-004/007). |
| II. Backend API Only | ✅ PASS | Deliverables: routes/api, controllers, models, migrations, seeders, services, tests, docs. No Blade/Vite work; skeleton's front-end scaffolding untouched (constitution II). |
| III. Simplicity Over Over-Engineering | ✅ PASS | Controllers + Form Requests + Eloquent + one Policy + two small service classes. No repositories, DTO layers, managers, factories beyond Laravel's, or event architecture. The single abstraction (`PaymentGateway` contract) is mandated by the assessment and Principle VII. Money as integers, no money library. |
| IV. Data Integrity & Concurrency Safety (NON-NEGOTIABLE) | ✅ PASS | Allocation runs inside one DB transaction per request: lock candidate seat rows (`lockForUpdate`) → re-check availability inside the lock → allocate all seats or none. Concurrent correctness is **verified, not assumed**: the dedicated concurrency suite uses the same MySQL engine the application runs on, with real parallel processes racing the same seat and exercising real `SELECT … FOR UPDATE` row locks. One database everywhere — tests are production-compatible by construction (research.md §3). |
| V. Reservation Expiration | ✅ PASS | `expires_at` column is the sole source of truth; availability is a query predicate evaluated at request time (`status = pending AND expires_at > now`). **No scheduled job exists in the design at all** — expired holds can't be stuck by a missed scheduler because there is nothing to miss. |
| VI. Available Seats | ✅ PASS | The seats endpoint returns only seats passing the availability predicate (FR-005/FR-025); no held/sold endpoints exist. |
| VII. Payment Abstraction | ✅ PASS | `App\Contracts\PaymentGateway` (initiate hosted checkout + verify/normalize notification) bound in the container; `PaymobGateway` adapter is the only provider-aware class; business logic (PaymentService) depends on the contract only. Failed payment retains the hold until `expires_at`; retries within the window; late success per FR-013 — encoded in PaymentService, tested. |
| VIII. Authentication & Authorization | ✅ PASS | Sanctum tokens; reservation/payment routes under `auth:sanctum`; `ReservationPolicy` enforces server-side ownership (view, pay). Webhook route is the transport-level exception, verified per XI. |
| IX. Automated Testing | ✅ PASS | Suite plan covers every behavior listed in FR-026/SC-001…010, including all-or-nothing creation, cross-user competition, expiry without cleanup, duplicate/late webhooks, retry rules, and money exactness. No real provider calls (X). |
| X. Testability | ✅ PASS | `FakePaymentGateway` (default local driver) implements the contract; tests bind fakes via the container; webhook tests craft signed payloads. |
| XI. Security | ✅ PASS | Validation via Form Requests; HMAC signature verification on the provider callback; idempotent processing keyed on provider transaction id; no provider secrets in responses/logs (contract documents the sanitization). Ownership enforced by Policy, never client input. |
| XII. Documentation | ✅ PASS | README planned with setup, API usage, design decisions, assumptions, testing; contracts/ + quickstart.md carry the runnable validation scenarios; hosted-flow frontend role documented without client code. |
| XIII. AI-Assisted Development | ✅ PASS | All artifacts written to be explainable; README will include the AI-usage explanation required by the assessment email. |

## Project Structure

### Documentation (this feature)

```text
specs/001-ticket-reservation/
├── plan.md              # This file (/speckit.plan command output)
├── research.md          # Phase 0 output (/speckit.plan command)
├── data-model.md        # Phase 1 output (/speckit.plan command)
├── quickstart.md        # Phase 1 output (/speckit.plan command)
├── contracts/           # Phase 1 output (/speckit.plan command)
│   ├── api.md           # HTTP API contract (endpoints, payloads, status codes, errors)
│   └── payment-gateway.md  # Provider-independent payment boundary contract
└── tasks.md             # Phase 2 output (/speckit.tasks command - NOT created by /speckit.plan)
```

### Source Code (repository root)

Standard Laravel single-app layout (Option: Laravel API service — skeleton already present; no new top-level directories):

```text
app/
├── Contracts/
│   └── PaymentGateway.php            # provider-independent boundary (constitution VII)
├── Http/
│   ├── Controllers/Api/
│   │   ├── AuthController.php        # register / login / logout / me
│   │   ├── EventController.php       # list events, show event, available seats
│   │   ├── ReservationController.php # create, own-reservation listing, own-reservation detail
│   │   └── PaymentController.php     # initiate payment, provider webhook
│   └── Requests/
│       ├── RegisterRequest.php
│       ├── LoginRequest.php
│       └── CreateReservationRequest.php
├── Models/
│   ├── User.php
│   ├── Event.php
│   ├── Seat.php
│   ├── Reservation.php
│   └── Payment.php
├── Payments/                          # the only provider-aware namespace
│   ├── PaymobGateway.php              # hosted checkout adapter (REST via HTTP client, no SDK)
│   ├── FakePaymentGateway.php         # default/recommended driver for local runs and all tests
│   ├── PaymentIntent.php              # value object: reference + hosted checkout URL
│   └── PaymentNotification.php        # value object: verified, normalized outcome
├── Policies/
│   └── ReservationPolicy.php          # server-side ownership (FR-015)
├── Console/Commands/PaymentSimulate.php  # fake-driver demo control for quickstart (fake driver only)
├── Providers/AppServiceProvider.php   # binds PaymentGateway -> configured driver
└── Services/
    ├── ReservationService.php         # all-or-nothing, concurrency-safe allocation
    └── PaymentService.php             # outcome application: complete / record failure / FR-013 late rules

bootstrap/app.php                      # registers api routes + auth:sanctum middleware
config/payment.php                     # driver, currency (EGP), minor-unit precision, provider creds from env
database/
├── migrations/                        # events, seats, reservations, reservation_seat, payments (+ sanctum tokens)
└── seeders/                           # 2 events with uniform seat prices, seats, demo users
routes/api.php
phpunit-concurrency.xml                # dedicated concurrency suite (same MySQL)
tests/
├── Feature/                           # behavior suite (RefreshDatabase, MySQL test schema)
│   ├── AuthTest.php
│   ├── EventsAndSeatsTest.php
│   ├── ReservationCreationTest.php
│   ├── ReservationExpiryTest.php
│   ├── OwnershipTest.php
│   ├── PaymentFlowTest.php            # initiate/success/failure/retry
│   ├── PaymentWebhookTest.php         # signature, idempotency, late/duplicate
│   └── MoneyTest.php                  # totals, precision, mismatch
└── Feature/Concurrency/               # dedicated concurrency suite (same MySQL) + real parallel processes
    └── ConcurrentReservationTest.php
```

**Structure Decision**: keep the stock Laravel layout — no new top-level dirs, no separation beyond Laravel's conventional folders. The single deliberate boundary is `app/Payments/` (provider-aware) vs `app/Contracts/PaymentGateway.php` (the boundary itself) vs `app/Services/` (provider-agnostic business logic), so the "swap the provider without touching business logic" requirement (SC-008) is structurally visible and explainable.

## Complexity Tracking

No constitution violations. For transparency: the one abstraction beyond stock Laravel is the `PaymentGateway` contract (Principle VII / FR-017) — required by the assessment, therefore not a violation. The single-database decision (MySQL everywhere — development, tests, concurrency suite, reference deployment; reachable local instance, no Docker) is documented under Principle IV above and in research.md §3.
