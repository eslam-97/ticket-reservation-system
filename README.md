# Ticket Reservation API

A small API-only ticket reservation service built with **Laravel 13, PHP 8.4, and MySQL 8.4**.

Users can browse events, reserve seats for 30 minutes, and pay through a hosted payment provider.

> **About the design:**  
> The task asks for a small service, but I intentionally included some design ideas that are more useful in larger systems. The goal was not to over-engineer this task, but to show how I think about concurrency, payments, failures, and data consistency when the system becomes more complex.

## Stack

- Laravel 13 / PHP 8.4
- MySQL 8.4
- Sanctum authentication
- Pest + MySQL tests
- Fake and Stripe payment drivers
- Docker Compose

## Run locally

The application and database run entirely in Docker. You do **not** need PHP, Composer, or MySQL installed locally.

### Requirements

- Docker Desktop with Docker Compose
- **GNU Make** for the `make` commands below

> **Windows:** GNU Make is not included with Docker Desktop. Install it separately, for example:
>
> ```powershell
> choco install make
> ```
>
> Verify:
>
> ```bash
> docker --version
> docker compose version
> make --version
> ```

### Using Make

```bash
git clone <repo>
cd <repo>

make up
make test
```

API:

```text
http://localhost:8000/api/v1
```

Demo user:

```text
demo@example.com
password
```

Useful commands:

```bash
make logs
make shell
make fresh
make down
```

### Without Make

Make is only a convenience wrapper around Docker Compose. You can run the equivalent Docker commands directly.

For example:

```bash
docker compose up -d
docker compose exec -T app php artisan migrate --force
docker compose exec -T app php artisan db:seed --force
docker compose exec -T app env APP_ENV=testing DB_DATABASE=tickets_test vendor/bin/pest
```

For the full setup flow, `make up` is recommended because it also handles build, dependencies, health checks, and initialization.

### Completing a payment locally

The default driver is `fake`, so there is no hosted page to click. Simulate the provider's signed webhook:

```bash
make fake-webhook ID=<payment_id> TYPE=succeeded
# or failed / expired
```

Without Make:

```bash
docker compose exec -T app php artisan payments:fake-webhook <payment_id> --type=succeeded
```

`payment_id` comes from the checkout response. The command signs the body the same way a real provider would, and prints the equivalent curl.

To use real Stripe:

```text
PAYMENTS_DRIVER=stripe
STRIPE_SECRET_KEY=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

```bash
stripe listen --forward-to localhost:8000/api/v1/webhooks/payments/stripe
```

## API

```text
POST   /auth/register
POST   /auth/login
POST   /auth/logout
GET    /events
GET    /events/{id}
GET    /events/{id}/seats
POST   /reservations
GET    /reservations/{id}
POST   /reservations/{id}/checkout
DELETE /reservations/{id}
POST   /webhooks/payments/{provider}
```

Main errors:

```text
409  conflict
410  expired / not pending
422  validation error
502  provider rejected request
503  provider temporarily unavailable
```

## Frontend role

The backend depends on the client for one thing: the payment redirect.

1. `POST /reservations` → show a countdown from the returned `expires_at` (server time, not the device clock).
2. `POST /reservations/{id}/checkout` → redirect the user to `checkout_url`.
3. When the user returns, **the return URL is not proof of payment.** Poll `GET /reservations/{id}` until `status` is `confirmed`, or the latest payment attempt is `failed` (then offer checkout again), or `status` is `expired`.
4. To change seats while a reservation is pending, `DELETE` it first — the API never silently replaces or merges holds.


## How it fits together

```text
CLIENT                     API + DATABASE                          PROVIDER

POST /reservations   ──▶   lock the seats (NOWAIT)
                           release any expired holds on them
                           reservation: pending, 30-minute deadline
                     ◀──   201  expires_at

POST /checkout       ──▶   payment attempt: initiated        ──▶   create hosted session
                     ◀──   checkout_url                      ◀──   session url

redirect the user    ──────────────────────────────────────▶      user pays on the hosted page

                           verify signature, amount, currency ◀──   signed webhook
                           payment: succeeded
                           reservation: confirmed
                           seats: sold

GET /reservations/id ──▶   poll
                     ◀──   confirmed
