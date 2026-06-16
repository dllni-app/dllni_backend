# API Contract: Cleaning SOS Feature for Flutter

## Scope
This document covers only the SOS feature for cleaning orders.

Audience:
- Flutter `user-app`: customer can trigger SOS from a cleaning order.
- Flutter cleaning worker app: cleaning worker can trigger SOS from an assigned cleaning booking.
- Flutter `cleaning_owner_app`: operations/owner user can monitor, acknowledge, and resolve cleaning SOS alerts.

Base URL:
- `{{BASE_URL}}/api/v1`

Auth:
- `Authorization: Bearer <sanctum_token>`
- `Content-Type: application/json`

Important backend note:
- The shared SOS model and owner list/show endpoints already exist through `sos_alerts`.
- Cleaning-specific create, acknowledge, and resolve endpoints below are the required contract for this feature. The current legacy `POST /api/v1/user/sos` endpoint is restaurant-order bound and must not be used for cleaning orders.

---

## Feature Rules
- A cleaning SOS is linked to `Modules\Cleaning\app\Models\CleaningBooking` through `bookingId` and `bookingType`.
- Customer SOS and worker SOS use the same response shape so `cleaning_owner_app` can render one unified emergency queue.
- Creating an SOS should create both:
  - a `sos_alerts` row
  - a critical `system_alerts` row with `alertType = sos_triggered`
- New cleaning SOS alerts should start with `status = triggered`.
- A booking can have multiple historical SOS alerts, but the backend should prevent duplicate active alerts from the same reporter on the same booking.
- Active statuses are `triggered` and `acknowledged`.
- Terminal statuses are `resolved`, `completed`, and `cancelled` bookings should not accept new SOS alerts.
- Location is optional but strongly recommended. If permission is denied, Flutter should submit the SOS without latitude/longitude.
- Audio recording is not part of this API contract. If added later, it should be a separate upload contract.

---

## Status and Type Enums

SOS status:
- `triggered`: created and waiting for owner/operations attention.
- `acknowledged`: owner/operations has seen it and is handling it.
- `resolved`: owner/operations closed the incident.

Emergency type:
- `safety_threat`
- `medical_emergency`
- `severe_conflict`

Reporter role:
- `customer`
- `worker`

Source:
- `booking`

For cleaning SOS, always use `source = booking`. Use `reporterRole` to distinguish customer vs worker.

---

## Shared SOS Alert Resource

All cleaning SOS endpoints should return this shape.

```json
{
  "data": {
    "id": 15,
    "bookingId": 987,
    "bookingType": "Modules\\Cleaning\\app\\Models\\CleaningBooking",
    "emergencyType": "safety_threat",
    "message": "Worker is behaving aggressively and I need urgent help.",
    "source": "booking",
    "status": "triggered",
    "latitude": 33.5138,
    "longitude": 36.2765,
    "triggeredAt": "2026-06-14 00:50:00",
    "acknowledgedAt": null,
    "resolvedAt": null,
    "reporter": {
      "role": "customer",
      "userId": 42,
      "name": "Customer Name",
      "phone": "+963900000000"
    },
    "booking": {
      "id": 987,
      "status": "in_progress",
      "scheduledDate": "2026-06-14",
      "scheduledTime": "10:00",
      "propertyType": "apartment",
      "address": "Damascus, Mazzeh",
      "customer": {
        "id": 42,
        "name": "Customer Name",
        "phone": "+963900000000"
      },
      "workers": [
        {
          "id": 77,
          "name": "Worker Name",
          "phone": "+963911111111"
        }
      ]
    },
    "createdAt": "2026-06-14 00:50:00",
    "updatedAt": "2026-06-14 00:50:00"
  }
}
```

Required backend additions to the current resource:
- `reporter`
- `booking.customer`
- `booking.workers`
- `acknowledgedAt`

If `acknowledgedAt` is not stored as a dedicated column, return `null` until the backend adds it.

---

## User App Flow: Customer SOS on Cleaning Order

### When to show the SOS button
Show the SOS button on the cleaning order details/tracking screen when:
- the user is authenticated
- the order is a cleaning booking
- the booking belongs to the authenticated customer
- the booking status is not `completed` or `cancelled`

