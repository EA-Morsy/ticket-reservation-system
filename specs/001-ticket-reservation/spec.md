# Feature Specification: Ticket Reservation API (Backend Technical Assessment)

**Feature**: `001-ticket-reservation` (feature directory; current Git branch is `main`)

**Created**: 2026-09-18

**Status**: Clarified — ready for technical planning

**Last Updated**: 2026-09-18

**Input**: User description: "Create the feature specification for the Backend Developer technical assessment described in the attached task PDF: a backend ticket/event seat reservation API with authentication, event/seat browsing, 30-minute temporary reservations, concurrency-safe seat holds, and provider-independent hosted payment."

## User Scenarios & Testing *(mandatory)*

<!--
  User stories are PRIORITIZED as user journeys ordered by importance.
  Each user story/journey is INDEPENDENTLY TESTABLE — implementing just one
  still yields a viable slice of value.
-->

### User Story 1 - Browse events and view available seats (Priority: P1)

As a visitor to the API, I can browse the list of events and view a single event together with its currently available seats, so that I can decide which event to attend and which seats I can choose from.

An event contains a set of numbered seats. Event and seat data exist in the system from seed data; browsing does not require an account.

**Why this priority**: It is the entry point of every other journey — nothing can be reserved until a user can discover an event and see which seats are currently available. It is also independently shippable: a browsable catalog is a working deliverable on its own.

**Independent Test**: Can be fully tested by requesting the event list and a single event's seats and verifying that only currently available seats are returned (available-seat calculation verified against seeded holds/sales).

**Acceptance Scenarios**:

1. **Given** an event exists with seats, **When** any client requests the list of events, **Then** the response includes the event (identified by a unique ID, with a name).
2. **Given** an event exists with seats, **When** any client requests the event's seats, **Then** the response includes only seats that are currently available.
3. **Given** a seat on an event is covered by an active temporary reservation (its expiration time has not passed), **When** the event's seats are requested, **Then** that seat is absent from the response.
4. **Given** a seat on an event is covered by a completed reservation (already sold), **When** the event's seats are requested, **Then** that seat is absent from the response.
5. **Given** a seat's temporary reservation has expired, **When** the event's seats are requested, **Then** that seat appears in the response as available again.
6. **Given** a nonexistent event ID, **When** the event's seats are requested, **Then** the API responds with a not-found error, not an empty seat list.

---

### User Story 2 - Reserve seats and pay via hosted payment to complete the purchase (Priority: P1)

As an authenticated user, I can select one or more available seats and start a reservation that temporarily holds them for 30 minutes, then complete payment through a third-party hosted payment interface so that the seats become mine (sold) once payment succeeds.

**Why this priority**: This is the core of the assessment — the reservation lifecycle (hold → pay → complete, or expire) is the behavior being evaluated, including its concurrency-safety, expiration, and provider-independent payment requirements.

**Independent Test**: Can be fully tested by authenticating, reserving seeded available seats, driving the payment step with simulated provider outcomes (success and failure), and verifying completion vs. seats staying/returning available.

**Acceptance Scenarios**:

