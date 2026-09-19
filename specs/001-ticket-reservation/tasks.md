# Tasks: 001-ticket-reservation — Ticket Reservation API

**Input**: Design documents from `/specs/001-ticket-reservation/`
**Prerequisites**: plan.md ✅ | spec.md ✅ | research.md ✅ | data-model.md ✅ | contracts/api.md ✅ | contracts/payment-gateway.md ✅ | quickstart.md ✅ | constitution.md ✅

**Tests**: REQUIRED for this feature — the constitution (Principle IX) and spec (FR-026, SC-007) mandate automated tests as a deliverable. Test tasks are placed before or immediately adjacent to their implementation tasks (test-first where practical).

**Organization**: 13 implementation phases as ordered in the planning prompt. User-story tags ([US1]–[US4]) mark traceability inside phases; the full story→task mapping is at the end.

**Confirmed rules frozen in these tasks** (do not reopen while implementing): failed payment retains the original hold until the original `expires_at` and never restarts/extends it; retry only while the original hold is active; late verified success = fresh all-or-none acquisition of ALL original seats, else success recorded without completion; duplicate notifications are no-ops — including concurrent duplicate delivery (applied exactly once; the losing duplicate re-reads the recorded outcome and answers the normal no-op); `expired` is derived from `expires_at` (never persisted, no cleanup job); MySQL is the only database; business logic depends only on the `PaymentGateway` contract; `FakePaymentGateway` is the default for tests/local.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: different files, no dependency on an incomplete task
- **[US1]** browse events & available seats (P1) · **[US2]** reserve + hosted payment (P1) · **[US3]** ownership & auth surface (P2) · **[US4]** payment notifications (P2)

## Path Conventions

Stock Laravel 13 layout (plan.md §Project Structure): `app/Http/Controllers/Api/`, `app/Http/Requests/`, `app/Models/`, `app/Services/`, `app/Contracts/`, `app/Payments/`, `app/Policies/`, `app/Console/Commands/`, `database/migrations|seeders|factories/`, `routes/api.php`, `tests/Feature/`, `tests/Feature/Concurrency/`.

---

## Phase 1 — Project and environment foundation

**Purpose**: API bootstrap, Sanctum, MySQL as the sole database (dev + tests), payment config without secrets.

- [x] T001 Enable the API surface + Sanctum: run `php artisan install:api`; verify `bootstrap/app.php` wires api routes and that `routes/api.php` exists; confirm Sanctum's `personal_access_tokens` migration is present; change nothing in `resources/`/`routes/web.php` (constitution II).
- [x] T002 Configure MySQL as the only database: set `DB_CONNECTION=mysql` (host/port/database/credentials via env) in `.env.example` and `config/database.php` defaults; document creating the dev schema (`ticket_reservation`) and the test schema (`ticket_reservation_test`) — reachable local MySQL instance, no Docker requirement (research.md §3).
- [x] T003 [P] Create `config/payment.php`: `driver` (default `fake`), `currency` = `EGP`, `minor_units` = 2, `providers.paymob.*` read exclusively from env (`PAYMOB_API_KEY` etc., empty defaults). The 30-minute hold is a fixed business rule (FR-010) — do NOT add a configurable hold duration. No secrets committed (FR-024).
- [x] T004 [P] Test configuration: update `phpunit.xml` to run against the MySQL test schema (no legacy embedded/file-database env entries remain); create `phpunit-concurrency.xml` for the dedicated concurrency suite (`tests/Feature/Concurrency`) using the same MySQL technology; document `.env.testing` values (research.md §3).

**Checkpoint**: `php artisan migrate` works against MySQL; `php artisan test` runs (empty suites pass) on the MySQL test schema.

---

## Phase 2 — Core data model

**Purpose**: Migrations, models, factories, seeders exactly per data-model.md. Migrations execute in strict FK dependency order (T005 → T006/T007 → T008/T009) — their generated timestamps/filenames MUST ensure referenced tables exist before dependent foreign keys apply; models, factories and seeders depend on the finished schema.

