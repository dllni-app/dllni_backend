# API Contract: Cleaning Time Extension Workflow (Flutter)

## Scope
This contract covers **time-extension flow during cleaning completion gate** across:
- `dllni-user-app` (customer)
- `dllni_cleaning_owner_app` (worker/owner)
- `Dllni_backend` (Laravel API + realtime)

Primary goal: ensure Flutter teams implement the same lifecycle and payload expectations for extension requests.

## Actors
- Customer (user app): can confirm completion, reject completion, or request extension.
- Worker (cleaning owner app): receives extension request and accepts/rejects it.
- Backend: enforces status transitions and emits realtime events.

## Lifecycle Overview
1. Worker marks booking complete (`POST /api/v1/cleaning-bookings/{id}/complete`).
2. Booking moves to `awaiting_customer_completion`.
3. Customer chooses one action:
- Confirm completion -> booking becomes `completed`.
- Reject completion -> booking reopens to `in_progress`.
- Extend time -> booking moves to `time_extension_requested`.
4. If customer chose extend time, worker reads extension warnings list and decides:
- Accept extension request.
- Reject extension request (optional message).

## Status Enum (Backend Source of Truth)
From `Modules/Cleaning/app/Enums/CleaningBookingStatus.php`:
- `pending`
- `worker_assigned`
- `awaiting_start_verification`
- `in_progress`
- `awaiting_customer_completion`
- `time_extension_requested`
- `completed`
- `cancelled`

---

## Customer APIs (dllni-user-app)
Base: `/api/v1/user`

### 1) Request extension time
`POST /cleaning/orders/{orderId}/completion/extend-time`

Headers:
- `Authorization: Bearer <sanctum_token>`
- `Accept: application/json`

Request body:
```json
{
  "additionalMinutes": 30
}
```

Validation (`UserCleaningOrderCompletionExtendTimeRequest`):
- `additionalMinutes`: nullable, integer, min 1, max 480.

Behavior (`UserCleaningOrderService::requestCompletionExtension`):
- Allowed only when booking status is `awaiting_customer_completion`.
- Updates booking status to `time_extension_requested`.
- Broadcasts `CleaningBookingTrackingUpdated` + `CompletionDecisionMade(decision=extension_requested)`.

Success response (shape):
```json
{
  "data": {
    "id": 123,
    "status": "time_extension_requested",
    "bookingNumber": "CLN-USER-AB12CD34",
    "timeWarnings": []
  },
  "message": "Extension request sent successfully."
}
```

Possible errors:
- `404` if order not found for authenticated customer.
- `422` when status is not `awaiting_customer_completion`.

### 2) Confirm completion
`POST /cleaning/orders/{orderId}/completion/confirm`

Request body: `{}`

Behavior:
- `awaiting_customer_completion` -> `completed`
- emits `CompletionDecisionMade(decision=approved)`

### 3) Reject completion
`POST /cleaning/orders/{orderId}/completion/reject`

Request body:
```json
{
  "reason": "Need more work in kitchen"
}
```

Behavior:
- `awaiting_customer_completion` -> `in_progress`
- `work_finished_at` reset to `null`
- emits `CompletionDecisionMade(decision=rejected)`

---

## Worker APIs (dllni_cleaning_owner_app)
Base: `/api/v1`

### 1) List extension requests
`GET /cleaning-time-warnings`

Headers:
- `Authorization: Bearer <sanctum_token>`

Response item shape (`CleaningTimeWarningResource`):
```json
{
  "id": 77,
  "bookingId": 123,
  "bookingType": "cleaning_booking",
  "customerResponse": "extend_time",
  "workerResponse": null,
  "sentAt": "2026-05-18 10:15:00",
  "customerRespondedAt": "2026-05-18 10:15:00",
  "workerRespondedAt": null,
  "additionalMinutes": 30,
  "workerRejectMessage": null,
  "booking": {
    "id": 123,
    "status": "time_extension_requested"
  },
  "createdAt": "2026-05-18 10:15:00",
  "updatedAt": "2026-05-18 10:15:00"
}
```

### 2) Accept extension request
`POST /cleaning-time-warnings/{warningId}/accept`

Request body:
```json
{
  "additionalMinutes": 30
}
```

Validation:
- `additionalMinutes`: nullable integer 1..480.

