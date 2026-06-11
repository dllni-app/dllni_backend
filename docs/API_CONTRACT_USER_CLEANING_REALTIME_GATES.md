# API Contract Ã¢â‚¬â€œ User app: cleaning realtime gates (start verification & completion)

**Audience:** Flutter / mobile (customer app)  
**Base URL:** Same as other user APIs, e.g. `https://dllni.mustafafares.com`  
**Prefix:** `/api/v1/user/...`  
**Auth:** Laravel Sanctum Ã¢â‚¬â€œ `Authorization: Bearer {token}` on all routes below.

This document covers **customer-only** HTTP actions that pair with worker lifecycle steps and **private Pusher** events on the booking channel. For worker endpoints (travel, arrive, security code, complete, etc.), 

---

## 1. Lifecycle overview (happy path)

1. Worker accepts Ã¢â€ â€™ `worker_assigned`.
2. Worker **start travel** Ã¢â€ â€™ still `worker_assigned`, `startedTravelAt` set.
3. Worker **arrive** Ã¢â€ â€™ status becomes **`awaiting_start_verification`**, `arrivedAt` set. Server broadcasts gate + tracking events (see Ã‚Â§4).
4. Worker calls **`GET /api/v1/cleaning-bookings/{id}/security-code`** (worker token) and reads the **4-digit** code to the customer.
5. Customer submits **`POST Ã¢â‚¬Â¦/cleaning/orders/{order}/start-verification/confirm`** with `{ "code": "0123" }`. On success the booking becomes **`awaiting_worker_start_confirmation`**; work does not start yet. Server broadcasts **`ArrivalVerified`** and **`CleaningBookingTrackingUpdated`**.
6. Worker confirms start with **`POST Ã¢â‚¬Â¦/cleaning-bookings/{id}/start-work`**. When the required worker approvals are complete, the booking becomes **`in_progress`** and `workStartedAt` is set.
7. Worker finishes on site Ã¢â€ â€™ **`POST Ã¢â‚¬Â¦/cleaning-bookings/{id}/complete`** Ã¢â€ â€™ status **`awaiting_customer_completion`**, `workFinishedAt` set. Server broadcasts **`cleaning_order.awaiting_customer_completion`** and tracking.
8. Customer either **confirms** completion, **rejects** (reopens job), or **requests extension** using the endpoints in Ã‚Â§3.

**Note:** While the worker app may still call **`POST Ã¢â‚¬Â¦/cleaning-bookings/{id}/start-work`** from `worker_assigned` without going through arrival + code (legacy compatibility), the recommended flow is: arrive Ã¢â€ â€™ code Ã¢â€ â€™ customer confirm Ã¢â€ â€™ worker start confirm.

---

## 2. HTTP endpoints (customer)

All paths are under **`/api/v1/user`**. `{order}` is the cleaning booking **numeric id** (same as `data.id` on order resources).

| Method | Path | Description |
| ------ | ---- | ----------- |
| POST | `/cleaning/orders/{order}/start-verification/confirm` | Customer enters the 4-digit code shown by the worker. |
| POST | `/cleaning/orders/{order}/completion/confirm` | Customer approves completion (`awaiting_customer_completion` Ã¢â€ â€™ `completed`). |
| POST | `/cleaning/orders/{order}/completion/reject` | Customer says work is not finished (`awaiting_customer_completion` Ã¢â€ â€™ `in_progress`, clears `workFinishedAt`). |
| POST | `/cleaning/orders/{order}/completion/extend-time` | Customer asks for more time (`awaiting_customer_completion` Ã¢â€ â€™ `time_extension_requested`). |

**Success:** HTTP **200**, body matches other user cleaning actions: `{ "data": { Ã¢â‚¬Â¦CleaningBookingResourceÃ¢â‚¬Â¦ }, "message": "Ã¢â‚¬Â¦" }` (message is translated string).

**Ownership:** Only the **customer** who owns the booking may call these. Others get **404** (id scoped to `customer_id`).

### 2.1 Start verification confirm

- **Middleware:** `throttle:cleaning-start-verification` Ã¢â‚¬â€œ **5 requests per minute** per authenticated user **and** per `{order}` (see `AppServiceProvider`).
- **Body (JSON):**

```json
{
  "code": "1234"
}
```