- [x] T005 Migration `events` (FIRST — no Phase 2 dependencies): `id`, `name` (string), `seat_price_minor` (integer, one uniform price for all seats of the event — FR-029), timestamps. No currency column (single fixed currency in config).
- [x] T006 Migration `seats` (depends on T005 — FK → events): `event_id` (FK → events, cascade, indexed), `number` (string label e.g. `A1`), `UNIQUE(event_id, number)`, timestamps. **No seat status/availability column** — availability is derived (data-model.md).
- [x] T007 Migration `reservations` (depends on T005 — FK → events; FK → users from the existing Laravel/Sanctum table): `user_id` (FK, indexed), `event_id` (FK, indexed), `status` (string — stored values ONLY `pending` | `completed`), `total_minor` (integer, immutable after creation), `expires_at` (datetime, **indexed** — the sole source of truth for the hold), timestamps (FR-008/010, data-model.md).
- [x] T008 Migration `reservation_seat` pivot (depends on T007 — reservations — and T006 — seats): `reservation_id` (FK, cascade), `seat_id` (FK, cascade, indexed), `UNIQUE(reservation_id, seat_id)` — distinct seats per reservation (FR-008).
- [x] T009 Migration `payments` (depends on T007 — reservations): `reservation_id` (FK, indexed — many attempts per reservation, FR-012), `provider` (string, provenance only), `reference` (string, UNIQUE — our payment-intent reference), `provider_transaction_id` (string, UNIQUE, nullable — the idempotency anchor, FR-020), `amount_minor` (integer), `currency` (string, length 3), `status` (`pending` | `success` | `failed`), `paid_at` (nullable datetime), timestamps.
- [x] T010 [P] Models `app/Models/Event.php` + `app/Models/Seat.php`: Event `hasMany` seats, `seat_price_minor` cast; Seat `belongsTo` event. No availability flag — availability is expressed later as a query (Phase 4).
- [x] T011 Models `app/Models/Reservation.php` + `app/Models/Payment.php` (+ `HasApiTokens` on `app/Models/User.php`): Reservation `belongsTo` user/event, `belongsToMany` seats, `hasMany` payments, and a **derived** status accessor — `pending` while `expires_at > now`, else `expired`; `completed` when stored `completed`. `total_minor`, `expires_at` never mutated after creation (FR-010/012).
- [x] T012 Factories (depends on T010–T011 — factories reference the model classes): `database/factories/EventFactory.php`, `SeatFactory.php`, `ReservationFactory.php`, `PaymentFactory.php` — minimal valid states for tests (reservation factory can produce pending/completed and arbitrary `expires_at`).
- [x] T013 Seeders: `database/seeders/EventSeeder.php` (2 events with uniform seat prices in minor units + a small numbered seat grid each — e.g. concert + theatre), `database/seeders/DemoUserSeeder.php` (documented demo users `ahmed@example.com` / `sara@example.com`, password `password`), wired in `database/seeders/DatabaseSeeder.php` (quickstart.md).

**Checkpoint**: `php artisan migrate --seed` on MySQL creates all 6 business tables + Sanctum tokens; seeded data matches quickstart expectations.

---

## Phase 3 — Authentication (US3)

**Goal**: register / login / me / logout with Sanctum Bearer tokens (FR-001/003, contract api.md).

**Independent Test**: register → receive token → `/me` returns the user → logout → same token gets 401.

- [x] T014 [P] [US3] Test-first `tests/Feature/AuthTest.php`: registration succeeds (201 + user + token); duplicate email rejected 422; invalid registration rejected 422; login succeeds (200 + token); bad credentials rejected; `GET /api/me` returns the authenticated user's basic info (no token data); `/me` rejects unauthenticated 401; `POST /api/logout` revokes **only** the current token; the revoked token can no longer access protected routes 401. (FR-003)
- [x] T015 [P] [US3] `app/Http/Requests/RegisterRequest.php` + `app/Http/Requests/LoginRequest.php`: name/email unique/confirmed password min-length per README-documented defaults; no password-reset/email-verification/social fields (scope).
- [x] T016 [US3] `app/Http/Controllers/Api/AuthController.php` (register/login/me/logout) + routes in `routes/api.php`: register & login issue Sanctum plain-text tokens (shown once); `me` returns basic account info; `logout` calls `$request->user()->currentAccessToken()->delete()` (current token only — no logout-all-devices). Register → 201; login/me/logout → 200. Depends on T014–T015.

**Checkpoint**: `php artisan test --filter=AuthTest` green (tests T014 written first fail before T016).

---

## Phase 4 — Events and seat availability (US1)

**Goal**: public browsing; seats endpoint returns ONLY currently available seats (FR-004/005/006/025, Principle VI).

**Independent Test**: seed → list events → show event → seats endpoint reflects holds/sales/expiry exactly.

