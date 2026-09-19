# Phase 1 Data Model: Ticket Reservation API (001-ticket-reservation)

Entities from the specification mapped to a relational schema. All monetary values are **integer minor units** (piasters for EGP) — see [research.md §4](./research.md). Availability is always **derived** from reservation rows + `expires_at`; seats carry no status column.

## Entity Relationship Overview

```text
User 1───* Reservation *───1 Event 1───* Seat
              │                  │
              │                  └────* (seats of the event, via seats.event_id)
              └──* Reservation *───* Seat   (reservation_seat pivot, one event per reservation)
              │
              └──* Payment
```

## Tables

### users *(extends Laravel's default migration)*

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| name | string | |
| email | string UNIQUE | identity (FR-003: duplicate identity rejected) |
| password | string | bcrypt hash |
| timestamps | | |

Plus Sanctum's `personal_access_tokens` table (created by `php artisan install:api`).

### events

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | unique identifier (FR-004) |
| name | string | |
| seat_price_minor | integer | one uniform price for all seats of the event (FR-029); positive |
| timestamps | | |

Currency is **not** stored here — the system operates in one fixed currency (EGP, 2-decimal minor unit) declared in `config/payment.php` (research §4).

### seats

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| event_id | bigint FK → events (cascade) | indexed |
| number | string | seat label, e.g. `A1`; `UNIQUE(event_id, number)` |
| timestamps | | |

**Deliberate absence**: no `status`/`is_available` column on seats. Availability (FR-006) is a derived property — a seat is unavailable iff it is covered by a completed reservation or by a pending reservation with `expires_at > now`. Deriving it can never go stale; a stored flag could (constitution V).

### reservations

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| user_id | bigint FK → users | indexed; ownership (FR-015) |
| event_id | bigint FK → events | indexed; reservation covers one event only (FR-008) |
| status | string | stored values: `pending` \| `completed` (see state model below) |
| total_minor | integer | agreed total, immutable across retries (FR-029/FR-030); set once at creation |
| expires_at | datetime | creation + 30 minutes; **indexed**; sole source of truth for the hold (Principle V); never modified by payment outcomes (FR-010/FR-012) |
| timestamps | | |

Availability predicate used everywhere (availability endpoint, allocation, FR-013 re-check):

```sql
-- seat is UNAVAILABLE when covered by:
--   a completed reservation, or
--   a pending reservation whose expires_at > NOW()
```

No unique constraint can express this temporal invariant across rows (a seat legitimately appears in many expired holds before being sold), so **the invariant "at most one active-or-completed reservation per seat" is enforced by the locked allocation transaction** (research §6), not by a constraint.

### reservation_seat *(pivot)*

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| reservation_id | bigint FK → reservations (cascade) | `UNIQUE(reservation_id, seat_id)` — no duplicate seat inside one reservation |
| seat_id | bigint FK → seats (cascade) | indexed — availability lookups join on it |

A reservation holds **distinct** seats of a single event (duplicate seat IDs in a request are rejected as invalid input — spec edge case; cross-event mixing rejected, FR-008).

### payments

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| reservation_id | bigint FK → reservations | indexed; a reservation may have several attempts (retries, FR-012) |
| provider | string | driver name (`paymob`, `fake`) — provenance only; business logic never branches on it |
| reference | string UNIQUE | our payment-intent reference given to/returned by the gateway |
| provider_transaction_id | string UNIQUE, nullable | provider's transaction id; the **idempotency anchor** (FR-020) |
| amount_minor | integer | expected/confirmed amount in minor units (FR-030) |
| currency | string(3) | always the configured currency |
| status | string | `pending` \| `success` \| `failed` |
| paid_at | datetime, nullable | when success was verified |
| timestamps | | |

Sensitive provider data (secrets, tokens, raw payloads with credentials) is never stored or exposed (FR-024).

## State Models

### Reservation

```text
            creation (locked allocation,
            all selected seats acquired)
(none) ──────────────────────────────► pending ────► completed
                                          │              ▲
                                          │              │ verified matching success
                                          │  derived:    │ (any time while pending IF:
                                          │  expired     │  before expiry: always
                                          │  when        │  after expiry: only if ALL original
                                          │  NOW >=      │  seats still available — FR-013)
                                          │  expires_at  │
                                          ▼              │
                                 expired + recorded      │
                                 successful payment ─────┘ (completion only via the FR-013 path)
                                 (payment recorded, reservation NOT completed)
```

- `expired` is **derived** (`pending AND expires_at <= now`), never stored, and never written by a job — nothing in the codebase writes expired state (Principle V).
- Failed/abandoned payment does **not** transition the reservation and does not touch `expires_at` (FR-012). Retries create new payment rows; seats and expiry are unchanged.
- `completed` is terminal. A delayed failure notification cannot undo it (FR-020).

### Payment

```text
              initiation (gateway contract)
(none) ──────────────────► pending ────► success   (verified provider confirmation)
                             │
                             └────────► failed    (verified provider rejection)
```

- Transitions happen **only** from signature-verified provider data (FR-019); the browser redirect never mutates state (FR-022).
- Duplicate delivery: the unique `provider_transaction_id` lookup finds the recorded outcome and processing is a no-op (FR-020).
- Late success after expiry: recorded as `success`; reservation completion follows FR-013 only (all original seats available → complete; else success recorded without completion).
- Late failure after completion: recorded but the reservation is untouched (FR-020).

## Derived Values (never stored)

| Value | Derivation |
|---|---|
| Seat availability | absence of covering completed reservation or active (pending, unexpired) hold (FR-006) |
| Reservation `expired` | `status = pending AND expires_at <= NOW()` |
| Remaining hold time | `expires_at - NOW()` (FR-014) |
| Reservation total | `distinct seat count × event.seat_price_minor` — computed once at creation, then immutable (FR-029) |

## Invariants (all enforced server-side)

1. **Single winner per seat**: no seat is ever covered by two reservations that are simultaneously active-or-completed. Enforced by the locked, single-transaction allocation (FR-009; research §6) and verified by the parallel-process concurrency suite.
2. **All-or-nothing allocation**: a reservation is created with all its selected seats or not at all; a losing request leaves no partial reservation and no residual holds (FR-008, spec edge cases).
3. **One event per reservation**: every seat attached to a reservation belongs to the reservation's event (FR-008).
4. **Immutable expiry and total**: `expires_at` and `total_minor` never change after creation (FR-010, FR-012, FR-029).
5. **Completion requires verified matching success**: amount and currency must match the reservation's agreed total (FR-019, FR-030) — a one-minor-unit mismatch never completes.
6. **Idempotent payment processing**: a given provider transaction is applied at most once (unique `provider_transaction_id`) (FR-020).
7. **Ownership**: every reservation row is bound to its owning `user_id`; all owner-only access goes through the policy, never client input (FR-015).
