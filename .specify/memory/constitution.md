<!--
## Sync Impact Report
- **Version change**: 1.1.0 → **1.1.1** — synchronize previously deferred payment guidance with the user's confirmed specification decisions; no new principle.
- **Modified principle**: VII (reference confirmed retry and late-success behavior).
- **Resolved specification decisions**: late success with all seats available; retries before original expiry; all-or-nothing creation; API registration/login; uniform event-level pricing.
- **Deferred technical decisions**: provider, authentication mechanism, production-compatible concurrency tests, fixed currency, exact monetary representation, and provider conversion.
- **Synchronized artifacts**: specs/001-ticket-reservation/spec.md; specs/001-ticket-reservation/checklists/requirements.md.
- **Templates**: no changes required; generic templates remain subordinate to this constitution.
-->

# Ticket Reservation System Constitution

## Core Principles

### I. Scope Discipline

Implement only behavior explicitly required by the technical assessment and by decisions clarified during specification. Do not add speculative features, infrastructure, APIs, or abstractions. The following remain out of scope unless the assessment specification explicitly requires them later: admin dashboards, event management, frontend/client code, notifications, refunds, coupons or discounts, social authentication, password reset, email verification, roles/permissions beyond what authorization requires, microservices, dedicated message brokers, search infrastructure, Kubernetes, and unnecessary Redis or other infrastructure. Rationale: this is a technical assessment; correctness and judgment are being evaluated, not breadth.

### II. Backend API Only

This project is an API/backend service. All deliverable code is server-side: HTTP API endpoints, application/business logic, database schema, and background jobs. Do not implement frontend or client-side application code. Starter-template artifacts that exist only because of the Laravel skeleton (e.g., Vite/Blade assets) are not deliverables and MUST NOT be extended.

### III. Simplicity Over Over-Engineering

Prefer simple, Laravel-native solutions that satisfy the requirements: controllers, form requests/validation, Eloquent models and migrations, authorization policies, and framework-native queueing or scheduling where appropriate. Do not introduce repositories, CQRS, event sourcing, microservices, unnecessary factories/managers, DTO layers, additional design patterns, or extra infrastructure unless a concrete specification requirement justifies them. When two designs both satisfy the spec, the simpler one MUST be chosen. The payment abstraction (Principle VII) is required by the assessment and is the explicit exception.

### IV. Data Integrity and Concurrency Safety (NON-NEGOTIABLE)

Seat reservation MUST be concurrency-safe. The system MUST never allow the same seat for the same event to be successfully reserved by multiple users. Availability checks and reservation writes MUST run inside a database transaction with appropriate row-level locking (e.g., `lockForUpdate()`); database-level guarantees are preferred wherever they are sufficient. Unlocked application-level checks are not acceptable for seat allocation.

The concurrency guarantee MUST actually be verified by tests. SQLite (including in-memory SQLite) MAY be used for general application tests, but its locking model MUST NOT be treated as sufficient evidence for database-specific concurrency or row-locking behavior where its semantics differ from the production database. Concurrency-specific tests MUST run on a database engine compatible with the production environment. The requirement is that the guarantee is verified — not that every test uses the production engine. Exact test infrastructure and database configuration are technical-planning decisions, not fixed by this constitution.

### V. Reservation Expiration

A temporary reservation is valid for 30 minutes. The `expires_at` timestamp stored on the reservation is the single source of truth for whether that reservation is still active; every availability check and reservation mutation MUST evaluate it from the stored data against the current time at request time. Seat availability MUST NOT depend on a background job or scheduler having executed on time: if the expiration job is delayed or temporarily unavailable, an expired reservation MUST still not make its seats appear unavailable. Background jobs MAY transition expired reservations and/or perform cleanup; they are an optimization, never the source of truth.

### VI. Available Seats

The API that exposes seats for an event MUST return only seats that are currently available. It MUST NOT expose actively held seats, completed/sold seats, or otherwise unavailable seats unless the final specification explicitly requires such information. Do not introduce separate APIs for held/sold seats, seat-status endpoints, or extra seat-management features.

### VII. Payment Abstraction

Reservation and business logic MUST NOT depend on a specific payment provider. All payment interaction MUST go through a small, clearly defined application-level contract; each provider implementation — including the hosted third-party payment interface required by the assessment — implements that contract and is bound through Laravel's service container, so it can be replaced or faked without touching business logic. No specific payment provider is chosen and no payment SDK is adopted by this constitution; those decisions belong to specification and planning.

A failed or abandoned payment MUST retain the incomplete reservation's seat hold until its original expiration (creation time + 30 minutes). Failure MUST NOT release seats early, restart or extend the timer, or complete the reservation. At or after the original expiration, that hold MUST no longer block its seats regardless of cleanup execution. A failure notification MUST NOT restore an expired hold or undo a completed reservation. Payment retries and late success outcomes follow the confirmed rules in the feature specification: retries are allowed within the original hold window; verified late success completes only if all original seats can be acquired together while available, otherwise payment success is recorded without reservation completion. Neither path extends the original hold.

### VIII. Authentication and Authorization