- [x] T017 [P] [US1] Test-first `tests/Feature/EventsAndSeatsTest.php`: `GET /api/events` lists seeded events (id, name, seat_price as exact 2dp decimal string); `GET /api/events/{id}` returns basic info + seat price only — **no seat details**; unknown event → 404; `GET /api/events/{id}/seats` returns only available seats; a seat under an active hold (`expires_at > now`) is hidden; a seat in a completed reservation is hidden; after Carbon time-travel past `expires_at` (no cleanup run, nothing else executed) the seat reappears; unknown event on seats endpoint → 404 (never an empty list). (FR-006/FR-011/SC-003)
- [x] T018 [US1] `app/Http/Controllers/Api/EventController.php` (index, show, seats) + routes: availability derived at query time — exclude seats covered by a completed reservation OR a pending reservation with `expires_at > now`; expired pending reservations never block (no jobs, no stored flags). Formatting via minor units → exact decimal string (FR-030). Depends on T017.

**Checkpoint**: `php artisan test --filter=EventsAndSeatsTest` green; held/sold seats never listed.

---

## Phase 5 — Reservation creation and ownership (US2 / US3)

**Goal**: all-or-nothing, concurrency-safe creation with server-computed totals (FR-008/009/010/029/030) + ownership policy (FR-015).

**Independent Test**: create reservation → seats hidden; conflicting/mixed requests → 409 with per-seat reasons and zero residual state.

**Creation flow (frozen)**: 1) begin transaction → 2) lock candidate seat rows (`lockForUpdate`) → 3) re-check availability inside the lock → 4) reject the WHOLE request if any seat is unavailable → 5) create reservation + pivot rows only if every seat is available → 6) commit atomically.

- [x] T019 [P] [US2] Test-first `tests/Feature/ReservationCreationTest.php`: successful creation 201 (status `pending`, seats, `expires_at` = now + 30 min exactly); duplicate `seat_numbers` 422; unknown seat 422; seats from another event 422; empty/missing selection 422; actively held seat → 409 `SEAT_UNAVAILABLE` with per-seat reason `held`; sold seat → 409 reason `sold`; mixed available/unavailable → 409, NO reservation created, non-conflicting seats of the failed request NOT held (all-or-nothing, FR-008); same user retrying an already-held seat → 409 (FR-009); unauthenticated → 401.
- [x] T020 [P] [US2] Test-first `tests/Feature/MoneyTest.php`: total = distinct seat count × event price exactly in minor units — `"10.10" × 3 = "30.30"` (3030) — no float drift (FR-030); response totals formatted as exact 2dp decimal strings; client has NO price/total input to tamper with (FR-029).
- [x] T021 [US2] `app/Http/Requests/CreateReservationRequest.php`: `event_id` must exist; `seat_numbers` required array, min 1, distinct; every number must be a seat of that event (cross-event/unknown → 422, never silently ignored — FR-023).
- [x] T022 [US2] `app/Services/ReservationService.php` — `create()`: implements the frozen 6-step flow; total computed server-side (`count(distinct seats) × event.seat_price_minor`); `expires_at = now + 30 minutes` fixed; rejection payload lists every unavailable seat with reason `held`|`sold` (never WHO holds it). Depends on T005–T011, T021.
- [x] T023 [US2] `app/Http/Controllers/Api/ReservationController.php` — `store()` wired to the service + route `POST /api/reservations` (`auth:sanctum`); renders the contract's 409 `SEAT_UNAVAILABLE` / 422 / 201 shapes exactly. Depends on T019–T022.
- [x] T024 [P] [US3] `app/Policies/ReservationPolicy.php` (view, pay → `user_id` ownership) registered for auto-discovery — server-side enforcement, never client input (FR-015). Depends on T011.

**Checkpoint**: creation suite green; failed requests leave no partial reservations or holds (verify in T019 assertions).

---

## Phase 6 — Reservation read APIs (US2 / US3)

**Goal**: owner-scoped listing + owner-only detail; derived `expired` status (FR-014/015, contract api.md).

**Independent Test**: two users, two reservations → each listing sees only its own; cross-user detail → 403; time-travel → status `expired` while DB row still `pending`.

- [x] T025 [P] [US3] Test-first `tests/Feature/ReservationListingTest.php`: `GET /api/reservations` (authenticated) returns ONLY the current user's reservations with summary fields (id, event_id, seats, total, status, expires_at, `last_payment` summary per contract); another user's reservations NEVER appear; unauthenticated → 401. (FR-014)
- [x] T026 [P] [US3] Test-first `tests/Feature/OwnershipTest.php`: detail `GET /api/reservations/{reservation}` owner → 200; other authenticated user → **403 `FORBIDDEN`** (plain, per the revised contract — no existence-hiding claims); unauthenticated → 401; derived status shows `pending` → `expired` after Carbon time-travel with the DB row still `pending` and `expires_at` unchanged (Principle V).
- [x] T027 [US2] `ReservationController` — `index()` (owner-scoped listing) + `show()` (owner-only detail with derived status + `last_payment` summary) + routes `GET /api/reservations`, `GET /api/reservations/{reservation}` (route-model binding + policy authorize). Depends on T024–T026.