Behavior:
- Sets warning `worker_response = extend_time`.
- Sets `worker_responded_at`.
- Stores `additional_minutes` if provided.

### 3) Reject extension request
`POST /cleaning-time-warnings/{warningId}/reject`

Request body:
```json
{
  "message": "Cannot extend due to next booking"
}
```

Validation:
- `message`: nullable string max 500.

Behavior:
- Sets warning `worker_response = commit_current_time`.
- Sets `worker_responded_at` and `worker_reject_message`.

Common authorization guard:
- User must have linked `worker` account.
- Warning must belong to a cleaning booking assigned to this worker.

Possible errors:
- `403` not owner worker / wrong booking type.
- `422` already responded.

---

## Realtime Contract

### Channels
- Booking channel: `private-cleaning-booking.{bookingId}`
- Worker channel: `private-cleaning-worker.{workerId}`

### Channel auth rules (AppServiceProvider)
- `cleaning-booking.{bookingId}`: allowed for booking customer OR assigned worker.
- `cleaning-worker.{workerId}`: allowed only for worker owning that `workerId`.

### Events relevant to extension flow

1) `CleaningBookingTrackingUpdated`
- Channel: `private-cleaning-booking.{bookingId}`
- Payload:
```json
{
  "tracking": {
    "cleaningBookingId": 123,
    "status": "time_extension_requested",
    "workerId": 52,
    "startedTravelAt": "...",
    "arrivedAt": "...",
    "workStartedAt": "...",
    "workFinishedAt": "...",
    "customerConfirmedAt": "...",
    "cancelledAt": null,
    "updatedAt": "2026-05-18T10:15:00+00:00"
  }
}
```

2) `CompletionDecisionMade`
- Channels:
- `private-cleaning-booking.{bookingId}`
- `private-cleaning-worker.{workerId}` (if worker exists)
- Payload:
```json
{
  "cleaningBookingId": 123,
  "workerId": 52,
  "decision": "extension_requested",
  "message": null,
  "decidedAt": "2026-05-18T10:15:00+00:00",
  "version": 1
}
```

3) `ServiceExtensionRequested`
- Trigger: new time warning created.
- Channels:
- `private-cleaning-booking.{bookingId}`
- `private-cleaning-worker.{workerId}` (if worker exists)
- Payload:
```json
{
  "warningId": 77,
  "cleaningBookingId": 123,
  "workerId": 52,
  "requestedMinutes": 30,
  "version": 1
}
```

---

## Flutter Integration Mapping

### dllni-user-app
- Uses `POST /api/v1/user/cleaning/orders/{id}/completion/extend-time` in:
- `lib/features/orders/data/source/orders_remote_data_source.dart`
- Sends `additionalMinutes` when provided.
- Should listen on `private-cleaning-booking.{bookingId}` for:
- `CleaningBookingTrackingUpdated`
- `CompletionDecisionMade`
- `ServiceExtensionRequested` (optional for customer UI sync)

### dllni_cleaning_owner_app
- Uses:
- `GET /api/v1/cleaning-time-warnings`
- `POST /api/v1/cleaning-time-warnings/{id}/accept`
- `POST /api/v1/cleaning-time-warnings/{id}/reject`
- in `lib/features/orders/data/source/orders_remote_data_source.dart`
- Should subscribe to:
- `private-cleaning-worker.{workerId}` for global worker-level extension notifications.
- `private-cleaning-booking.{bookingId}` while order details is open.

---

## Important Implementation Notes
1. Backend currently validates `additionalMinutes` on customer extend endpoint, but `requestCompletionExtension()` changes booking status and does not directly persist that value on booking itself.
2. Worker extension decision is stored on `cleaning_time_warnings` (not directly on booking row).
3. Treat backend booking `status` as source of truth for UI state transitions.
4. For race safety, always refetch booking details after critical completion/extension events.

---

## Suggested QA Scenarios
1. Customer requests extension while status is `awaiting_customer_completion` -> expect `200`, status becomes `time_extension_requested`.
2. Customer requests extension in any other status -> expect `422`.
3. Worker receives `ServiceExtensionRequested` then `GET /cleaning-time-warnings` includes new warning.
4. Worker accepts once -> second accept/reject on same warning returns `422`.
5. Unauthorized worker (different booking owner) gets `403` on warning accept/reject.
