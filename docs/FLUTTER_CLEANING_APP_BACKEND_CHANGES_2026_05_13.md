# Flutter Cleaning App - Backend Changes Handoff

Date: 2026-05-13  
Backend: `Dllni_backend`

## Summary
This document describes backend changes that affect Flutter cleaning app behavior for cleaning order lifecycle, realtime subscriptions, and login/session handling.

## 1) Cleaning Status Filter Alignment (422 fix)

### What changed
Backend status validation for cleaning order list filters now uses the full lifecycle enum instead of a hardcoded subset.

### Affected endpoint
- `GET /api/v1/user/cleaning/orders`

### Status values now accepted (full set)
- `pending`
- `worker_assigned`
- `awaiting_start_verification`
- `in_progress`
- `awaiting_customer_completion`
- `time_extension_requested`
- `completed`
- `cancelled`

### Flutter impact
- You can safely send `filter[status]` with gate statuses such as:
  - `awaiting_start_verification`
  - `awaiting_customer_completion`
  - `time_extension_requested`
- Backend should no longer return `422` for valid lifecycle states.

## 2) Realtime Gate Event Broadcast Scope Expanded

### What changed
`cleaning_order.awaiting_start_verification` now broadcasts to **two private channels**:
- `private-cleaning-booking.{bookingId}` (existing)
- `private-cleaning-customer.{customerId}` (new)

The event payload now includes:
- `cleaningBookingId`
- `customerId` (new)
- `workerId`
- `status`
- `expiresAt`

### Flutter impact
- Keep existing booking-channel subscription as primary.
- Add/keep support for customer-scoped channel subscription for global prompt handling:
  - `private-cleaning-customer.{customerId}`
- Listen for event name:
  - `cleaning_order.awaiting_start_verification`
- Route to secure-code dialog when event is received.

## 3) Token/Session Policy Updated (parallel QA stability)

### What changed
Login no longer deletes previous tokens for the same token name before issuing a new one.

### Flutter impact
- Parallel sessions/logins are now allowed (no forced invalidation caused by a new login on another device/session).
- Keep current logout behavior unchanged:
  - logout still revokes only the **current** access token.

## Flutter Implementation Checklist
- Confirm cleaning app subscribes to:
  - `private-cleaning-booking.{bookingId}`
  - `private-cleaning-customer.{customerId}`
- Confirm app listens to event:
  - `cleaning_order.awaiting_start_verification`
- Confirm secure-code prompt can be triggered from either channel.
- Confirm order list filters can request full lifecycle statuses without 422.
- Confirm login from another device/session does not force current app logout.

## QA Scenarios (recommended)
1. Worker arrives -> status becomes `awaiting_start_verification`.
2. Customer app receives gate event on booking channel.
3. Customer app receives gate event on customer channel.
4. Customer enters code -> order moves to `in_progress`.
5. `GET /api/v1/user/cleaning/orders?filter[status]=awaiting_start_verification` returns 200.
6. `GET /api/v1/user/cleaning/orders?filter[status]=awaiting_customer_completion` returns 200.
7. Login same account from second device/session does not revoke first session unexpectedly.

## Notes
- Broadcast auth remains Sanctum-based (`POST /broadcasting/auth`).
- Private channel authorization now includes `cleaning-customer.{customerId}` for the authenticated customer.