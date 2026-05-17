# Flutter Realtime Contract: Cleaning Module + User Module (Cleaning Section)

Audience: Flutter developers (customer app and cleaning worker app)  
Backend: Laravel API + Sanctum + private broadcast channels

## 1) Scope and Base Paths

- Cleaning worker/admin module routes: `/api/v1/...` from `Modules/Cleaning/routes/api.php`
- User app cleaning routes: `/api/v1/user/cleaning/...` from `Modules/User/routes/api.php`
- Broadcast auth endpoint: `POST /broadcasting/auth` (Sanctum protected)

## 2) Auth and Channel Authorization

- All endpoints below require `Authorization: Bearer {sanctum_token}`.
- Private channels are authorized in `app/Providers/AppServiceProvider.php`:
- `cleaning-booking.{bookingId}`: allowed for booking customer OR assigned worker.
- `cleaning-worker.{workerId}`: allowed only for same authenticated worker.
- `cleaning-customer.{customerId}`: allowed only for same authenticated customer.

Flutter channel names (Pusher/Echo):
- `private-cleaning-booking.{bookingId}`
- `private-cleaning-worker.{workerId}` (worker app only)
- `private-cleaning-customer.{customerId}` (currently used by one event)

## 3) Realtime Events (Source of Truth)

All event names and payload keys below are exact backend wire shapes.

1. `WorkerLocationUpdated`
- Channels: `private-cleaning-booking.{bookingId}`
- Payload:
```json
{
  "latitude": 33.5138,
  "longitude": 36.2765,
  "workerId": 21,
  "updatedAt": "2026-05-17T10:00:00+00:00"
}
```

2. `WorkerArrived`
- Channels: `private-cleaning-booking.{bookingId}`
- Payload:
```json
{
  "cleaningBookingId": 1001,
  "arrivedAt": "2026-05-17T10:05:00+00:00"
}
```

3. `cleaning_order.awaiting_start_verification`
- Channels:
- `private-cleaning-booking.{bookingId}`
- `private-cleaning-customer.{customerId}`
- Payload:
```json
{
  "cleaningBookingId": 1001,
  "customerId": 88,
  "workerId": 21,
  "status": "awaiting_start_verification",
  "expiresAt": "2026-05-17T10:15:00+00:00"
}
```

4. `ArrivalVerified`
- Channels:
- `private-cleaning-booking.{bookingId}`
- `private-cleaning-worker.{workerId}` (if worker exists)
- Payload:
```json
{
  "cleaningBookingId": 1001,
  "workerId": 21,
  "arrivedAt": "2026-05-17T10:05:00+00:00",
  "version": 1
}
```

5. `cleaning_order.awaiting_customer_completion`
- Channels: `private-cleaning-booking.{bookingId}`
- Payload:
```json
{
  "cleaningBookingId": 1001,
  "workerId": 21,
  "status": "awaiting_customer_completion",
  "expiresAt": "2026-05-17T11:30:00+00:00"
}
```

6. `CompletionDecisionMade`
- Channels:
- `private-cleaning-booking.{bookingId}`
- `private-cleaning-worker.{workerId}` (if worker exists)
- Payload:
```json
{
  "cleaningBookingId": 1001,
  "workerId": 21,
  "decision": "approved",
  "message": null,
  "decidedAt": "2026-05-17T11:10:00+00:00",
  "version": 1
}
```
- `decision` enum: `approved | rejected | extension_requested`

7. `ServiceExtensionRequested`
- Channels:
- `private-cleaning-booking.{bookingId}`
- `private-cleaning-worker.{workerId}` (if worker exists)
- Payload:
```json
{
  "warningId": 990,
  "cleaningBookingId": 1001,
  "workerId": 21,
  "requestedMinutes": 30,
  "version": 1
}
```

8. `CleaningBookingTrackingUpdated`
- Channels: `private-cleaning-booking.{bookingId}`
- Payload:
```json
{
  "tracking": {
    "cleaningBookingId": 1001,
    "status": "in_progress",
    "workerId": 21,
    "startedTravelAt": "2026-05-17T09:40:00+00:00",
    "arrivedAt": "2026-05-17T10:05:00+00:00",
    "workStartedAt": "2026-05-17T10:08:00+00:00",
    "workFinishedAt": null,
    "customerConfirmedAt": "2026-05-17T10:08:00+00:00",
    "cancelledAt": null,
    "updatedAt": "2026-05-17T10:08:00+00:00"
  }
}
```

## 4) Cleaning Booking Status Enum

From `Modules/Cleaning/app/Enums/CleaningBookingStatus.php`:
- `pending`
- `worker_assigned`
- `awaiting_start_verification`
- `in_progress`
- `awaiting_customer_completion`
- `time_extension_requested`
- `completed`
- `cancelled`