| Field | Type | Rules |
| ----- | ---- | ----- |
| code | string | Required; exactly **4** digits (`0-9`). |

**Valid booking status:** `awaiting_start_verification` for first verification; duplicate submissions may return the current booking while already in `awaiting_worker_start_confirmation`.

**Behavior:** Verifies the code against the latest `booking_security_codes` row (HMAC-SHA256 with `APP_KEY`, TTL, attempt counter). On match, marks the code consumed and sets booking to **`awaiting_worker_start_confirmation`**, sets `customerConfirmedAt`, and leaves `workStartedAt` null until worker start confirmation.

**Errors:**

- **422** Ã¢â‚¬â€œ Validation (bad `code` format), wrong code (`errors.code`), expired code, no code row, or booking not in `awaiting_start_verification`.
- **429** Ã¢â‚¬â€œ Too many failed attempts for this code row (HTTP exception; not Laravel validation shape).

**Idempotency:** If the code row is **already consumed**, the server returns **200** with the current booking (no error).

### 2.2 Completion confirm

- **Body:** empty object `{}` accepted.

**Valid status:** `awaiting_customer_completion` Ã¢â€ â€™ **`completed`**.

Dispatches **`CompletionDecisionMade`** with `decision: "approved"` (see Ã‚Â§4).

### 2.3 Completion reject

**Body (optional):**

```json
{
  "reason": "Optional text, max 500 chars"
}
```

**Valid status:** `awaiting_customer_completion` Ã¢â€ â€™ **`in_progress`**, `workFinishedAt` cleared.

Dispatches **`CompletionDecisionMade`** with `decision: "rejected"`.

### 2.4 Completion extend time

**Body (required):**

```json
{
  "additionalMinutes": 30
}
```

| Field | Type | Rules |
| ----- | ---- | ----- |
| additionalMinutes | integer | Required; 1-480. |

**Valid status:** `awaiting_customer_completion` -> **`time_extension_requested`**.

Dispatches **`CompletionDecisionMade`** with `decision: "extension_requested"` and creates a quoted extension warning for worker handling.

---

## 3. Status enum (relevant values)

String values on booking `status` (camelCase in JSON per API resources):

| Value | Meaning (short) |
| ----- | ----------------- |
| `worker_assigned` | Worker assigned; may be en route. |
| `awaiting_start_verification` | Worker arrived; waiting for customer 4-digit code. |
| `awaiting_worker_start_confirmation` | Customer entered the correct code; waiting for worker to confirm start. |
| `in_progress` | Service in progress. |
| `awaiting_customer_completion` | Worker marked work finished; customer must confirm / reject / extend. |
| `time_extension_requested` | Customer asked for more time from completion gate. |
| `completed` | Customer confirmed completion. |

---

## 4. Realtime (Pusher / Laravel Echo)

### 4.1 Channel auth

- **Subscribe (client channel name):** `private-cleaning-booking.{bookingId}` (Laravel `PrivateChannel('cleaning-booking.{id}')` Ã¢â€ â€™ Pusher prefix `private-`).
- **Auth:** `POST /broadcasting/auth` with Sanctum token; body includes `channel_name` and `socket_id` per Pusher docs.
- **Authorization:** Customer **or** assigned worker for that booking may subscribe (see `AppServiceProvider::bootBroadcastChannels`).

**Worker-only channel:** Some events also broadcast to `private-cleaning-worker.{workerId}` for the assigned worker (see payloads below).

### 4.2 Events on `private-cleaning-booking.{bookingId}`

| Client event name | When | Payload (top-level keys) |
| ----------------- | ---- | ------------------------- |
| `CleaningBookingTrackingUpdated` | Most status/timestamp changes | `{ "tracking": { "cleaningBookingId", "status", "workerId", "startedTravelAt", "arrivedAt", "workStartedAt", "workFinishedAt", "customerConfirmedAt", "cancelledAt", "updatedAt" } }` |
| `WorkerLocationUpdated` | Worker sends location while en route | latitude, longitude, workerId, updatedAt |
| `WorkerArrived` | Worker taps arrive | cleaningBookingId, arrivedAt |
| `cleaning_order.awaiting_start_verification` | After worker **arrive** | cleaningBookingId, workerId, status (`awaiting_start_verification`), expiresAt (ISO; security code expiry hint, may be null) |
| `cleaning_order.awaiting_customer_completion` | After worker **complete** | cleaningBookingId, workerId, status (`awaiting_customer_completion`), expiresAt (server-chosen completion window hint) |
| `ArrivalVerified` | After customer **start-verification confirm** | cleaningBookingId, workerId, arrivedAt, status (`awaiting_worker_start_confirmation`), version |
| `CompletionDecisionMade` | After customer completion **confirm** / **reject** / **extend-time** | cleaningBookingId, workerId, decision (`approved` \| `rejected` \| `extension_requested`), message, decidedAt, version |
| `ServiceExtensionRequested` | When backend creates a cleaning time warning row for the booking | warningId, cleaningBookingId, workerId, requestedMinutes, additionalAmount, currency, version |

