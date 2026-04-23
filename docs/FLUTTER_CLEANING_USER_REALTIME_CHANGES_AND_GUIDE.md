# Cleaning user module – realtime Flutter implementation guide (contract companion)

**Audience:** Flutter developers (customer / user app)  
**Scope:** Contract-oriented Flutter implementation for **private Pusher** channels, **broadcast events**, and customer-side realtime state handling for cleaning orders. HTTP details stay aligned with the API contract.

**Read next (reference):**

- [API_CONTRACT_USER_CLEANING_REALTIME_GATES.md](API_CONTRACT_USER_CLEANING_REALTIME_GATES.md) – full HTTP contract and event table  
- [FLUTTER_IMPLEMENTATION_CLEANING_REALTIME_GATES.md](FLUTTER_IMPLEMENTATION_CLEANING_REALTIME_GATES.md) – longer Flutter checklist, state diagram, QA scenarios  

This document is the **implementation spine**; the API contract now includes a dedicated Flutter realtime section that should be treated as authoritative for endpoint + event naming.

---

## 1. Backend changelog (what was added)

| Item | Description |
| ---- | ----------- |
| **Worker private channel auth** | `Broadcast::channel('cleaning-worker.{workerId}', …)` in `app/Providers/AppServiceProvider.php`. Only a `User` whose Sanctum identity is linked to that **worker id** may authorize `private-cleaning-worker.{workerId}`. **Customer Flutter apps do not subscribe here** unless you ship a combined worker+customer binary. |
| **Booking channel auth (unchanged behaviour, documented)** | `cleaning-booking.{bookingId}`: customer who owns the booking **or** assigned worker may subscribe. Customer app uses **`private-cleaning-booking.{id}`** only. |
| **`ArrivalVerified` event** | Fired only when the confirm handler **consumes** the code row and transitions the booking to `in_progress` (first successful verify in that window). If the code was **already consumed**, the API still returns **200** with the current booking but **does not** dispatch `ArrivalVerified` again — rely on refetch or prior events for idempotent UI. |
| **`CompletionDecisionMade` event** | Fired after customer **completion/confirm**, **completion/reject**, or **completion/extend-time**. Carries `decision`: `approved`, `rejected`, or `extension_requested`. |
| **`ServiceExtensionRequested` event** | Fired when a **`CleaningTimeWarning`** row is **created** for a cleaning booking (time / extension warning flow). Same payload semantics for booking + worker channels. Customer app should treat it as “something changed for this booking — refetch or show extension UX per product”. |
| **Dispatch wiring** | `Modules/User/app/Services/UserCleaningOrderService.php` dispatches `ArrivalVerified` / `CompletionDecisionMade`. `Modules/Cleaning/app/Observers/CleaningTimeWarningObserver.php` dispatches `ServiceExtensionRequested` on `CleaningTimeWarning::created`. |
| **Rate limit** | Start verification confirm: `throttle:cleaning-start-verification` (5/min per user per order). |

**Source files (for code spelunking):**

- `app/Providers/AppServiceProvider.php` – `bootBroadcastChannels()`  
- `Modules/Cleaning/app/Events/ArrivalVerified.php`  
- `Modules/Cleaning/app/Events/CompletionDecisionMade.php`  
- `Modules/Cleaning/app/Events/ServiceExtensionRequested.php`  
- `Modules/User/app/Services/UserCleaningOrderService.php`  
- `Modules/Cleaning/app/Observers/CleaningTimeWarningObserver.php`  

---

## 2. Flutter – channels to use (customer app)

| Purpose | Pusher / Echo channel name | Auth |
| ------- | --------------------------- | ---- |
| Order timeline, gates, all events below | `private-cleaning-booking.{bookingId}` | `POST {baseUrl}/broadcasting/auth` with Sanctum Bearer token; `channel_name` must be exactly `private-cleaning-booking.{bookingId}` |

`bookingId` = numeric cleaning booking id (same as `data.id` from user cleaning order APIs).

Do **not** hardcode cluster or host; reuse the same Pusher options as other modules (restaurant, supermarket, etc.) if the app already has realtime.

---

## 3. Events to bind (customer app)

Subscribe on **`private-cleaning-booking.{bookingId}`** and listen for these **exact** event names (Laravel `broadcastAs()` strings; no leading dot unless your client library adds a namespace — align with Laravel Echo / Pusher conventions you already use):

| Event name | When | Suggested client action |
| ---------- | ---- | ------------------------- |
| `CleaningBookingTrackingUpdated` | Many lifecycle updates | Refetch order or merge `tracking` if you maintain a typed model |
| `WorkerLocationUpdated` | Worker en route | Update map |
| `WorkerArrived` | Worker tapped arrive | Optional banner; prepare start-code UI |
| `cleaning_order.awaiting_start_verification` | After worker arrive | Show 4-digit code entry |
| `ArrivalVerified` | After customer confirms code | Close code UI; expect `in_progress` on refetch |
| `cleaning_order.awaiting_customer_completion` | After worker complete | Show completion actions |
| `CompletionDecisionMade` | After customer confirm / reject / extend | Sync multi-device UI; `decision` tells you what happened |
| `ServiceExtensionRequested` | After server creates a time warning for this booking | Refetch order / warnings list; optional in-app notice |

