# HTTP API Contract — Ticket Reservation API (001-ticket-reservation)

JSON REST API under `/api`. Content type `application/json`. All monetary amounts are decimal strings of the fixed currency (EGP, 2 decimals, e.g. `"150.00"`) — exact to the minor unit, never rounded (FR-030).

## Error shape

Non-validation errors use:

```json
{
  "error": {
    "code": "SEAT_UNAVAILABLE | UNAUTHENTICATED | FORBIDDEN | NOT_FOUND | PAYMENT_NOT_ALLOWED",
    "message": "human-readable, non-sensitive message"
  }
}
```

- `SEAT_UNAVAILABLE` responses additionally include per-seat detail:
  `{"error": {"code": "SEAT_UNAVAILABLE", "message": "...", "seats": [{"number": "A3", "reason": "held"}]}}` — `reason` is `held` (active hold) or `sold` (completed reservation). The response never reveals **who** holds a seat.
- Validation failures return **422** with Laravel's standard error bag: `{"message": "...", "errors": {"field": ["..."]}}`.

## Endpoints

### Authentication

#### `POST /api/register` — create account (FR-003)

Request: `{"name": "...", "email": "...", "password": "...", "password_confirmation": "..."}`

Response `201`:
```json
{
  "user": {"id": 1, "name": "eman", "email": "eman@example.com"},
  "token": "1|plaintext-token-shown-once"
}
```

Errors: 422 (invalid email, weak password, duplicate email identity).

#### `POST /api/login` — obtain API token (FR-001)

Request: `{"email", "password"}`

Response `200`: same shape as register. Errors: 422 error bag (invalid credentials).

#### `GET /api/me` — current account (FR-003)

Authenticated. Response `200`:
```json
{"user": {"id": 1, "name": "eman", "email": "eman@example.com"}}
```

The authenticated user's basic account information — never tokens or other sensitive authentication data. Unauthenticated → 401.

#### `POST /api/logout` — revoke the current token (FR-003)

Authenticated. Response `200` (empty body).

Revokes **only** the access token used for this request (no logout-all-devices). A logged-out token MUST no longer authorize protected requests — any later use of it returns 401.

**Authentication scope (FR-002, FR-003, FR-019)**: the events endpoints are public — browsing is a documented minimal-access assumption. Registration and login are public. `me`, `logout`, all reservation endpoints, and payment initiation require `Authorization: Bearer <token>`; ownership is enforced server-side. The payment webhook uses **no** user authentication: authenticity comes from provider signature verification instead. Missing/invalid token on a protected endpoint → 401 `UNAUTHENTICATED`.

### Events (public browsing — documented assumption)

#### `GET /api/events` — list events (FR-004)

Response `200`:
```json
{
  "data": [
    {"id": 1, "name": "Cairo Jazz Night", "seat_price": "150.00"}
  ]
}
```

#### `GET /api/events/{id}` — view a single event (FR-004)

Response `200`:
```json
{
  "id": 1,
  "name": "Cairo Jazz Night",
  "seat_price": "150.00"
}
```

Basic public information and the event's configured seat price only — **no seat details** (held/sold/available seat data belongs to the seats endpoint below). Unknown event → 404 `NOT_FOUND`.

#### `GET /api/events/{id}/seats` — available seats only (FR-005, FR-025)

Response `200`:
```json
{
  "event_id": 1,
  "seats": [{"id": 101, "number": "A1"}]
}
```

Contains **only** currently available seats: seats covered by a completed reservation or an active (`pending`, `expires_at > now`) hold never appear. A seat whose hold expired reappears without any cleanup having run (FR-006/FR-011). Unknown event → 404 `NOT_FOUND` (never an empty list).

### Reservations (authenticated, owner-only)

#### `GET /api/reservations` — the authenticated user's reservations (FR-014)

Response `200`:
```json
{
  "data": [
    {"id": 12, "event_id": 1, "status": "pending", "seats": ["A1"], "total": "150.00", "expires_at": "2026-09-18T20:00:00Z", "last_payment": {"status": "pending", "paid_at": null}}
  ]
}
```

Returns **only** reservations belonging to the authenticated user — never another user's. Summary level: enough to identify the reservation, event, seats, total, status, and expiration; payment information is summarized as `last_payment` exactly as already justified by FR-013/FR-014. `status` follows the existing lifecycle: `pending`, `completed`, or derived `expired` (Principle V). This is an owner listing — not an admin or global reservation listing.

#### `POST /api/reservations` — create (FR-008/FR-009)

Request: `{"event_id": 1, "seat_numbers": ["A1", "A2"]}` — seats addressed by `number` within one event.

Response `201`:
```json
{
  "reservation": {
    "id": 10, "event_id": 1, "status": "pending",
    "seats": ["A1", "A2"], "total": "300.00",
    "expires_at": "2026-09-18T20:00:00Z"
  }
}
```

- Creation is all-or-nothing: if **any** selected seat is unavailable, the entire request fails with **409 `SEAT_UNAVAILABLE`** listing every unavailable seat and its reason; no reservation is created and none of the seats become held by the failed request (including under concurrency).
- Total is server-computed: distinct seat count × event seat price (FR-029). No client price field exists to tamper with.

#### `GET /api/reservations/{reservation}` — owner-only details (FR-014, FR-015)

Response `200`:
```json
{
  "reservation": {
    "id": 10, "event_id": 1,
    "status": "pending | completed | expired",
    "seats": ["A1", "A2"], "total": "300.00",
    "expires_at": "2026-09-18T20:00:00Z",
    "last_payment": {"status": "pending | success | failed", "paid_at": "2026-09-18T19:41:00Z"}
  }
}
```