```

The reservation row is the centre of everything. Seats hang off it as claims, payment attempts hang off it as history, and no other table decides whether a seat is free — availability is always read from the claims plus the reservation's deadline.

**A held seat leaves that state in exactly four ways:**

```text
payment confirmed   ──▶  sold        (final)
hold expires        ──▶  available   (immediately on read; released on the next reservation)
user cancels        ──▶  available   (final — a later payment is refunded, not confirmed)
payment fails       ──▶  still held  (retry checkout until the deadline)
```

**Where each part sits:**

```text
HTTP  ──▶  Action  ──▶  reservation + payment state machines  ──▶  MySQL constraints
                   ──▶  PaymentGateway  ──▶  fake | stripe
```

The action layer holds the rules, the state machines refuse illegal transitions, and the database constraints catch anything both of them miss. The scheduler runs the same actions on a timer to tidy expired holds and settle payments whose webhook never arrived.


## Design points

### 1. Seat locking

Two users can try to reserve the same seat at almost the same time.

The reservation runs inside a database transaction and locks the requested seats with `FOR UPDATE NOWAIT`.

This means:

```text
User A → gets the seat
User B → gets 409
```

The database also has a unique constraint on active seat claims. The lock handles normal concurrency, while the database constraint is the final safety check.

### 2. Reservation expiry

A reservation normally lasts 30 minutes:

```text
expires_at = min(now + 30 minutes, event.starts_at)
```

The application does not depend on the scheduler to decide whether a seat is expired.

Once `expires_at` passes, the reservation is treated as expired immediately. When another user tries to take the seat, expired claims are released inside the same transaction.

The scheduler mainly cleans up stored state in the background.

### 3. One pending reservation per user

A user can have only one pending reservation for an event.

```text
Same seats       → return the existing reservation
Different seats  → 409
```

This prevents accidental duplicate reservations and also stops one user from holding many sets of seats.

### 4. Payment abstraction

Reservation logic does not know about Stripe.

It uses a `PaymentGateway` interface, with:

```text
fake
stripe
```

This keeps payment logic separate from the business logic and makes another provider easier to add later.

The fake provider also makes the whole flow testable without a real payment account.

### 5. Idempotency

Network requests can fail after the server or payment provider has already completed the operation.

For example:

```text
Create checkout
      ↓
Provider succeeds
      ↓
Response is lost
      ↓
Client retries
```

The system reuses the same payment attempt and idempotency key instead of creating another payment session.

The same idea is used for reservation retries.

### 6. Webhooks are the payment source of truth

The user returning from the payment page does not mean the payment succeeded.

The reservation is completed only after a valid signed webhook confirms the payment.

The webhook also checks the payment amount and currency before confirming the reservation.

### 7. Money handling

Money is stored as integer minor units instead of floating-point numbers.

Example:

```text
EGP 100.50 → 10050
```

The server calculates the amount and stores a price snapshot when the reservation is created.

The client never sends the payment amount.

### 8. State and failure handling

Reservation and payment states use guarded transitions.

This helps handle cases such as:

```text
payment succeeds after the reservation expires
payment arrives after cancellation
duplicate webhooks
provider timeouts
refund failures
```

For example, an expired reservation can still be confirmed by a late payment if the seats are still free. A cancelled reservation is final, so a late payment is refunded instead.

### 9. Database as the final safety layer

Important rules are enforced in the database, not only in PHP code.

For example:

```text
One active claim per seat
One pending reservation per user/event
One open payment attempt per reservation
```

This protects the system even if application code later contains a bug.

## Assumptions

- One event per reservation, max 10 seats.
- Holds can only start while the event hasn't begun; `expires_at` is clamped to `starts_at`.
- `GET /events` lists past events too — the sales-close rule governs reserving, not browsing.
- Cancellation is final: a payment arriving after a cancel is refunded, never confirmed.
- Holds are never extended, including while a checkout session is open.
- Cancelling a confirmed reservation, partial refunds, wallets and payouts are out of scope.
- No admin APIs; events and seats are seeded.

## Testing

Tests use **real MySQL** because the system depends on MySQL locking and generated columns.

The suite covers:

- Concurrent seat reservations
- Expired-seat reclaim
- Reservation retries
- Payment success/failure
- Duplicate webhooks
- Late payments
- Cancellation
- Provider failures
- Lock contention
- Authorization and validation

With Make:

```bash
make test
```

Without Make:

```bash
docker compose exec -T app php artisan config:clear
docker compose exec -T app env APP_ENV=testing DB_DATABASE=tickets_test vendor/bin/pest
```

The detailed decisions and rejected alternatives are in:

```text
docs/DESIGN_DECISIONS.md
```