Reservation and payment actions MUST be associated with an authenticated user, and every reservation MUST belong to exactly one user. Users MUST NOT be able to view, modify, pay for, or otherwise access another user's reservations. Ownership MUST be enforced server-side (authentication middleware plus authorization policies), never inferred from client-supplied input. Provider-initiated webhooks are the transport-level exception and MUST be verified per Principle XI.

### IX. Automated Testing

Automated tests are a required deliverable. Tests MUST cover the important business behavior: authentication; available-seat calculation; reservation creation; the 30-minute expiration; reservation attempts on unavailable seats; authorization/cross-user access; payment success; payment failure behavior; payment webhook behavior; duplicate webhook delivery; and concurrent attempts to reserve the same seat. Tests MUST NOT use real payment transactions or uncontrolled external services. Keep testing focused on important behavior: tests for trivial framework-generated behavior (getters/setters, framework internals) are NOT required.

### X. Testability

Business logic MUST be designed so external payment providers can be replaced with fakes or mocks in automated tests, via the payment contract and the service container. Tests MUST NOT perform real payment transactions or call real provider endpoints; webhook scenarios are simulated with crafted test payloads.

### XI. Security

Validate all external input. Protect reservation and payment endpoints with authentication. Authorize every access to user-owned resources. Verify payment callbacks/webhooks (signature/secret or the provider's equivalent mechanism) before mutating reservation or payment state, and process webhook deliveries idempotently so repeated or duplicate payment events are handled safely. Do not expose sensitive payment/provider data (secrets, tokens, credentials) in API responses, logs, or test fixtures.

### XII. Documentation

The project MUST include documentation covering: setup instructions; API usage (endpoints, payloads, responses); important design decisions and assumptions (concurrency approach, expiration handling, payment abstraction); and testing instructions. Documentation is part of the deliverable and MUST be kept accurate as behavior changes.

### XIII. AI-Assisted Development

AI-assisted development is permitted. All generated code and design decisions MUST remain understandable, reviewable, and explainable by the developer submitting the assessment; anything that cannot be confidently explained MUST be rewritten or removed. Generated code MUST meet the same standards as hand-written code (Principles III, IX, XI). The submission MUST include a brief explanation of how AI was used and for what, or the relevant AI conversation, as requested in the assessment email.

## Technology Stack and Constraints

- **Framework**: Laravel `^13.17` on PHP `^8.3` (as pinned in `composer.json`), Eloquent ORM, migrations and seeders.
- **Testing**: PHPUnit `^12`; in-memory SQLite is preconfigured in `phpunit.xml` and MAY be used for general/fast application tests. Per Principle IV, concurrency/row-locking tests MUST use a database engine compatible with the production environment when SQLite cannot faithfully verify the behavior. Exact production/test database configuration is deferred to technical planning.
- **Background work**: Laravel's queue and scheduling capabilities are available and used only where appropriate (Principle III); they are never the source of truth for expiration (Principle V).
- **Code style**: Laravel Pint (already in `require-dev`).
- **Tooling already present**: Laravel Boost; Spec Kit (`.specify/`).
- **Dependencies**: no payment SDK or provider is selected at this stage. Additional packages are adopted only when the assessment specification requires them, justified against Principle III.
- **Deliverables**: backend code, migrations/seeders, tests, and documentation only (Principle II).

## Development Workflow and Quality Gates

- Development follows the Spec Kit flow already initialized in `.specify/`: constitution → specify → clarify → plan → tasks → implement, with `/speckit.analyze` (and `/speckit.converge` where applicable) checking specification/plan/task alignment against this constitution.
- A task or increment is complete only when:
  1. The test suite passes (`composer test` / `php artisan test`).
  2. Every abstraction and dependency traces to a specification requirement (Principles I, III).
  3. Concurrency-critical paths use transactions and locking correctly, and the guarantee is verified on an engine that exercises the production database's locking semantics (Principle IV).
  4. New endpoints are validated, authenticated, and authorized as applicable (Principles VIII, XI).
  5. Documentation reflects any behavior or setup change (Principle XII).
- Tests accompany (or precede) the behavior they cover; uncovered business behavior is not done (Principles IX–X).

## Governance

- This constitution supersedes other project practices and any agent/tooling defaults when they conflict; where a generic "best practice" contradicts a principle here, the principle wins — especially I–III.
- **Amendments**: edit this file, bump the version per SemVer (MAJOR: principle removed or redefined; MINOR: new principle or materially expanded guidance; PATCH: clarifications/wording), update **Last Amended**, and record the change in the Sync Impact Report comment. Superseded guidance MUST be removed rather than left to conflict.
- **Deferred decisions** (payment provider selection; database configuration for concurrency tests; remaining business decisions identified in the specification) MUST be resolved through specification/clarification and planning — never by silent implementation drift.
- **Compliance review**: verify each change against these principles before marking its task complete; remove or explicitly justify in the plan any complexity not traceable to the assessment.
- Runtime guidance files (e.g., `CLAUDE.md`, `AGENTS.md`) MUST NOT contradict this constitution.

**Version**: 1.1.1 | **Ratified**: 2026-09-18 | **Last Amended**: 2026-09-18