Existing gate events (`cleaning_order.*`) are still documented in the API contract; the **new** first-class events for customer decisions are **`ArrivalVerified`**, **`CompletionDecisionMade`**, and **`ServiceExtensionRequested`**.

---

## 4. JSON payloads (as broadcast on the wire)

Laravel puts these inside the Pusher payload (your Flutter client typically reads the inner map after the library unwraps the envelope). Field names are **camelCase** as below.

### 4.1 `ArrivalVerified`

```json
{
  "cleaningBookingId": 123,
  "workerId": 45,
  "arrivedAt": "2026-04-22T10:15:00+00:00",
  "version": 1
}
```

- `workerId` may be `null` if the booking has no worker assigned (edge case).  
- `arrivedAt` is the booking’s arrival timestamp when present; treat empty or missing display as “use refetched order timestamps”.

### 4.2 `CompletionDecisionMade`

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

`decision` is always one of:

- `approved` — `POST …/completion/confirm`  
- `rejected` — `POST …/completion/reject`  
- `extension_requested` — `POST …/completion/extend-time`  

`message` is reserved for future use; currently often `null`.

### 4.3 `ServiceExtensionRequested`

```json
{
  "warningId": 789,
  "cleaningBookingId": 123,
  "workerId": 45,
  "requestedMinutes": 30,
  "version": 1
}
```

- `requestedMinutes` mirrors `additional_minutes` on the warning row; may be `null`.  
- Use `warningId` if you call worker-facing extension APIs from a different surface; the **customer** app usually refetches **`GET /api/v1/user/cleaning/orders/{id}`** (or your list endpoint) and drives UI from resources.

**Dual broadcast:** `ArrivalVerified`, `CompletionDecisionMade`, and `ServiceExtensionRequested` are also published on **`private-cleaning-worker.{workerId}`** when `workerId` is non-null, so the worker app can refresh in parallel. The customer app only needs the **booking** channel.

---

## 5. Minimal Flutter integration pattern

1. **When opening order details** (you have `bookingId` and a valid user token):  
   - Subscribe to `private-cleaning-booking.{bookingId}`.  
   - Bind listeners for all event names in §3.

2. **On any matching event:**  
   - **Simplest:** `GET /api/v1/user/cleaning/orders/{bookingId}` and replace local state.  
   - **Faster:** merge `CleaningBookingTrackingUpdated.tracking` when that event fires; still refetch on `ArrivalVerified`, `CompletionDecisionMade`, and `ServiceExtensionRequested` unless you map every field and edge case.

3. **After successful POST** to start-verification or completion endpoints:  
   - Prefer the response `data` as source of truth; optional GET for parity.

4. **Multi-device:**  
   - Listen for `CompletionDecisionMade` and `ArrivalVerified` so a second device updates without polling.

5. **Dispose:**  
   - Unsubscribe when leaving the order screen to avoid leaks and stale handlers.

### 5.1 Pseudocode (Dart-style)

```dart
// After Sanctum login; baseUrl and token from your app config.
final channel = pusher.subscribe('private-cleaning-booking.$bookingId');

void onEvent(String name, Map<String, dynamic> data) {
  switch (name) {
    case 'ArrivalVerified':
    case 'CompletionDecisionMade':
    case 'ServiceExtensionRequested':
    case 'CleaningBookingTrackingUpdated':
    case 'cleaning_order.awaiting_start_verification':
    case 'cleaning_order.awaiting_customer_completion':
      refetchOrder(bookingId);
      break;
    case 'WorkerLocationUpdated':
      updateMapFrom(data);
      break;
    default:
      break;
  }
}
```

Adjust to your package (`pusher_channels_flutter`, custom auth delegate, etc.). Ensure **event name strings match exactly** (including dots in `cleaning_order.*`).

---

## 6. HTTP reminders (customer)

| Action | Method | Path |
| ------ | ------ | ---- |
| Confirm 4-digit code | POST | `/api/v1/user/cleaning/orders/{order}/start-verification/confirm` |
| Approve completion | POST | `/api/v1/user/cleaning/orders/{order}/completion/confirm` |
| Reject completion | POST | `/api/v1/user/cleaning/orders/{order}/completion/reject` |
| Request more time | POST | `/api/v1/user/cleaning/orders/{order}/completion/extend-time` |

Full bodies, errors, and idempotency: see [API_CONTRACT_USER_CLEANING_REALTIME_GATES.md](API_CONTRACT_USER_CLEANING_REALTIME_GATES.md).

---

## 7. Quick QA checklist (realtime)

- [ ] Subscribe only after you have a real `bookingId` and token; 403 on auth means channel name or token mismatch.  
- [ ] Confirm code on device A → device B on same order receives `ArrivalVerified` or at least `CleaningBookingTrackingUpdated` and UI updates.  
- [ ] Completion confirm / reject / extend on device A → device B receives `CompletionDecisionMade` with the correct `decision`.  
- [ ] When a time warning is created for the booking, customer channel receives `ServiceExtensionRequested` and refetch shows consistent state.  
- [ ] Leaving the screen unsubscribes; no duplicate handlers after navigation push/pop.

---

_End of document._
