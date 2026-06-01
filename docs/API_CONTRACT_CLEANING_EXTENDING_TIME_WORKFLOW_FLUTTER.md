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
- `additionalMinutes`: required, integer, min 1, max 480.

Behavior (`UserCleaningOrderService::requestCompletionExtension`):
- Allowed only when booking status is `awaiting_customer_completion`.
- Computes quote using dashboard setting `extension_rate_per_30_minutes`:
  - `quotedAmount = round((extensionRatePer30Minutes / 30) * additionalMinutes, 2)`
- Creates a `cleaning_time_warnings` row with:
  - `customer_response=extend_time`, `customer_responded_at`, `sent_at`
  - `additional_minutes` (requested minutes)
  - `quoted_amount`, `quoted_currency` (default `SYP`), `price_applied_at=null`
- Updates booking status to `time_extension_requested`.
- Broadcasts `CleaningBookingTrackingUpdated` + `CompletionDecisionMade(decision=extension_requested)`.

Success response (shape):
```json
{
  "data": {
    "id": 123,
    "status": "time_extension_requested",
    "bookingNumber": "CLN-USER-AB12CD34",
    "extensionFeeTotal": 0
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
  "requestedMinutes": 30,
  "additionalAmount": 4500,
  "currency": "SYP",
  "priceAppliedAt": null,
  "workerRejectMessage": null,
  "booking": {
    "id": 123,
    "status": "time_extension_requested",
    "extensionFeeTotal": 0
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
- Applies quote to booking totals exactly once (`price_applied_at` guard):
  - increments `cleaning_bookings.extension_fee_total` by `quoted_amount`
  - increments `cleaning_bookings.total_price` by `quoted_amount`
- Sets `price_applied_at` when price is applied.
- Optional `additionalMinutes` remains backward-compatible but no longer drives pricing.

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
  "additionalAmount": 4500,
  "currency": "SYP",
  "version": 1
}
```

---

## Flutter Integration Mapping

### dllni-user-app
- Uses `POST /api/v1/user/cleaning/orders/{id}/completion/extend-time` in:
- `lib/features/orders/data/source/orders_remote_data_source.dart`
- Must send `additionalMinutes` (required).
- Parse and map:
  - `requestedMinutes`, `additionalAmount`, `currency` from warning/event payloads
  - `extensionFeeTotal` from booking payload
- UX should show extension charge as pending until worker accepts.
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
- Show request minutes and quoted price from:
  - `requestedMinutes`, `additionalAmount`, `currency`
- Should subscribe to:
- `private-cleaning-worker.{workerId}` for global worker-level extension notifications.
- `private-cleaning-booking.{bookingId}` while order details is open.

---

## Important Implementation Notes
1. Customer `additionalMinutes` is required and stored on the warning row (`additional_minutes`).
2. Price quote is calculated at request time and stored in warning (`quoted_amount`, `quoted_currency`).
3. Booking totals are updated only after worker accepts, and guarded from double apply via `price_applied_at`.
4. Treat backend booking `status` as source of truth for UI state transitions.
5. For race safety, always refetch booking details after critical completion/extension events.

---

## Suggested QA Scenarios
1. Customer requests extension with required `additionalMinutes` while status is `awaiting_customer_completion` -> expect `200`, warning stores requested minutes + quote fields and status becomes `time_extension_requested`.
2. Customer requests extension without `additionalMinutes` or in invalid status -> expect `422`.
3. Worker receives `ServiceExtensionRequested` with `requestedMinutes`, `additionalAmount`, `currency`, then `GET /cleaning-time-warnings` includes matching fields.
4. Worker accepts once -> booking `extensionFeeTotal` and `totalPrice` increase by quote, `priceAppliedAt` is set.
5. Worker retries accept/reject on same warning -> expect `422` and no double-charge.
6. Unauthorized worker (different booking owner) gets `403` on warning accept/reject.
