# Flutter Realtime Changes Needed (Cleaning + User Apps)

Date: 2026-05-03
Backend: `Dllni_backend`

## Why this doc exists

We investigated `403` on `POST /broadcasting/auth` for:

`channel_name=private-cleaning-worker.{id}`

Root causes found:

1. Cleaning app could fall back to `user.id` when `workerId` was missing.
2. Old cached `worker_id` values might still exist on devices.
3. Backend broadcast auth is now finalized for Sanctum + private channels.

This file explains exactly what Flutter teams should update.

---

## Backend contract (current)

- Broadcast auth endpoint: `POST /broadcasting/auth`
- Auth method: `Authorization: Bearer {sanctum_token}`
- Private channels:
  - `private-cleaning-booking.{bookingId}`
  - `private-cleaning-worker.{workerId}`
- Cleaning worker login payload now includes:
  - `user.workerId` (source of truth for worker channel subscription)

---

## Required changes in `dllni_cleaning_owner_app`

## 1) Use only `user.workerId` for worker channel

Current risky pattern:

`workerId = r.user?.workerId ?? r.user?.id`

Required change:

- Remove fallback to `user.id`.
- Subscribe only when `workerId` is non-null.
- If `workerId` is null, do not subscribe and show a controlled error (or force profile refresh/re-login).

Why: worker channel authorization checks the real `worker.id`, not `user.id`.

## 2) Refresh persisted `worker_id` strategy

- On successful login, overwrite local `worker_id` from `user.workerId`.
- If token exists but `worker_id` is missing/invalid on app start:
  - fetch worker profile endpoint and restore it, or
  - force logout and login.

## 3) Keep auth request format exactly

For `onAuthorizer` request to `/broadcasting/auth`:

- Headers:
  - `Authorization: Bearer ...`
  - `Accept: application/json`
  - `X-Requested-With: XMLHttpRequest`
- Body:
  - `socket_id`
  - `channel_name`

## 4) Subscribe flow order

1. Login success.
2. Save token.
3. Save `worker_id` from `user.workerId`.
4. Initialize Pusher.
5. Subscribe:
   - `private-cleaning-worker.{workerId}`
   - `private-cleaning-booking.{bookingId}` (when opening order details)

## 5) Pusher credentials

Ensure app uses same backend environment values:

- `PUSHER_APP_KEY`
- `PUSHER_APP_CLUSTER`

Prefer `--dart-define` in CI/CD over hardcoded defaults.

---

## Required changes in `dllni_user_app`

## 1) Keep user app on booking channel only

User app should subscribe to:

- `private-cleaning-booking.{bookingId}`

User app should not subscribe to worker-private channels.

## 2) Event names to listen for (booking channel)

- `CleaningBookingTrackingUpdated`
- `WorkerLocationUpdated`
- `WorkerArrived`
- `cleaning_order.awaiting_start_verification`
- `ArrivalVerified`
- `cleaning_order.awaiting_customer_completion`
- `CompletionDecisionMade`
- `ServiceExtensionRequested`

## 3) Keep same authorizer pattern

Use same `/broadcasting/auth` call style as cleaning app:

- bearer token
- `socket_id`
- `channel_name`

## 4) Token/session handling

If `/broadcasting/auth` returns `401`:

- treat as expired token
- refresh session or force login

If `403`:

- channel/id mismatch or unauthorized booking access
- verify booking id and authenticated ownership

---

## One-time QA checklist for both apps

1. Login to cleaning app with a worker account.
2. Confirm login response contains `user.workerId`.
3. Confirm cleaning app subscribes to `private-cleaning-worker.{workerId}` (not `user.id`).
4. Open cleaning order details in both apps.
5. Confirm both sides subscribe to `private-cleaning-booking.{bookingId}`.
6. Trigger arrival/completion flow and verify realtime events received on both apps.
7. Clear app storage and re-test fresh install behavior.

---

## Important note for existing testers

After these backend changes, testers should logout/login once in `dllni_cleaning_owner_app` so local `worker_id` is refreshed from `user.workerId`.