**Checkpoint**: read APIs green; no cross-user leakage anywhere.

---

## Phase 7 — Payment abstraction and fake gateway (US2)

**Goal**: the provider-independent boundary (Principle VII, FR-017, SC-008) + default fake driver.

**Independent Test**: container resolves the configured driver; business code has zero provider-specific references.

- [x] T028 [P] [US2] `app/Contracts/PaymentGateway.php` (initiate / verifyNotification) + `app/Payments/PaymentIntent.php` + `app/Payments/PaymentNotification.php` value objects — exactly per contracts/payment-gateway.md (reference, hostedCheckoutUrl; reference, providerTransactionId, status, amountMinor, currency, paidAt). Amounts in integer minor units everywhere (FR-030).
- [x] T029 [US2] `app/Payments/FakePaymentGateway.php` (depends on T028 — implements the contract and uses the VOs): deterministic hosted checkout URL; signed notification generation using the same scheme the webhook verifies (so webhook code paths run end-to-end with zero credentials); deterministic success/failure control signal. Default/recommended driver for tests and local runs (FR-027, constitution X).
- [x] T030 [US2] Binding in `app/Providers/AppServiceProvider.php`: `PaymentGateway::class` → `config('payment.driver')` implementation; `PAYMENT_DRIVER=fake` default in `.env.example`. Business logic will depend only on the contract (verified again in T049). Depends on T028–T029, T003.

**Checkpoint**: `app()->make(PaymentGateway::class)` resolves `FakePaymentGateway` with default config.

---

## Phase 8 — Paymob adapter (US2)

**Goal**: the concrete real hosted-checkout adapter, fully isolated behind the contract (research.md §1, payment-gateway.md).

**Independent Test**: adapter unit tests with fixture payloads — signature verified/rejected, responses normalized to the contract VOs; no external network in tests.

- [x] T031 [P] [US2] `app/Payments/PaymobGateway.php` — `initiate()`: build the hosted-checkout intent via Laravel HTTP client only (no SDK); credentials from `config/payment.php` env (empty defaults); never log or return secrets (FR-024); convert minor units ↔ provider units losslessly — reject non-lossless values (FR-030). Docblock notes Paymob is an implementation choice, not an assessment requirement.
- [x] T032 [US2] `PaymobGateway::verifyNotification()` + unit tests `tests/Unit/PaymobGatewayTest.php`: test `initiate()` with Laravel `Http::fake()` / `Http::assertSent()` only — assert configured Paymob credentials/config and expected HTTP request usage, lossless conversion of reservation integer minor units to provider units, successful-response normalization into the provider-independent `PaymentIntent` (internal/payment reference + hosted checkout URL), and safe handling of upstream errors, malformed responses, or missing required response data with no credential/secret leakage and no real network request. Also cover HMAC-SHA512 verification of the transaction callback — **verify the exact callback/HMAC field set and ordering against the official Paymob documentation during implementation; do not assume all Paymob callbacks share one field set** (research.md §1); invalid/missing signature → `null` (endpoint answers 403, no state change); normalize to `PaymentNotification`; fixture-driven only — no network. Depends on T028, T031.

**Checkpoint**: adapter passes unit tests offline; nothing Paymob-specific exists outside `app/Payments/`.

---

## Phase 9 — Payment initiation and lifecycle (US2)

**Goal**: `POST /api/reservations/{reservation}/pay` with confirmed retry/expiry rules (FR-012/016/018, contract api.md).

**Independent Test**: pay on pending → reference + hosted URL; completed/expired → 409; failed-payment retry keeps seats/total/`expires_at` unchanged.

