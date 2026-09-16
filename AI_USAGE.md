# How I Used AI During This Task

I used Claude throughout the task as a supporting tool for both design exploration and implementation.

## Design

I used Claude to explore different approaches and trade-offs rather than asking it to produce one final design.

We discussed areas such as:

- Seat locking
- Reservation expiry
- Payment handling
- Idempotency
- Database design

I then evaluated the alternatives, chose the final approach, and documented the decisions.

## Reviewing the AI

The initial suggestions were not always correct. I tested them against real scenarios and found several issues, including:

- Expired seats remaining blocked by database constraints
- Different lock orders causing possible deadlocks
- Late payments conflicting with expired reservations
- Retries creating duplicate payment sessions
- Cancelled reservations being restored by late payments

I adjusted the design and implementation to handle these cases.

## Implementation

Claude Code helped implement the system based on the design and decisions I had made.

I reviewed the resulting code and tests, and used the test suite and real scenarios to verify that the implementation matched the intended behaviour.

## What AI Did Not Decide

I made the final decisions about:

- Architecture
- Cancellation rules
- Late-payment behaviour
- Reservation rules
- Trade-offs and failure handling

AI was used as a development aid for exploration and implementation, while the final technical decisions and validation remained mine.