`status` is derived: `pending` (active hold), `completed` (sold), or `expired` (pending but `expires_at <= now`). `expired` is never stored — it is computed from the timestamp (Principle V). `last_payment` lets the owner distinguish "payment succeeded" from "reservation completed" (FR-013/FR-014: late success can leave an expired reservation uncompleted with a successful payment recorded). Non-owner (including other authenticated users) → 403 `FORBIDDEN`. Unauthenticated → 401.

### Payments

#### `POST /api/reservations/{id}/pay` — initiate hosted payment (FR-016, FR-012)

Owner-only. Response `200`:
```json
{
  "payment_reference": "pay_12abc",
  "hosted_checkout_url": "https://provider.example/checkout/…",
  "expires_at": "2026-09-18T20:00:00Z"
}
```

**Documented frontend role (no client code is built)**: redirect the user to `hosted_checkout_url`; the provider collects payment; the provider notifies our webhook (the source of truth) and may redirect the browser to a return URL for display only — the redirect is never payment truth (FR-022).

Rules (FR-012):
- Allowed only while the reservation is pending and not expired. Retry after a failed payment is allowed within the original window: same reservation, same seats, **unchanged** `expires_at` and total.
- On a completed reservation → 409 `PAYMENT_NOT_ALLOWED`. On an expired reservation → 409 `PAYMENT_NOT_ALLOWED` (the hold is not restored).
- Amount = the reservation's immutable agreed total in minor units (FR-029/FR-030).

#### `POST /api/payments/webhook` — provider notification (FR-019, FR-020)

Provider-to-server notification; **no user authentication** — authenticity comes from signature verification instead (HMAC-SHA512 over the provider's ordered callback fields, secret from env; research §7).

Semantics:
- Verified, matching success → completes a pending reservation (before or after expiry per FR-013: after expiry, all original seats must still be available, otherwise success is recorded **without** completion and seats are not allocated).
- Verified failure → recorded; the reservation is untouched; the hold stands until its original `expires_at`; a failure never restores an expired hold or undoes a completion.
- Amount/currency mismatch (even one minor unit) → recorded but never completes the reservation (FR-019/FR-030).
- Duplicate delivery of an already-processed notification → **200 no-op**; no repeated side effects, no re-allocation attempt even if availability later changed.
- Unknown/malformed reference → 404/422, no state change, no crash.
- Failed signature verification → **403**, no state change, no existence hints.

## Validation rules (server-side, FR-023)

- `event_id` must reference an existing event; every `seat_numbers` entry must be a seat of **that** event (cross-event mixing cannot pass → 422; seat not on the event → 422).
- `seat_numbers`: required array, min 1, distinct (duplicates → 422); zero/empty/missing → 422.
- Unknown seat number → 422 (invalid input, never silently ignored).
- Client-supplied price/total fields do not exist in the contract; nothing to tamper with (FR-029).
- Registration: valid unique email, password confirmation, minimum length (Laravel defaults, documented in README).

## Status-code summary

| Scenario | Status |
|---|---|
| Register / reservation created | 201 |
| Login / me / logout / pay initiated / webhook handled (incl. duplicate no-op) | 200 |
| Seats / events / reservation fetched | 200 |
| Missing/invalid token | 401 |
| Non-owner reservation access | 403 `FORBIDDEN` |
| Webhook signature verification failure | 403 |
| Unknown event/reservation/payment reference | 404 |
| Seat conflict on creation (all-or-nothing) | 409 `SEAT_UNAVAILABLE` |
| Pay on completed/expired reservation | 409 `PAYMENT_NOT_ALLOWED` |
| Any validation failure | 422 |

## Test scenario mapping (for the tasks phase)

| FR-026 behavior | Scenario via this contract |
|---|---|
| Auth & ownership | register/login; owner GET 200; other-user GET → 403; unauthenticated → 401 |
| me & logout | `/me` returns the authenticated user and rejects unauthenticated requests; logout revokes the current token; a logged-out token can no longer access protected endpoints |
| Reservation listing | `GET /api/reservations` returns only the authenticated user's reservations; another user's reservations never appear in the listing |
| Events (list & show) | `GET /api/events`; `GET /api/events/{id}` returns id/name/seat_price only — no seat details; unknown event → 404 |
| Available-seat filtering | `GET /api/events/{id}/seats` after hold/sold/expiry transitions; held/sold never listed (FR-005/FR-025) |
| Expiry without cleanup | Carbon time-travel past `expires_at`, run nothing; seat reappears; user B books it (SC-003) |
| Creation & all-or-nothing | POST success 201; mixed available/unavailable → 409 with per-seat reasons; reservation list shows nothing created |
| Same-user competition | user A holds A1; A requests A1 again → 409 |
| Cross-user concurrency | parallel-process suite (research §3), same contract path |
| Success during active hold | pay → signed webhook success → completed, seats sold |
| Failure before expiry | webhook failure → seats still held, `expires_at` unchanged, retry allowed |
| Retry rules | pay → failure → pay again (same expiry/seats) → webhook success → completed; pay after expiry/completion → 409 |
| Late success, all seats available | pay → travel past expiry → webhook success → completed, seats sold (FR-013) |
| Late success, a seat gone | another user books after expiry → webhook success → `last_payment.status = success`, reservation stays expired, no seats allocated |
| Duplicate notifications | deliver same signed webhook twice → second is a no-op; re-deliver after rebooking → outcome preserved |
| Mismatched confirmation | success notification with amount off by one minor unit → never completes (FR-030) |
| Money exactness | `"10.10" × 3 = "30.30"` exact; >2dp rejected 422 |