`ArrivalVerified` and `CompletionDecisionMade` are also sent on **`private-cleaning-worker.{workerId}`** when `workerId` is set, so the worker app can refresh without relying only on the booking channel.

---

## 5. Security notes (client)

- The **plaintext 4-digit code** is only returned to the **worker** (`GET Ã¢â‚¬Â¦/security-code`). The customer app should **never** display a stored code from the API; they type what the worker shows.
- Wrong codes increment server-side attempts; after repeated failures the API may return **429** on confirm.

---

## 6. Flutter realtime implementation contract (customer app)

Use this as the implementation baseline for order details screens and multi-device sync.

### 6.1 Realtime channel setup

1. Build channel name: `private-cleaning-booking.{bookingId}`.
2. Authenticate private channel via `POST /broadcasting/auth` with user Sanctum token.
3. Subscribe only after:
   - booking id exists,
   - token exists,
   - screen is active.
4. Unsubscribe when leaving order details to avoid duplicate handlers.

### 6.2 Event handling contract

On `private-cleaning-booking.{bookingId}`, bind:

- `CleaningBookingTrackingUpdated`
- `WorkerLocationUpdated`
- `WorkerArrived`
- `cleaning_order.awaiting_start_verification`
- `cleaning_order.awaiting_customer_completion`
- `ArrivalVerified`
- `CompletionDecisionMade`
- `ServiceExtensionRequested`

Client behavior:

- `WorkerLocationUpdated`: patch map position directly from payload.
- Any other event above: refetch `GET /api/v1/user/cleaning/orders/{bookingId}` and replace current order state.
- After successful action POSTs (`start-verification/confirm`, completion actions), use response `data` immediately; optional refetch for parity.

### 6.3 Payload DTOs (wire shape)

```json
{
  "cleaningBookingId": 123,
  "workerId": 45,
  "arrivedAt": "2026-04-22T10:15:00+00:00",
  "version": 1
}
```

`ArrivalVerified`

```json
{
  "cleaningBookingId": 123,
  "workerId": 45,
  "decision": "approved",
  "message": null,
  "decidedAt": "2026-04-22T11:00:00+00:00",
  "version": 1
}
```

`CompletionDecisionMade` (`decision`: `approved` | `rejected` | `extension_requested`)

```json
{
  "warningId": 789,
  "cleaningBookingId": 123,
  "workerId": 45,
  "requestedMinutes": 30,
  "additionalAmount": 4500,
  "currency": "SYP",
  "version": 1
}
```

`ServiceExtensionRequested` (`requestedMinutes` may be `null`; `additionalAmount` may be numeric string or number)`

### 6.4 Dart-style reference snippet

```dart
final channel = pusher.subscribe('private-cleaning-booking.$bookingId');

void handleCleaningEvent(String eventName, Map<String, dynamic> payload) {
  switch (eventName) {
    case 'WorkerLocationUpdated':
      updateMapFrom(payload);
      return;
    case 'ArrivalVerified':
    case 'CompletionDecisionMade':
    case 'ServiceExtensionRequested':
    case 'CleaningBookingTrackingUpdated':
    case 'WorkerArrived':
    case 'cleaning_order.awaiting_start_verification':
    case 'cleaning_order.awaiting_customer_completion':
      refetchOrder(bookingId);
      return;
    default:
      return;
  }
}
```

For a longer Flutter checklist and QA scenarios, see [FLUTTER_CLEANING_USER_REALTIME_CHANGES_AND_GUIDE.md](FLUTTER_CLEANING_USER_REALTIME_CHANGES_AND_GUIDE.md).