Recommended UI:
1. User taps `SOS`.
2. App opens a confirmation bottom sheet explaining that this is for urgent safety/help situations.
3. User selects emergency type.
4. User may enter a short message.
5. App requests current location.
6. App submits the SOS immediately, even if location permission is denied.
7. App shows an emergency submitted state and waits for owner acknowledgement via realtime/polling.

### Endpoint
`POST /api/v1/user/cleaning/orders/{order}/sos`

`{order}` is the cleaning booking numeric id, same as `data.id` from user cleaning order APIs.

### Request body

```json
{
  "emergencyType": "safety_threat",
  "message": "The worker did not arrive and I cannot contact support.",
  "latitude": 33.5138,
  "longitude": 36.2765,
  "clientRequestId": "2c7d9b1d-64a4-4be2-91fb-a5f3df780c22"
}
```

Validation:

| Field | Type | Required | Rules |
| --- | --- | --- | --- |
| `emergencyType` | string | Yes | One of `safety_threat`, `medical_emergency`, `severe_conflict` |
| `message` | string | No | Trimmed, max `1000` chars |
| `latitude` | number | No | `-90..90`; required only if longitude is sent |
| `longitude` | number | No | `-180..180`; required only if latitude is sent |
| `clientRequestId` | string | No | UUID recommended for retry/idempotency |

Backend behavior:
- Scope `{order}` to the authenticated customer. If not owned, return `404`.
- Reject terminal bookings (`completed`, `cancelled`) with `422`.
- Store `booking_id = {order}` and `booking_type = Modules\Cleaning\app\Models\CleaningBooking`.
- Store `source = booking`, `status = triggered`, and reporter role `customer`.
- If an active SOS already exists for the same booking and customer reporter, return the existing alert with `200` instead of creating a duplicate.
- Broadcast/push `cleaning_order.sos_triggered`.

Success response:
- `201 Created` when a new SOS is created.
- `200 OK` when an existing active SOS is returned for idempotency.
- Body is the shared SOS alert resource.

Errors:
- `401`: missing or invalid token.
- `404`: cleaning booking does not exist or is not owned by the authenticated customer.
- `422`: invalid body or booking is already terminal.
- `429`: too many SOS attempts.

Flutter implementation notes:
- Disable the SOS submit button while the request is in flight.
- Keep `clientRequestId` stable across retries for the same tap.
- Do not block submission on GPS. Send location only when available.
- After success, store the returned `id` as the active SOS id for that order.
- Show `triggered` as "Sent to support", `acknowledged` as "Support is handling this", and `resolved` as "Closed".

---

## Cleaning Worker Flow: Worker SOS on Assigned Booking

### When to show the SOS button
Show the SOS button on the worker active booking screen when:
- the authenticated user has a worker profile
- the worker is assigned to the booking, or has an accepted worker assignment
- the booking is active and not `completed` or `cancelled`

Valid active statuses:
- `worker_assigned`
- `awaiting_start_verification`
- `awaiting_worker_start_confirmation`
- `in_progress`
- `awaiting_customer_completion`
- `time_extension_requested`

### Endpoint
`POST /api/v1/cleaning-bookings/{cleaning_booking}/sos`

### Request body

```json
{
  "emergencyType": "medical_emergency",
  "message": "I need immediate medical assistance at the job location.",
  "latitude": 33.5138,
  "longitude": 36.2765,
  "clientRequestId": "7fef9abd-9c63-4062-8789-96c4d910f1aa"
}
```

Validation is the same as customer SOS.

Backend behavior:
- Reuse the same worker authorization rules used by worker booking actions.
- Reject if the authenticated user does not have a worker profile.
- Reject if the worker is not assigned to the booking and has no accepted assignment.
- Store `source = booking`, `status = triggered`, and reporter role `worker`.
- If an active SOS already exists for the same booking and worker reporter, return the existing alert with `200`.
- Broadcast/push `cleaning_order.sos_triggered`.

Success and error responses are the same as customer SOS.