- [x] T033 [P] [US2] Test-first `tests/Feature/PaymentFlowTest.php`: owner pays a pending reservation → 200 with `payment_reference` + `hosted_checkout_url` + reservation `expires_at`; gateway-initiation failure using a forced-failing `PaymentGateway` fake/mock → appropriate safe/non-sensitive error, NO usable `payments` row, reservation remains `pending`, seats/`total_minor`/`expires_at` unchanged, no seat released, no real external request, and no provider credentials/secrets in the response; non-owner → 403; unauthenticated → 401; pay on completed → 409 `PAYMENT_NOT_ALLOWED`; pay on expired (Carbon time-travel) → 409 and the hold is NOT restored; retry after verified failure while still within the original window → allowed, same reservation, seats/total unchanged, `expires_at` unchanged, a NEW payment attempt row created (FR-012); amount/currency always from server-side reservation data (FR-029); retry-total stability assertion (FR-026).
- [x] T034 [US2] `app/Services/PaymentService.php` — `initiate()` in EXACTLY this order: (1) authorize the reservation owner via policy (T024); (2) verify the reservation is stored `pending` AND not expired → else 409 `PAYMENT_NOT_ALLOWED`; (3) take the trusted server-side `total_minor` and configured currency (FR-029); (4) call `PaymentGateway::initiate($reservation)` (the contract — never a concrete adapter); (5) receive a valid provider-independent `PaymentIntent` carrying the payment **reference** and the hosted checkout URL; (6) ONLY AFTER successful gateway initiation, persist the new payment-attempt row (`reservation_id`, `provider`, the RETURNED reference, `total_minor`, configured currency, `status = pending`); (7) return `payment_reference` + `hosted_checkout_url` + reservation `expires_at`. Explicit constraints: do NOT persist a normal usable payment row before a valid reference exists (the `payments.reference` column is UNIQUE NOT NULL — T009); if gateway initiation fails, NO usable orphan payment attempt remains; initiation NEVER changes reservation seats, total, or `expires_at`; retry creates a fresh attempt only after the new gateway intent succeeds (FR-012); PaymentService depends only on `PaymentGateway` — never on Paymob directly. No extra payment state and no temporary table. `app/Http/Controllers/Api/PaymentController.php` — `pay()` + route `POST /api/reservations/{reservation}/pay`. Depends on T030, T033.

**Checkpoint**: initiation suite green with the fake driver; no external calls.

---

## Phase 10 — Payment webhook (US4)

**Goal**: provider-to-server notifications — verified, matched, idempotent, expiry-aware (FR-018/019/020/013, FR-012; data-model state machines).

**Independent Test**: signed success completes; duplicates no-op; late success follows the frozen FR-013 branch exactly.

**Frozen lifecycle rules**: success before expiry → payment `success` + reservation `completed` + seats permanently sold. Verified failure → payment `failed`, reservation untouched, seats held until the original `expires_at` (no early release, no restart/extension; retry still allowed within the window). Late verified success → record success, then ONE fresh concurrency-safe check of ALL original seats: all available → acquire all atomically + complete; ANY unavailable → acquire none, success recorded, reservation left uncompleted, no new hold, `expires_at` untouched. Duplicate delivery after a processed outcome → 200 no-op, never retries acquisition — including when the SAME notification arrives CONCURRENTLY: the outcome is applied exactly once, the losing duplicate re-reads the recorded payment and answers the normal no-op (never a 500, never a second side effect).