1. **Given** an authenticated user and available seats, **When** the user submits a reservation request for one or more of those seats, **Then** the reservation is created, the seats are held by that user, and the response identifies the reservation, the held seats, and the total price.
2. **Given** a reservation with an active hold, **When** the owner requests the reservation, **Then** the API returns its details, including held seats and remaining hold time.
3. **Given** an active reservation, **When** matching payment success is verified server-side before the hold expires, **Then** the reservation is completed, the seats are sold and permanently unavailable to everyone, and the reservation shows a completed status.
4. **Given** an active reservation whose hold's 30 minutes pass without completed payment, **When** any client next observes the seats, **Then** they are considered available again — without any cleanup process having run — the expired hold provides no allocation priority. A matching late success may complete the reservation only under the fresh availability check in FR-013.
5. **Given** an incomplete reservation before its original expiration, **When** payment fails or is abandoned, **Then** its seats remain held until the original expiration; failure does not release them early, complete the reservation, or restart the timer.
6. **Given** an incomplete reservation whose payment failed, **When** current time reaches or exceeds its original expiration, **Then** that hold no longer blocks its seats, even without cleanup; a failure notification received afterwards cannot restore the hold.
7. **Given** two or more users attempt to reserve the same seat at the same time, **When** the requests are processed concurrently, **Then** at most one reservation succeeds and the other(s) receive a clear failure for that seat.
8. **Given** a reservation request mixing seats from different events, **When** the user submits it, **Then** the API rejects it with a validation error (a reservation covers one event's seats only).
9. **Given** the owner's payment failed and the original hold has time remaining, **When** the owner initiates another payment attempt, **Then** it is allowed on the same reservation with the same seats and unchanged expiration. Matching success verified before expiry completes the reservation.
10. **Given** a failed payment on a reservation whose original expiry has been reached, **When** the owner requests a new payment attempt, **Then** initiation is rejected and the expired hold is not restored; confirmation for an already initiated payment follows FR-013.
11. **Given** a completed reservation, **When** its owner requests another payment attempt, **Then** initiation is rejected and the completed reservation remains unchanged.
12. **Given** a request for three seats where one is sold or actively held, **When** reservation creation is attempted, **Then** the entire request is rejected with a seat-unavailable outcome; no partial reservation is created and the other two seats are not held by the failed request.
13. **Given** concurrent requests for overlapping selections, **When** one request acquires a shared seat, **Then** a losing request leaves no partial reservation or holds on its other selected seats; existing valid reservations remain unchanged.

---

### User Story 3 - Users act only on their own reservations (Priority: P2)

As an authenticated user, I can operate only on my own reservations — view, pay for, and otherwise act on them — and the system prevents me from viewing or modifying another user's reservation, so that reservations are private and safe from tampering.

**Why this priority**: Security requirement stated in the assessment ("reservation and payment actions associated with a user" plus general security good practice); essential but downstream of the core reservation flow.

**Independent Test**: Can be tested by creating reservations for two users and verifying that cross-user access attempts are rejected, while the owner retains access.

**Acceptance Scenarios**:

1. **Given** a reservation owned by user A, **When** user B attempts to view it, **Then** the API denies access (not-found/forbidden), without revealing details of the reservation.
2. **Given** a reservation owned by user A, **When** user B attempts to pay for it, **Then** the API denies the action and user A's reservation is unchanged.
3. **Given** a reservation owned by user A, **When** user A requests it, **Then** the API returns its details.
4. **Given** an unauthenticated client, **When** it attempts a user-initiated reservation or payment action, **Then** the API rejects it as unauthenticated.
5. **Given** valid new account details, **When** the client registers and then logs in, **Then** an account is created and can perform authenticated actions.
6. **Given** invalid registration details or an existing account identity, **When** registration is attempted, **Then** the request is rejected without creating a duplicate account or granting access.
7. **Given** incorrect credentials, **When** login is attempted, **Then** authentication fails and protected operations remain inaccessible.
8. **Given** an authenticated user, **When** they request their current account, **Then** the API returns their own basic account information and no sensitive token data.
9. **Given** an authenticated user, **When** they log out, **Then** the access token used for that request is revoked and can no longer access protected endpoints.
10. **Given** reservations owned by users A and B, **When** user A requests their reservation list, **Then** only reservations owned by user A are returned.
---

### User Story 4 - Payment notifications update reservation state reliably (Priority: P2)

As the system, I must accept payment notifications from the payment provider and update reservation state correctly and idempotently — including when a notification arrives late (after hold expiration) or is delivered more than once — so that reservation state stays truthful.

**Why this priority**: The hosted payment flow requires server-side confirmation of payment truth; reliability and idempotency are core correctness for the payment integration. It builds on the flows of US1/US2 but is testable in isolation with simulated provider notifications.

**Independent Test**: Can be tested by simulating provider notifications (success, duplicate delivery, late arrival after expiration) against a reservation and verifying the resulting reservation and seat states.

**Acceptance Scenarios**:

1. **Given** an active reservation whose payment has succeeded at the provider, **When** a matching, verified success notification is received before expiration, **Then** the reservation is completed and its seats become sold.
2. **Given** a payment notification that has already been processed, **When** the same notification is received again (duplicate delivery), **Then** the reservation state does not change again and no error side effects occur.
3. **Given** an incomplete reservation whose hold has expired and all its original seats are currently available, **When** a matching, verified late success is processed, **Then** the system safely acquires all those seats and completes the reservation, whether payment occurred before or after expiry; no new temporary hold is started.
4. **Given** a payment notification, **When** it cannot be verified as genuinely originating from the provider, **Then** the API rejects it and reservation state is unchanged.
5. **Given** a notification for an unknown or malformed payment reference, **When** it is received, **Then** the API rejects/ignores it safely without crashing or changing reservation state.
6. **Given** a verified provider notification without a user login session, **When** it is processed, **Then** provider verification applies instead of user authentication; payment matching and lifecycle rules still apply.
7. **Given** a success notification with a mismatched reservation, amount, or currency, **When** it is processed, **Then** it does not complete the reservation.
8. **Given** a completed reservation, **When** a delayed failure notification arrives, **Then** it does not undo completion or release the sold seats.
9. **Given** an expired incomplete reservation with at least one original seat sold or actively held by another reservation, **When** matching late success is processed, **Then** payment is recorded as successful but the reservation remains uncompleted; no seats are allocated to it and existing reservations are unchanged.
10. **Given** a late confirmation competing with a new reservation for the same available seats, **When** both are processed concurrently, **Then** allocation is exclusive: late completion acquires every original seat or none.
11. **Given** a late success already recorded without reservation completion, **When** the same notification is delivered again after seats become available, **Then** its recorded outcome remains unchanged and no seats are acquired by duplicate processing.

---

### Edge Cases

- Requesting a reservation for a seat that is actively held by an existing active reservation, including one owned by the same user → rejected with a clear "seat no longer available" outcome for that seat.
- Requesting a reservation for a seat that has already been sold (completed reservation) → rejected the same way.
- Requesting a reservation that mixes seats from different events → rejected as invalid input.
- Reserving zero seats or an empty/missing seat selection → rejected as invalid input.
- Requesting a seat ID that does not exist → rejected as invalid input (not silently ignored).
- Reservation expiration: after 30 minutes without completed payment, the hold is no longer active; seats are available again based on the expiration timestamp itself, even if no background process has run.
- Matching payment success is verified before expiration → reservation completes; seats permanently unavailable. Payment made earlier but confirmed after expiration remains subject to FR-013.
- Matching payment success is processed at or after expiry → complete only if all original seats can be acquired together under FR-013, regardless of when payment occurred; otherwise record successful payment without reservation completion.
- Failed payment before expiration → retain the hold until its original expiration without restarting the timer; at or after expiration the failed payment cannot keep or restore the hold (FR-012).
- Retry after failure → the owner may retry on the same incomplete reservation before original expiry, without changing seats or extending the hold. New attempts at or after expiry, or after completion, are rejected. Already initiated payments may still produce late confirmations under FR-013.
- Duplicate payment notifications/callbacks → handled safely and idempotently.
- Concurrent attempts to reserve the same seat, by the same or different users → at most one succeeds (see FR-009).
- Duplicate seat IDs in a selection MUST NOT cause duplicate allocation or charging; reject-versus-normalize response handling belongs in the API contract.
- A multi-seat selection containing any unavailable seat → reject the entire request; do not create a partial reservation or leave any selected seat held by that failed request. This applies equally to a conflict discovered during concurrent allocation.
- Late confirmation racing with rebooking MUST acquire all original seats or none; it MUST NOT take seats sold or actively held by any other reservation, including one owned by the same user.
- Accessing or modifying another user's reservation → denied (owner-only access).
- Expired-hold reservation treated as a candidate for a new reservation → the new reservation on the same seat must be able to succeed without interference from the expired one.

## Requirements *(mandatory)*

### Functional Requirements

**Authentication & users**

- **FR-001**: The system MUST provide user accounts and a simple authentication mechanism so that reservation and payment actions are associated with a specific user. (The PDF leaves the exact approach open; a standard mechanism is assumed — see Assumptions.)
- **FR-002**: User-initiated reservation creation, payment initiation, and reservation-specific actions MUST require authentication. Provider-initiated notifications do not require a user login session; they MUST be independently verified under FR-019.
- **FR-003**: Users MUST be able to register a new account and log in through the API, view their own basic account information, and log out (revoking the token used for the request). Valid registration creates an account that can authenticate; invalid registration, duplicate account identity, and invalid login credentials MUST be rejected without granting access. A logged-out token MUST no longer authorize protected requests. Seeded accounts may support tests but MUST NOT replace self-registration. Password reset, email verification, social login, and administrative account management remain out of scope; exact authentication mechanisms and request fields belong in planning.

**Events & seats (read-only)**

- **FR-004**: The system MUST expose a browsable list of events and allow viewing a single event; each event has a unique identifier and a name. A seat-count summary is not a required deliverable.
- **FR-005**: The system MUST expose a single event's seats, returning ONLY seats that are currently available for that event.
- **FR-006**: A seat MUST be considered available when it is not covered by (a) a completed reservation, nor (b) an active temporary reservation whose expiration time has not passed. A seat whose temporary reservation has expired MUST be considered available again.
- **FR-007**: The availability calculation MUST be derived from stored reservation data and the expiration timestamp evaluated at request time. It MUST NOT logically depend on any cleanup/background process having executed. Events and seats MUST come from seed data; no event/seat administration APIs are in scope.

**Reservations**

- **FR-008**: An authenticated user MUST be able to start a reservation for one or more currently available seats of a single event. The reservation temporarily holds exactly those seats for 30 minutes. Creation MUST be all-or-nothing: if any selected seat is unavailable when allocation is performed, the entire request MUST fail, no reservation holding a subset may be created, and no seats may remain held by the failed request.
- **FR-009**: Reservation creation MUST be concurrency-safe: when requests compete for the same available seat, at most one reservation may acquire it; the losing attempts MUST receive a clear "seat no longer available" outcome. This applies to requests by the same or different users. No seat may belong to overlapping active holds or completed purchases. For overlapping multi-seat requests, a losing request MUST leave none of its selected seats held by that request, including seats not involved in the conflict. Availability checking and allocation of the entire selection MUST have one concurrency-safe outcome. The mechanism is a technical-plan decision.
- **FR-010**: The reservation MUST record an expiration timestamp set to creation time + 30 minutes. An incomplete hold is active only while current time is strictly before that timestamp; at or after it, the hold is expired. Payment failure MUST NOT change this timestamp.
- **FR-011**: Any consumer evaluating a reservation (availability check, payment attempt, etc.) MUST evaluate it against the current time. An expired hold MUST NOT prevent its seats from being considered available, and MUST NOT block a new reservation on the same seat — regardless of whether any cleanup process has run.
- **FR-012**: A failed or abandoned payment MUST NOT complete the reservation or release its seats before the original 30-minute expiration. The incomplete reservation continues holding its seats until that timestamp. At or after expiration, the hold MUST cease blocking seats regardless of payment failure or cleanup execution. A failure MUST NOT restart, extend, or restore the hold. This does not undo a completed reservation or override a newer valid reservation. After a failed payment, the authenticated owner MUST be allowed to initiate another payment attempt on the same incomplete reservation while current time is strictly before its original expiration. Retrying MUST NOT change the selected seats or restart or extend the hold. New retry initiation at or after expiration MUST be rejected; a success confirmation for an already initiated payment remains subject to FR-013. A completed reservation MUST NOT accept another payment attempt.
- **FR-013**: When matching, verified payment success for an incomplete reservation is processed at or after its original expiration, the system MUST re-evaluate every originally selected seat. If all are currently available under FR-006, it MUST acquire all of them and complete the reservation as sold in one concurrency-safe operation. This applies whether payment occurred before or after expiration. It MUST NOT restart or extend the original hold. If any original seat is sold or actively held by another reservation, it MUST record successful payment without completing the reservation or allocating any seats to it. Existing reservations MUST remain unchanged. The owner MUST be able to distinguish successful payment from an uncompleted reservation. This outcome does not introduce automatic refunds or a settlement workflow; the limitation MUST be documented.
- **FR-014**: A user MUST be able to view their own reservation details, including selected seats, reservation status, payment outcome, expiration time, and total price, and MUST be able to list their own reservations (their reservations only — never another user's). Selected seats MUST NOT be represented as held or sold to the user when late payment succeeded without reservation completion.
- **FR-015**: All reservation state MUST be enforced server-side; users MUST NOT be able to view, modify, or act on another user's reservation.

**Payment**

- **FR-016**: Payment MUST be performed through a third-party payment provider's hosted payment interface: the API prepares/initiates the payment and hands the user over to the provider-hosted page; the frontend's role in this flow (redirect handling) MUST be documented but no client-side code is built.
- **FR-017**: Reservation/business logic MUST NOT depend directly on a specific payment provider; provider interaction MUST be behind a provider-independent boundary so that another typical payment provider can be integrated later. (The concrete interface design is a technical-plan decision; no provider or SDK is chosen at specification time.)
- **FR-018**: Matching payment success verified server-side while the hold is active MUST complete the reservation: the seats become sold and the reservation reflects completed status. Late confirmation is governed by FR-013; unconditional completion after expiration is not permitted by this requirement.
- **FR-019**: The system MUST process provider-to-server payment notifications only after verifying their authenticity. Before completion, it MUST verify that the confirmation matches the intended payment/reservation and expected amount and currency. Unknown or mismatched confirmations MUST NOT complete a reservation. A user login session is not required for this provider-verified flow.
- **FR-020**: Payment notification processing MUST be idempotent: repeated deliveries MUST NOT corrupt state or repeat side effects. A delayed failure notification MUST NOT undo a completed reservation or release its sold seats. Re-delivery of an already processed late success MUST preserve its recorded completion or non-completion outcome; it MUST NOT retry allocation merely because availability later changes.
- **FR-021**: Unsuccessful payment outcomes MUST be handled explicitly (see FR-012) and MUST NOT silently leave ambiguous state.
- **FR-022**: The browser/redirect flow MUST NOT be the sole source of payment truth — payment completion MUST be confirmed server-side via provider-confirmed data (e.g., a verified notification), not only by what the returning browser reports.

**Data integrity & security**

- **FR-023**: The system MUST validate all external input to reservation and payment actions (seat references, formats, event consistency).
- **FR-024**: The system MUST NOT expose sensitive payment/provider data (provider secrets, tokens, credentials) in API responses.
- **FR-025**: The seats-availability API MUST NOT include held or sold seats in its response (this is the endpoint whose requirement is specifically to return available seats only).

**Automated tests**

- **FR-026**: Automated tests MUST cover authentication and ownership; listing/viewing events; available-seat filtering; active holds hiding seats; expired holds releasing seats without cleanup; reservation creation and invalid/unavailable selections; cross-event rejection; duplicate seat safety; same-user and cross-user seat competition; successful matching payment confirmation during an active hold; failed payment retaining seats before original expiry without changing that expiry; release at the exact expiry and afterwards; delayed failure not restoring an expired hold or undoing completion; provider verification without user login; rejection of mismatched payment confirmations; duplicate notification safety; and late confirmation at or after expiry with payment occurring both before and after expiry: all seats available, any seat sold or actively held, no partial allocation, concurrent rebooking, owner-visible successful-payment/uncompleted-reservation outcome, and duplicate delivery after availability changes. Tests MUST also cover owner retries after failure within the original window, unchanged expiry and seats, successful retry completion, and rejection of new attempts at or after expiry or after completion. Tests MUST verify all-or-nothing creation for mixed available/unavailable selections and concurrent overlapping selections, including that failed requests leave no partial reservation or residual holds. Tests MUST also cover registration and login success, invalid registration, duplicate identity, invalid credentials, event-level uniform pricing, exact totals for fractional prices, rejection of excess monetary precision, client price tampering, stable reservation totals across payment retries, and amount/currency matching including a one-minor-unit mismatch.
- **FR-027**: Tests MUST NOT use real payment transactions or uncontrolled external services; provider behavior MUST be simulated via the provider-independent boundary.

**Documentation**

- **FR-028**: The project MUST include a README with setup/run instructions, API usage, important design decisions and assumptions, testing instructions, the frontend role in hosted payment (without client-side implementation), and the limitation that a late successful payment can remain without a completed reservation and without automatic refund or settlement handling. The submission MUST also include a brief explanation of how AI was used and for what, or the relevant AI conversation, as requested in the assessment email; the README may provide this explanation or link.

**Pricing and monetary accuracy**

- **FR-029**: Each seeded event MUST have one server-controlled seat price shared by all its seats; different events MAY have different prices. The reservation total MUST equal the number of distinct selected seats multiplied by that event's seat price. The reservation MUST retain its agreed total and currency for all payment attempts and confirmations. Client-supplied prices or totals MUST NOT override the server calculation. No price administration, discounts, or multi-currency features are introduced.
- **FR-030**: Prices, totals, and payment comparisons MUST be exact at the configured currency's smallest supported monetary unit (minor unit), without rounding drift. Values with precision finer than that unit MUST be rejected rather than silently rounded. For a currency with two decimal places, a price of 10.10 for three seats MUST total exactly 30.30 (3030 minor units); a confirmed amount differing by even one minor unit MUST NOT complete the reservation. The currency precision MUST be respected rather than assuming every currency uses two decimal places. The API and provider boundary MUST document their amount units and preserve the exact value and currency through conversion. Storage types, exact arithmetic mechanisms, and provider-specific conversion belong in the technical plan.

### Key Entities *(include if feature involves data)*

- **User**: a person with credentials who can authenticate; owns reservations. Attributes: identity, authentication secret (mechanism unspecified at spec level).
- **Event**: an occurrence that has numbered seats. Attributes: unique identifier, name, and one uniform seat price. No administration after seeding.
- **Seat**: a numbered place belonging to exactly one event. Attributes: number/label, event membership. Its availability MUST be consistent with completed reservations and unexpired holds. Storage representation is a planning decision.
- **Reservation**: a user's hold on one or more seats of a single event. Attributes: owning user, associated event, held seats, status (active hold → completed or expired; an expired reservation may complete through FR-013; a payment failure alone does not end the hold), creation time, expiration time (creation + 30 minutes), total price.
- **Payment**: the provider-driven transaction attached to a reservation. Attributes: provider-independent reference, amount, outcome. Successful payment and reservation completion are distinct outcomes: late success can leave the reservation uncompleted. Payment truth comes from verified provider confirmation, not from the browser redirect.
- **Price/Money**: one fixed currency and a uniform seat price for each event. The server calculates the exact reservation total from the distinct seat count and event price, and preserves that agreed total for payment attempts. Amounts respect the currency's minor-unit precision (FR-029/FR-030); representation belongs in planning.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Given seeded events with seats, a client can list events and view an event's available seats, and the response never contains held or sold seats (verified by test).
- **SC-002**: A reservation created by an authenticated user holds its seats such that no other user can reserve them while the hold is active; this holds under concurrent attempts — at most one competing reservation per seat succeeds and every multi-seat request acquires its entire selection or leaves no reservation holding any subset. Verify both pre-existing conflicts and concurrent overlapping selections, including the absence of residual holds from losing requests, under realistic concurrent execution per the constitution; verification infrastructure is a planning decision.
- **SC-003**: A seat whose hold has expired is bookable by another user immediately, with no background process having run (verified by test: expire the hold, do NOT run cleanup, attempt new reservation → succeeds).
- **SC-004**: Matching payment success verified during an active hold completes the reservation. Failure leaves the incomplete hold active before its original expiry without changing that expiry; at or after expiry it no longer blocks seats, without cleanup. The owner can retry a failed payment before original expiry on the same reservation without changing its seats or expiry; new attempts at or after expiry or after completion are rejected. Duplicate notifications have no repeated effects, and delayed failures do not undo completion (verified by tests).
- **SC-005**: For a matching verified success processed at or after expiry, all original seats available means the reservation completes and all become sold, regardless of payment time. Any unavailable original seat means successful payment is recorded without reservation completion or any allocation. Concurrent rebooking never causes double allocation; duplicate delivery preserves the first processed outcome even if availability changes. These outcomes and owner-visible status are verified by tests.
- **SC-006**: User B cannot view or modify user A's reservation, and user B's reservation listing never contains user A's reservations; all user-initiated reservation/payment actions require authentication; a logged-out token authorizes nothing (FR-003); provider notifications require independent verification and payment matching instead of user login (verified by tests).
- **SC-007**: The automated test suite passes in a fresh clone after documented setup steps, covering all behaviors in FR-026.
- **SC-008**: Swapping the payment provider implementation behind the provider-independent boundary requires no changes to reservation/business logic (verified by the tests running entirely against a simulated provider).

- **SC-009**: A client can register and log in through the API; invalid or duplicate registration and invalid login credentials do not grant access (verified by tests).
- **SC-010**: Every seat in an event uses the same price; reservation totals equal distinct seat count times that price exactly. Fractional-price cases, excess precision, client price tampering, stable retry totals, amount-unit conversion, and a one-minor-unit payment mismatch are verified by tests under FR-029/FR-030.

## Assumptions

- **Single currency**: The PDF does not mention currencies; the system operates in one fixed currency (currency choice and representation decided at planning).
- **Standard authentication**: The PDF says "the exact authentication approach is up to you"; a standard token/session-based API authentication is assumed, decided concretely at planning.
- **Seed data**: Events, their uniform seat prices, and seats are provided via database seeders with a small representative data set (a couple of events, a handful of seats each). No admin UI/API.
- **Scope boundaries**: No frontend code, no admin dashboard, no notifications/emails, no refunds, no coupons/discounts, no multi-currency, no social auth, no password reset, no email verification, no complex roles/permissions, no event/seat management CRUD — per the assessment and constitution.
- **Time source**: Expiration is evaluated using authoritative server time, not a client-supplied time. Clock and testing mechanisms belong in planning.
- **Public browsing**: Anonymous browsing is a documented minimal-access assumption, not an explicit assessment requirement.
- **Reservation details**: Owner-only detail retrieval supports observing the hold and payment outcome; it does not introduce reservation history, cancellation, or management features.
- **30 minutes fixed**: The hold duration is the PDF's stated 30 minutes; it is not configurable per event in scope.

## Clarifications

### Session 2026-09-18

- Q: Does failed payment release seats immediately or retain the hold? → A: Retain the hold until the original 30-minute expiration. Failure neither releases seats early nor restarts the timer; an expired incomplete hold no longer blocks seats, independently of cleanup. Confirmed by the user; do not ask again.

- Q: Can late success complete an expired reservation even if payment occurred after expiry? → A: Yes. At processing time, atomically acquire all original seats and complete only if every seat is available; otherwise record successful payment without completing the reservation or allocating seats. Never displace another reservation or restart the hold. No automatic refund is added. User selected A.

- Q: May the owner retry a failed payment on the same reservation during the remaining original hold window? → A: Yes. Allow another attempt before the original expiry without changing seats or restarting/extending the timer. User selected A.

- Q: If any selected seat becomes unavailable during reservation creation, should the entire request fail or partially succeed? → A: Reject the entire request and allocate none of the seats. No partial reservation or residual hold may remain from the failed request, including under concurrency. User selected A.

- Q: Should accounts be self-registered through the API or only seeded? → A: Provide API registration and login. User selected 1A; seeded accounts do not replace registration.

- Q: Should pricing be uniform per event or independent per seat? → A: One uniform seat price per event; total equals distinct seat count times that price. Different events may have different prices. User selected 2A.

- Monetary accuracy follow-up: The user's minor-unit concern is covered by FR-030. Exact amounts, declared units, currency precision, and payment amount matching are required; storage representation and provider conversion remain planning decisions.

No material business clarification questions remain from this session. Technical planning must choose the payment provider, authentication mechanism, production-compatible concurrency test setup, fixed currency, and exact monetary representation and conversion. It must preserve all confirmed behavior without adding scope.