Flutter implementation notes:
- Put SOS on the active task screen, not only in settings/profile.
- Use the worker's latest known location from tracking if fresh; otherwise request current GPS.
- If GPS fails, submit without location and show a non-blocking warning.
- After success, show a persistent alert banner until the SOS is acknowledged or resolved.

---

## Cleaning Owner App Flow: Monitor and Handle SOS

### Screen behavior
The owner app should have a dedicated SOS queue screen and a visible dashboard badge.

Recommended tabs:
- `Active`: `triggered`, `acknowledged`
- `Resolved`: `resolved`

Recommended active item display:
- emergency type
- reporter role (`customer` or `worker`)
- booking id
- customer name and phone
- worker names and phones
- address
- latest latitude/longitude map action
- status
- triggered time

### List SOS alerts
`GET /api/v1/sos-alerts`

Current endpoint exists.

Required query support for cleaning owner app:

| Query | Required | Example |
| --- | --- | --- |
| `perPage` | No | `20` |
| `filter[status]` | No | `triggered` |
| `filter[emergencyType]` | No | `safety_threat` |
| `filter[source]` | No | `booking` |
| `filter[bookingType]` | Yes for cleaning app | `cleaning` |
| `filter[reporterRole]` | No | `customer` |
| `sort` | No | `-triggeredAt` |

Example:

`GET /api/v1/sos-alerts?filter[bookingType]=cleaning&filter[status]=triggered&sort=-triggeredAt&perPage=20`

Backend implementation required:
- Add `filter[bookingType] = cleaning` and map it to `Modules\Cleaning\app\Models\CleaningBooking`.
- Add `filter[reporterRole]`.
- Keep existing pagination structure.

Response:

```json
{
  "data": [
    {
      "id": 15,
      "bookingId": 987,
      "bookingType": "Modules\\Cleaning\\app\\Models\\CleaningBooking",
      "emergencyType": "safety_threat",
      "message": "Worker is behaving aggressively and I need urgent help.",
      "source": "booking",
      "status": "triggered",
      "latitude": 33.5138,
      "longitude": 36.2765,
      "triggeredAt": "2026-06-14 00:50:00",
      "resolvedAt": null,
      "reporter": {
        "role": "customer",
        "userId": 42,
        "name": "Customer Name",
        "phone": "+963900000000"
      },
      "booking": {
        "id": 987,
        "status": "in_progress",
        "scheduledDate": "2026-06-14",
        "scheduledTime": "10:00",
        "address": "Damascus, Mazzeh"
      },
      "createdAt": "2026-06-14 00:50:00",
      "updatedAt": "2026-06-14 00:50:00"
    }
  ],
  "links": {
    "first": "https://example.com/api/v1/sos-alerts?page=1",
    "last": "https://example.com/api/v1/sos-alerts?page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "path": "https://example.com/api/v1/sos-alerts",
    "per_page": 20,
    "to": 1,
    "total": 1
  }
}
```

### Show SOS details
`GET /api/v1/sos-alerts/{id}`

Current endpoint exists.

Backend implementation required:
- Scope owner/operations users as needed.
- Load full cleaning booking details, customer, assigned workers, and reporter.

Success response:
- `200 OK`
- Body is the shared SOS alert resource.

Errors:
- `401`: missing or invalid token.
- `403`: authenticated user cannot access SOS management.
- `404`: SOS does not exist.

### Acknowledge SOS
`POST /api/v1/sos-alerts/{id}/acknowledge`

Request body:

```json
{
  "note": "Calling customer now."
}
```

Validation:

| Field | Type | Required | Rules |
| --- | --- | --- | --- |
| `note` | string | No | Max `1000` chars |

Backend behavior:
- Allowed only for `status = triggered`.
- Set `status = acknowledged`.
- Store `acknowledgedBy`, `acknowledgedAt`, and optional note if storage exists.
- Broadcast/push `cleaning_order.sos_acknowledged`.

Success response:
- `200 OK`
- Body is the shared SOS alert resource.

Errors:
- `401`, `403`, `404`
- `422` if status is not `triggered`.

### Resolve SOS
`POST /api/v1/sos-alerts/{id}/resolve`

Request body:

```json
{
  "resolutionNote": "Customer confirmed the issue is resolved.",
  "resolutionType": "handled_by_support"
}
```

Validation:

| Field | Type | Required | Rules |
| --- | --- | --- | --- |
| `resolutionNote` | string | Yes | Trimmed, max `2000` chars |
| `resolutionType` | string | Yes | `handled_by_support`, `false_alarm`, `escalated_to_authorities`, `cancelled_by_reporter` |

Backend behavior:
- Allowed for `status = triggered` or `acknowledged`.
- Set `status = resolved`.
- Set `resolvedAt = now()`.
- Store `resolvedBy`, `resolutionType`, and `resolutionNote`.
- Mark related critical `system_alerts` as resolved if applicable.
- Broadcast/push `cleaning_order.sos_resolved`.

Success response:
- `200 OK`
- Body is the shared SOS alert resource.

Errors:
- `401`, `403`, `404`
- `422` if already resolved or request body is invalid.

Flutter implementation notes for owner app:
- Poll active SOS every 15 to 30 seconds if realtime is unavailable.
- Use pagination for history, but keep active alerts loaded from newest first.
- Show a high-priority local notification or in-app modal for new `triggered` events.
- Require confirmation before resolving an SOS.
- Keep call buttons for customer and worker visible on the details screen.

---

## Realtime and Push Contract

Recommended private channels:
- Booking channel: `private-cleaning-booking.{bookingId}`
- Owner operations channel: `private-cleaning.sos`

Events:
- `cleaning_order.sos_triggered`
- `cleaning_order.sos_acknowledged`
- `cleaning_order.sos_resolved`

Event payload:

```json
{
  "event": "cleaning_order.sos_triggered",
  "data": {
    "id": 15,
    "bookingId": 987,
    "status": "triggered",
    "emergencyType": "safety_threat",
    "reporter": {
      "role": "customer",
      "userId": 42,
      "name": "Customer Name"
    },
    "triggeredAt": "2026-06-14 00:50:00"
  }
}
```

Flutter behavior:
- `user-app` and worker app listen on the booking channel while viewing the active order.
- `cleaning_owner_app` listens on `private-cleaning.sos`.
- On event receipt, update the local SOS item by `id`.
- If an event references an unknown `id`, call `GET /api/v1/sos-alerts/{id}`.

---

## Backend Implementation Checklist

Create cleaning SOS endpoints:
- `POST /api/v1/user/cleaning/orders/{order}/sos`
- `POST /api/v1/cleaning-bookings/{cleaning_booking}/sos`

Extend owner SOS endpoints:
- keep `GET /api/v1/sos-alerts`
- keep `GET /api/v1/sos-alerts/{id}`
- add `POST /api/v1/sos-alerts/{id}/acknowledge`
- add `POST /api/v1/sos-alerts/{id}/resolve`

Data/resource requirements:
- Link cleaning alerts with `booking_id` and `booking_type = Modules\Cleaning\app\Models\CleaningBooking`.
- Add or expose reporter metadata: role, user id, name, phone.
- Add filters for `bookingType` and `reporterRole`.
- Add owner action metadata if needed: `acknowledgedBy`, `acknowledgedAt`, `resolvedBy`, `resolutionType`, `resolutionNote`.
- Return camelCase JSON for Flutter-facing resources.
- Add tests for customer create, worker create, owner list, owner acknowledge, owner resolve, duplicate active SOS, authorization, and terminal booking rejection.

---

## Existing Backend Source References

Current shared SOS files:
- `app/Models/SosAlert.php`
- `app/Http/Controllers/API/SosAlertController.php`
- `app/Http/Resources/SosAlertResource.php`
- `app/Http/Requests/SosAlertRequests/SosAlertFilterRequest.php`
- `app/Traits/FilterQueries/SosAlertFilterQuery.php`
- `routes/api.php`

Current cleaning booking files to extend:
- `Modules/Cleaning/routes/api.php`
- `Modules/Cleaning/app/Http/Controllers/API/CleaningBookingController.php`
- `Modules/Cleaning/app/Models/CleaningBooking.php`
- `Modules/Cleaning/app/Http/Resources/CleaningBookingResource.php`