## 5) Critical HTTP Endpoints for Realtime Lifecycle

## 5.1 Worker-side (Cleaning module)

- `POST /api/v1/cleaning-bookings/{id}/accept`
- `POST /api/v1/cleaning-bookings/{id}/reject`
- `GET /api/v1/cleaning-bookings/{id}/security-code`
- `POST /api/v1/cleaning-bookings/{id}/start-travel`
- `POST /api/v1/cleaning-bookings/{id}/location`
  - body:
```json
{ "latitude": 33.5138, "longitude": 36.2765 }
```
- `POST /api/v1/cleaning-bookings/{id}/arrive`
- `POST /api/v1/cleaning-bookings/{id}/start-work`
- `POST /api/v1/cleaning-bookings/{id}/complete`
- `POST /api/v1/cleaning-bookings/{id}/cancel`

Important guards:
- Worker must be linked to authenticated user.
- For most worker actions, booking must already belong to that worker.
- `location` requires status `worker_assigned` and `started_travel_at` already set.

## 5.2 Customer-side (User module cleaning section)

- `GET /api/v1/user/cleaning/orders`
- `POST /api/v1/user/cleaning/orders`
- `GET /api/v1/user/cleaning/orders/{order}`
- `PATCH /api/v1/user/cleaning/orders/{order}`
- `POST /api/v1/user/cleaning/orders/{order}/cancel`
- `POST /api/v1/user/cleaning/orders/{order}/start-verification/confirm`
  - middleware throttle: `cleaning-start-verification` = 5/min per user+order
  - body:
```json
{ "code": "1234" }
```
- `POST /api/v1/user/cleaning/orders/{order}/completion/confirm`
- `POST /api/v1/user/cleaning/orders/{order}/completion/reject`
  - body (optional):
```json
{ "reason": "Not fully cleaned yet" }
```
- `POST /api/v1/user/cleaning/orders/{order}/completion/extend-time`
  - body (optional):
```json
{ "additionalMinutes": 30 }
```

Ownership rule:
- All `/api/v1/user/cleaning/orders/{order}...` actions are scoped to the authenticated customer’s own orders.

## 6) Booking Resource Shape (Used by Both Sides)

Main response keys (from `CleaningBookingResource`):
- `id`, `customerId`, `workerId`, `bookingNumber`, `status`
- `propertyType`, `propertyDetails`
- `addressLatitude`, `addressLongitude`, `locationName`
- `estimatedSqm`, `estimatedHours`, `totalHours`
- `basePrice`, `addonsTotal`, `travelFee`, `cancellationFee`, `totalPrice`
- `scheduledDate`, `scheduledTime`
- `startedTravelAt`, `arrivedAt`, `workStartedAt`, `workFinishedAt`, `customerConfirmedAt`, `cancelledAt`
- `customer`, `worker`, `services`, `addons`, `billingPolicy`, `timeWarnings`, `disputes`

## 7) Flutter Integration Plan (What to Implement)

1. Build a shared CleaningRealtimeService:
- Handles private channel auth against `/broadcasting/auth`.
- Subscribes/unsubscribes per booking screen lifecycle.

2. Subscriptions:
- Customer app: subscribe to `private-cleaning-booking.{bookingId}`.
- Worker app: subscribe to both:
- `private-cleaning-booking.{bookingId}`
- `private-cleaning-worker.{workerId}` for worker-wide updates.

3. Event handling policy:
- For `WorkerLocationUpdated`: patch map marker directly.
- For all other cleaning events: trigger `GET` order details refresh and replace local state.

4. Action-driven sync:
- After every successful POST/PATCH action, apply returned booking payload immediately.
- Keep realtime listener active to sync second device sessions.

5. Error handling:
- `422`: validation/business status mismatch.
- `429`: too many start verification attempts.
- `403`: channel authorization failure or worker ownership restrictions.

## 8) Recommended QA Scenarios

1. Worker starts travel then sends location every few seconds, customer sees live map updates.
2. Worker arrives, customer receives `cleaning_order.awaiting_start_verification`, enters code, both apps move to `in_progress`.
3. Worker completes, customer receives `cleaning_order.awaiting_customer_completion`, then:
- confirm -> `completed`
- reject -> `in_progress`
- extend-time -> `time_extension_requested`
4. Same booking open on two devices; all state transitions propagate via realtime without manual refresh.

## 9) File References (Backend Source)

- `app/Providers/AppServiceProvider.php`
- `Modules/Cleaning/app/Events/*.php`
- `Modules/Cleaning/app/Services/CleaningBookingService.php`
- `Modules/Cleaning/app/Http/Controllers/API/CleaningBookingController.php`
- `Modules/User/app/Services/UserCleaningOrderService.php`
- `Modules/User/routes/api.php`
- `Modules/Cleaning/routes/api.php`