- [x] T035 [P] [US4] Test-first `tests/Feature/PaymentWebhookTest.php` (crafted, correctly-signed payloads — no user token, no network): valid signed success before expiry → completed + seats sold; invalid HMAC/signature → 403, no state change; unknown reference → 404, no state change; malformed payload → rejected safely (no crash, no change); amount mismatch by one minor unit → recorded but NOT completed (FR-030); currency mismatch → NOT completed; verified failure → payment failed, reservation pending, seats still hidden before expiry; delayed failure after completion → completion NOT undone (FR-020); duplicate success → no-op; duplicate failure → no-op; late success with ALL original seats available → completed + acquired (FR-013); late success with one seat taken (new reservation after expiry) → payment success recorded, reservation uncompleted, zero seats acquired, the other reservation unchanged; duplicate delivery of a processed late success after availability changed → outcome preserved, no retry; **concurrent duplicate delivery** — two simultaneous requests carrying the SAME payment reference, provider transaction ID, valid signature, and outcome → both complete without an unhandled error; exactly one applies the business outcome (payment state changed once, ONE reservation transition, no duplicate seat allocation, no second late acquisition, no duplicate payment attempt); the other observes the already-applied outcome and returns the normal no-op 200; NO 500. Ordinary sequential duplicates are covered here; the truly-simultaneous execution evidence lives in the dedicated concurrency suite (T040). (SC-004/SC-005)
- [x] T036 [US4] `app/Http/Controllers/Api/PaymentController.php` — `webhook()` + route `POST /api/payments/webhook` (NO auth middleware) + `PaymentService::applyNotification()` — provider-neutral throughout: never reference `PaymobGateway`, Paymob callback field names, HMAC field ordering, Paymob URLs, or Paymob credentials — provider specifics live ONLY inside `PaymentGateway::verifyNotification()` implementations (fake: T029; Paymob: T032); the service receives the same normalized `PaymentNotification` either way. Processing sequence: FIRST (before the business transaction) receive the notification → `verifyNotification()` → reject invalid/unverifiable with the documented response and NO state change → extract the normalized payment reference, provider transaction ID, status, amount, currency. THEN, inside ONE database transaction: (5) load the matched `Payment` row by its internal reference **with `lockForUpdate()`** — this payment-row lock is the PRIMARY serialization point for concurrent duplicate delivery of the SAME notification (both duplicates locate the same attempt by the same reference, so the row lock orders them); (6) RE-READ the payment's persisted state after acquiring the lock; (7) if this provider transaction/outcome was ALREADY processed → run NO reservation business logic, NO re-completion, NO second late seat acquisition, NO expiry change, NO new payment record — return the normal idempotent 200 no-op; (8) if first processing → validate amount/currency against the trusted reservation values, apply the outcome EXACTLY ONCE, persist provider transaction ID/status/`paid_at`, and apply reservation state changes atomically. Outcome rules: success-before-expiry → payment `success` (+ provider transaction ID, `paid_at` where appropriate) + reservation `pending → completed` committed together as ONE business outcome; `expires_at` untouched; no seat relations created or duplicated — completion itself makes the already-held seats permanently unavailable; a post-commit duplicate observes only the successful state and no-ops. Verified failure → mark the matched payment `failed` (store the provider transaction ID per the normalized contract), reservation status UNCHANGED, seats NOT released early, `expires_at` NOT changed/extended/restarted — the hold stands until the original expiration; a duplicate failure is a no-op; a delayed failure after completion never undoes it or releases sold seats. **Late success** → after recording success on the locked payment row, perform a fresh concurrency-safe check of ALL original seats in a locked transaction (`lockForUpdate` on the candidate seat rows), evaluating availability AFTER the locks: ALL available → acquire/retain all original seats atomically + mark the reservation completed + persist payment success, committed together; ANY unavailable → acquire NONE, payment stays recorded successful, reservation stays uncompleted/expired, ZERO partial allocation, existing reservations unchanged, original `expires_at` unchanged. The payment-row idempotency check (step 7) ALWAYS precedes any late seat-allocation logic, so a duplicate delivery can never re-acquire; reuse the SAME seat-allocation path/helper as ReservationService (research.md §6) — no second allocation algorithm. Secondary safety net: the `UNIQUE(provider_transaction_id)` constraint (T009) remains the final database-level defense against accidental reuse of a provider transaction across another row; if a uniqueness race still occurs (another transaction committed the same ID first), treat it as an EXPECTED idempotency race — catch it, re-read the committed outcome, return the normal duplicate/no-op — NO 500 may surface from an expected duplicate race, and no business effect repeats. No Redis/distributed locks, no webhook ledger table, no queues, no background deduplication jobs, no application-wide mutexes — database-backed and simple. Depends on T029, T030, T034, T035.
- [x] T037 [US4] `app/Console/Commands/PaymentSimulate.php` — `payments:simulate {outcome} {reference}`: fake-driver ONLY (refuses otherwise), builds a properly signed notification and delivers it to the webhook endpoint — the quickstart demo mechanism (quickstart.md steps 6–7). Depends on T036.

**Checkpoint**: webhook suite green; every frozen lifecycle rule has a passing test.

---

## Phase 11 — Dedicated concurrency testing (US2 / US4)

**Goal**: prove the locking guarantee on MySQL with REAL concurrent execution — no sequential fakes, no second database engine, no app-level mutexes (Principle IV, SC-002; research.md §3).

**Independent Test**: `php artisan test -c phpunit-concurrency.xml` — parallel processes race one seat; exactly one winner.

- [x] T038 [US2] `tests/Feature/Concurrency/ConcurrentReservationTest.php` — same-seat race: spawn ≥2 real parallel OS processes (Symfony `Process`) POSTing the same seat for different users against the MySQL test database; assert EXACTLY one 201 and the rest 409 `SEAT_UNAVAILABLE`; losers leave NO partial reservation and NO holds on their other selected seats. Runs via `phpunit-concurrency.xml`.
- [x] T039 [US2] Same file — overlapping multi-seat race: parallel requests with overlapping selections remain all-or-nothing — every winner acquires its ENTIRE selection, every loser acquires nothing; no seat ends up in two active-or-completed reservations.
- [x] T040 [US4] Same file — late-success vs new-reservation race: a late verified success (all seats seemed available) processed in parallel with a new reservation for the same seat → allocation is exclusive: exactly one winner per shared seat; the late path acquires ALL original seats or none; duplicate re-delivery never re-acquires. ALSO: simultaneous duplicate webhook delivery — two parallel processes POST the SAME signed notification → the payment-row `lockForUpdate` (T036 step 5) serializes them; the outcome is applied exactly once (one payment state change, ONE reservation transition, no duplicate late acquisition, no duplicate payment attempt) and the loser returns the normal idempotent no-op — no 500. This test is the real-concurrency evidence for duplicate webhooks (T035 covers ordinary sequential duplicates). Depends on T036.

