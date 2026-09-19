# Phase 0 Research: Ticket Reservation API (001-ticket-reservation)

Resolves every decision the constitution and specification deferred to planning. Each entry states the decision, the rationale, and the alternatives considered and why they were rejected — so every choice remains explainable per constitution Principle XIII.

## 1. Payment provider selection

**Decision**: **Paymob** as the concrete adapter behind the provider-independent `PaymentGateway` contract, with a `FakePaymentGateway` driver as the default/recommended mechanism for automated tests and local demonstration. No provider SDK is used; the adapter talks to Paymob's REST API via Laravel's HTTP client. Paymob is an **implementation choice, not an assessment requirement**: the assessment requires a third-party hosted payment interface, and Paymob is one concrete implementation satisfying it. When implementation begins, the adapter MUST verify the exact Paymob transaction-callback/HMAC field format from the **official Paymob documentation** at that time — Paymob callback types differ and the field set must not be assumed.

**Rationale**:
- The assessment (Restart Technology) requires "a third-party payment provider using its hosted payment interface". Paymob satisfies that requirement concretely: it offers a hosted checkout page, server-side signed callbacks, and test credentials, and it fits the provider abstraction the assessment mandates. (The company operating the assessment is Egyptian, which makes Paymob operationally convenient — convenience, not a requirement.)
- Paymob's hosted checkout fits the required flow exactly: the backend creates a payment intent and receives a hosted checkout URL; the user is redirected there; the provider notifies the backend server-to-server (HMAC-signed callback). No card data ever touches our API — matching FR-016/FR-024.
- The provider is **not** load-bearing: business logic depends only on the `PaymentGateway` contract and MUST NOT contain Paymob-specific branching (SC-008). The default/recommended driver is `FakePaymentGateway`, so the project runs and tests end-to-end with zero credentials and zero real transactions (Principle X). Optionally, real Paymob **test** credentials may be used against Paymob's test environment for manual integration testing — never production credentials.

**Alternatives considered**:
- *Stripe* — the most documented global option, but not available for Egyptian merchants; also its SDK would add a dependency the constitution requires us to justify away (Laravel HTTP client suffices).
- *PayPal* — wallets-oriented with a different hosted-flow shape; a weaker fit for the assessment's hosted card checkout.
- *Fawry* — also Egyptian, but its integration model (reference codes / retail collection) is a poorer fit for a hosted card checkout.
- *Choosing none / keeping only the fake* — rejected: the spec (FR-016) requires a real hosted-provider integration to exist behind the boundary; the fake alone would not demonstrate the adapter pattern.

## 2. Authentication mechanism