**Checkpoint**: the three race scenarios pass on MySQL — the constitution's locking evidence (Principle IV).

---

## Phase 12 — API/error consistency

**Goal**: one JSON error language per contracts/api.md (FR-023/024, Principle XI).

**Independent Test**: every error path in the suite asserts the documented status + shape.

- [x] T041 [P] JSON exception rendering in `bootstrap/app.php`: consistent error shape (`{"error": {"code", "message"}}`) for 401 `UNAUTHENTICATED`, 403 `FORBIDDEN`, 404 `NOT_FOUND`, 409 conflicts; 422 keeps Laravel's standard validation error bag; no stack traces/secrets in responses (FR-024).
- [x] T042 Contract-shape audit across `tests/Feature/*` (depends on T041's rendering being in place): assert documented codes and payloads for `SEAT_UNAVAILABLE` (per-seat reasons, no holder identity) and `PAYMENT_NOT_ALLOWED`; verify 401/403/404/409/422 mapping table of contracts/api.md end-to-end; fix any drift. Depends on suites from Phases 3–10 existing.

**Checkpoint**: the status-code summary table in contracts/api.md is fully enforced by tests.

---

## Phase 13 — Documentation and final verification

**Purpose**: README deliverable (FR-028, Principle XII) + final quality gates (constitution workflow checklist).

- [x] T043 [P] `README.md` — setup & running: PHP 8.3 extensions (`pdo_mysql`, `mbstring`, `openssl`, `curl`), MySQL setup (dev + test schemas, env vars table), `composer install` → `key:generate` → `migrate --seed` → `serve`, test commands (`composer test` + `php artisan test -c phpunit-concurrency.xml`), MySQL-only statement, no Docker requirement, no secrets committed.
- [x] T044 `README.md` — API usage & design decisions (depends on T043 — same file, written second): all 12 endpoints with request/response examples; error shape + status-code table; reservation lifecycle (fixed 30-minute hold, derived `expired`, failed payment retains the hold without restart, retry rules, FR-013 late success + the documented no-refund limitation); concurrency approach (MySQL transaction + `lockForUpdate`, dedicated parallel-process suite); payment abstraction (contract, `FakePaymentGateway` default, Paymob as implementation choice, optional Paymob test-environment setup); `payments:simulate` demo; the frontend's role in the hosted flow (document only); **AI usage disclosure** (assessment email); assumptions list.
- [x] T045 Run Laravel Pint (`vendor/bin/pint`) over `app/`, `database/`, `tests/` — clean.
- [x] T046 Run the full test suite against MySQL (`composer test`) — all green (SC-007).
- [x] T047 Run the dedicated concurrency suite (`php artisan test -c phpunit-concurrency.xml`) — all green (Principle IV evidence).
- [x] T048 Validate `quickstart.md` manually, steps 1–8 (register/me/logout, events, seats, reservation, listing, pay + `payments:simulate`, failure/expiry, ownership) — every expected outcome matches.
- [x] T049 Final integrity audit: zero references to any non-MySQL database engine or in-memory/file database driver anywhere in the project (config, env files, phpunit configs, docs); no out-of-scope features (no frontend, admin, event/seat CRUD, notifications, refunds, coupons, queues/schedulers/Redis/Docker requirements); no real payment/network calls in automated tests (fakes + fixture payloads only, FR-027); PRD coverage — every FR-001…FR-030 and every user story has implementation + test tasks completed; traceable to constitution principles I–XIII.

---

## Dependencies & Execution Order

### Phase dependencies

- **Phase 1** → **Phase 2**: config/env before migrations run against MySQL.
- **Phase 2** → **Phases 3–10**: all business code depends on schema + models. Within Phase 2, migrations run in strict FK dependency order — T005 (events) → T006 (seats) → T007 (reservations) → T008 (reservation_seat) → T009 (payments) — and the generated migration timestamps/filenames MUST create referenced tables before dependent foreign keys apply; then models (T010–T011) → factories (T012) → seeders (T013).
- **Phases 3 → 4 → 5 → 6** are sequential by shared file (`routes/api.php`) and by business dependency (availability query is reused by allocation; policy reused by reads/pay).
- **Phase 7** (contract + fake + binding) precedes **Phase 9** and **Phase 10** (both consume the contract).
- **Phase 8** (Paymob) depends only on Phase 7 — it is fully isolated (default driver stays fake) and required by NO other phase: Phases 9–10 work entirely through the contract with `FakePaymentGateway`. It may be built in parallel with or after them.
- **Phase 10** depends on Phases 7 + 9 and does NOT depend on Phase 8 (T036 = T029 + T030 + T034 + T035 — no Paymob tasks).
- **Phase 11** depends on Phases 5 (allocation), 9 (payment attempts), 10 (late-success path).
- **Phase 12** audits after all endpoint phases exist.
- **Phase 13** last — documentation and gates over the finished feature.

### Shared-file notes (do NOT parallelize)

- `routes/api.php` is touched by T016, T018, T023, T027, T034, T036 — strictly sequential.
- `app/Services/PaymentService.php` is touched by T034 and T036 — sequential.
- `README.md` is touched by T043 then T044 — sequential.
- `tests/Feature/Concurrency/ConcurrentReservationTest.php` (T038–T040) — one file, sequential.

### Parallel opportunities

17 tasks carry `[P]` after the dependency audit: T003, T004 (config/env — different files), T010 (models, after migrations finish), every test-first task in its own file (T014, T015, T017, T019, T020, T025, T026, T033, T035), T024 (policy), T028 (contract + VOs), T031 (Paymob initiation — isolated adapter files, valid once T028's contract exists; T032 stays sequential after T031), T041 (error rendering) and T043 (README setup). The five migrations (T005–T009) are deliberately NOT parallel — FK dependency order; factories (T012) and the fake gateway (T029) lost `[P]` because they consume contract/model classes; T042 lost `[P]` because it audits T041's rendering. Cross-phase, only Phase 8 genuinely overlaps Phases 9–10 (isolated adapter) — and nothing in Phases 9–10 waits on it.

---

## Implementation Strategy

### MVP first (browse → reserve → pay with the fake gateway)

1. Phases 1–2 (foundation + schema) → 3 (auth) → 4 (US1 browsing) → 5–6 (US2/US3 reservation core) → 7 → 9 → 10 (payment lifecycle behind the fake).
2. **STOP and VALIDATE**: quickstart.md steps 1–8 pass with `PAYMENT_DRIVER=fake` — the full business feature works end-to-end with local MySQL and `PAYMENT_DRIVER=fake`, requiring zero external payment services or payment-provider credentials.

### Incremental delivery

3. Phase 8 (real Paymob adapter) — swap-by-config demo of SC-008.
4. Phase 11 (concurrency evidence) → 12 (error consistency) → 13 (docs + gates).

Each phase ends green: its tests written first (T014/T017/T019/T020/T025/T026/T033/T035), then implementation turns them green.

---

## End Matter

**Total tasks**: 49

**Per phase**: P1: 4 · P2: 9 · P3: 3 · P4: 2 · P5: 6 · P6: 3 · P7: 3 · P8: 2 · P9: 2 · P10: 3 · P11: 3 · P12: 2 · P13: 7

**Parallelizable**: 17 `[P]` tasks (same-phase file isolation only; see shared-file notes and the audit in Parallel opportunities).

**MVP sequence**: T001–T013 → T014–T016 → T017–T018 → T019–T024 → T025–T027 → T028–T030 → T033–T034 → T035–T037 (validate with quickstart) → T031–T032 → T038–T040 → T041–T042 → T043–T049.

**User-story mapping**:

| Story | Implementation tasks | Test tasks |
|---|---|---|
| US1 browse events & available seats | T018 (+ schema T005/T006/T010, seeders T013) | T017 |
| US2 reserve + hosted payment | T021–T023, T027, T028–T030, T031, T034 (+ T022 allocation reused by FR-013) | T019, T020, T033, T035 (shared), T038–T039 |
| US3 auth surface & ownership | T015–T016, T024, T027 | T014, T025, T026 |
| US4 payment notifications | T036–T037 (+ T029 fake signed payloads) | T035, T040 |

**Format validation**: all 49 completed tasks follow `- [x] TNNN [P?] [USx?] Description with file path`; story labels appear on all story-serving tasks; foundation/polish tasks intentionally unlabeled per the template rules.