**Decision**: **Laravel Sanctum** personal access tokens (`php artisan install:api`), Bearer-token authentication for reservation/payment routes. The auth surface covers register, login, logout (revokes the token used for the request — a logged-out token must no longer authorize protected requests), and `me` (the authenticated user's basic account information, without sensitive authentication/token data).

**Rationale**: first-party, zero new concepts beyond the framework, designed exactly for "simple user authentication mechanism" on token-based APIs (the PDF leaves the approach open). Registration + login issue a plain-text token shown once; subsequent requests send `Authorization: Bearer <token>`. Logout is Sanctum's native token revocation of the current access token; ownership enforcement is a Policy, independent of the auth mechanism (Principle VIII).

**Alternatives considered**:
- *Session-cookie auth* — natural for Blade apps; this is a stateless API, and cookies add CSRF surface for no benefit here.
- *JWT package (e.g., tymon/php-auth)* — third-party dependency for stateless tokens Sanctum already provides; violates Principle III.
- *HTTP Basic* — no logout/revocation story; poor fit for a real client.

## 3. Database engine and concurrency-test strategy (constitution Principle IV)

**Decision**: **MySQL is the only relational database for this project** — used identically for local development, the general automated test suite, the dedicated concurrency suite, and the production/reference deployment. There is no second database strategy: every database-dependent behavior, including all normal feature tests, executes against MySQL, where `DB::transaction` + `lockForUpdate()` compile to real `SELECT … FOR UPDATE` row locks. The concurrency suite fans out **real parallel OS processes** (Symfony `Process`, already in Laravel's dependency chain) that race the same seat through the real HTTP allocation path, verifying the actual `transaction → lock candidate seat rows → re-check availability inside the lock → allocate all seats or none` behavior under genuine row-level locks. A reachable local MySQL instance is sufficient; **no Docker requirement** (any way of providing MySQL is acceptable).

**Rationale**:
- Constitution Principle IV demands database-level row locking and verified concurrency behavior. Running every suite on the same MySQL engine the application deploys on makes the tests production-compatible **by construction** — there is no separate database-engine strategy to justify or maintain.
- One engine keeps the deliverable honest and simple: the configuration the assessor runs locally is exactly the configuration the tests prove correct.
- No Redis, no distributed locks, no message broker, and no Docker mandate (Principles I/III).

**Setup note (documented in README/quickstart)**: a dedicated MySQL test schema (e.g. `ticket_reservation_test`) is configured via `.env.testing`/environment; `php artisan test` runs the general suite and `php artisan test -c phpunit-concurrency.xml` runs the concurrency suite — both against the same MySQL technology.

**Alternatives considered**:
- *A second, embedded/file database for any role (local runs, general tests, or concurrency evidence)* — rejected as the final decision: a single-engine design is simpler and more honest, and an engine without `SELECT … FOR UPDATE` semantics cannot verify the row-locking strategy the constitution requires.
- *MySQL via Docker Compose as a hard requirement* — rejected: a reachable local MySQL instance suffices; Docker is welcome but never mandated.
- *In-memory / per-connection databases for concurrency tests* — rejected outright: they cannot witness cross-connection contention.

## 4. Fixed currency and monetary representation

**Decision**: **EGP** (Egyptian pound, 2-decimal minor units = piasters) declared in `config/payment.php`. All monetary values are stored and computed as **integer minor units** (`integer` columns: `price_minor`, `total_minor`). The API presents decimal strings (e.g., `"10.10"`) and accepts them only when they carry at most the currency's minor-unit precision; excess precision is rejected, not rounded (FR-030). Conversion between decimal strings and minor units happens at the API edge and at the `PaymentGateway` boundary (the contract documents amounts in minor units; the Paymob adapter converts to the provider's required unit).

**Rationale**: integers eliminate floating-point drift entirely; `10.10 × 3 = 3030` exactly (FR-030's example). One fixed currency keeps scope tight (FR-029); EGP matches the company context. The precision-rejection rule is a validation-layer concern.

**Alternatives considered**:
- *Decimal/string storage* — keeps human readability but invites arithmetic drift or requires a money library; a bcmath-based string path is more machinery than integer columns for zero benefit here.
- *moneyphp/money library* — a full value-object library for one currency and one multiplication; violates Principle III.
- *USD* — arbitrary; no reason to detach from the company's context.

## 5. Reservation state model

**Decision**: store only two statuses on `reservations`: `pending` and `completed`. "Expired" is **derived** (`pending AND expires_at <= now`), presented as `expired` in API responses; "successful payment without completion" (FR-013's uncompleted outcome) is represented by the payments table recording a successful payment against a reservation that is not completed.

**Rationale**: the source of truth for expiry is the timestamp (Principle V); duplicating it into a status via a background job would create a second, stale truth. The owner-facing requirement (FR-014: distinguish successful payment from uncompleted reservation) is satisfied by the payment record + derived status, with no state-sync job. A late success under FR-013 either completes the reservation (pending → completed) or leaves it expired with a recorded successful payment — both trivially representable.

**Alternatives considered**:
- *An `expired` stored status updated by a scheduler* — makes a background job part of state truth, which Principle V explicitly forbids depending on; also adds a job for no functional gain.
- *An extra `payment_recorded` status* — a third stored state whose only content is already in the payments table; redundant.

## 6. Concurrency-safe allocation mechanism

**Decision**: reservation creation runs in a single database transaction: (1) lock the candidate seat rows (`lockForUpdate`), (2) re-evaluate availability inside the lock (no completed reservation covering the seat; no pending hold with `expires_at > now`), (3) insert the reservation + seat attachments, (4) commit. Any unavailable seat → rollback, whole request fails with a per-seat "seat no longer available" error (all-or-nothing, FR-008/FR-009). The same transactional path is reused by FR-013's late-success acquisition.

**Rationale**: one transaction covering check + write is the minimal correct design; the lock closes the check-then-insert race window between competing processes. Reusing the same path for late success guarantees the "acquire all original seats or none" rule with the same machinery.

**Alternatives considered**:
- *Unique-constraint trick (e.g., partial unique index on active holds)* — elegant, but it splits the "all-or-nothing multi-seat" guarantee across per-seat constraints plus app logic, and its predicate expressiveness varies by engine. More moving parts, no added safety.
- *Application-level mutex (cache lock)* — not a database guarantee; violates the spirit of Principle IV ("database-level guarantees are preferred").
- *Optimistic concurrency (version columns)* — indirection with no benefit when a single locked transaction is available.

## 7. Webhook verification and idempotency

**Decision**: the provider notification endpoint verifies Paymob's HMAC-SHA512 signature over the ordered callback fields using the provider's HMAC secret (from env). Processing is idempotent by construction: each payment has a provider-side transaction reference stored uniquely on the `payments` table; a re-delivered notification finds the already-recorded outcome and returns `200` without re-applying any state change. Unknown references are rejected safely; amount/currency mismatches never complete a reservation (FR-019/FR-020).

**Rationale**: signature verification is the constitution's Principle XI requirement; the unique-reference lookup makes duplicate delivery a no-op by design rather than by deduplication jobs or locks.

**Alternatives considered**:
- *Processed-webhooks ledger table* — general solution for many event types; overkill for one payment outcome that already lives on the payments row.
- *Verifying only the redirect return* — forbidden by FR-022 (browser is not the source of truth).

## 8. Time handling in tests

**Decision**: expiry/late-payment tests travel in time with `Carbon::setTestNow()` (Laravel-native, no package) — expiry is simulated by moving the clock, never by sleeping and never by shortening the 30-minute business rule. The MySQL concurrency suite uses real time because it spans real processes.

**Rationale**: deterministic expiry tests without sleeps; the 30-minute hold is a fixed business rule (FR-010) and tests must not reconfigure it; real time only where real concurrency is the subject.

**Alternatives considered**: sleeping in tests (slow, flaky); lowering the hold duration in config for tests (mutates the business rule the assessment specifies); freezing per-process clocks manually (reinvents Carbon).

## 9. Hosted payment flow shape (frontend's documented role)

**Decision**: `POST /reservations/{id}/pay` (authenticated, owner-only) calls the gateway contract to create/refresh a payment intent and returns `{ payment_reference, hosted_checkout_url, expires_at }`. The documented frontend role: redirect the user to `hosted_checkout_url`; the provider page collects payment; the provider then (a) notifies our server-to-server webhook — the source of truth — and (b) redirects the browser back to a configured return URL, which the frontend may use only for display. No client code is built (FR-016, FR-022).

**Rationale**: matches the PDF's hosted-interface requirement and keeps payment truth server-side. The `fake` driver returns a local URL and is driven to success/failure by config/test control, so the whole flow is demonstrable without credentials.

**Alternatives considered**: iframe embedding (Paymob supports it, but it couples the API to widget semantics and adds nothing for an API-only deliverable); polling-only confirmation without webhook (violates the server-confirmation requirement).

## 10. Seed data shape

**Decision**: two seeders: demo events (e.g., a concert and a theatre play, each with a uniform seat price in EGP and a small grid of numbered seats) and demo users (documented test credentials for the reviewer). Production-agnostic; no admin APIs (FR-004/FR-007).

**Rationale**: the PDF explicitly allows seeded events/seats; demo users make manual validation in quickstart.md effortless.

**Alternatives considered**: factories-only (fine for tests, but the deliverable should boot with browsable data); larger datasets (no value).
