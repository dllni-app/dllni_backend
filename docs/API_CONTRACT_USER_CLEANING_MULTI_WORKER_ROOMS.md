# API Contract: User Cleaning Multi-Worker Rooms

## Scope
This contract covers the multi-worker cleaning flow for the Flutter user app and worker app.

Base path:
- `/api/v1/user/cleaning/orders`
- `/api/v1/cleaning-bookings`

Auth:
- `Authorization: Bearer <token>`
- `Content-Type: application/json`

The backend keeps the existing booking lifecycle. The only state change is that `pending` now also means "searching for workers" while the required team is not yet complete.

---

## Core Rules
- `assignmentMode` values:
  - `preferred_worker`
  - `open_count`
- If `assignmentMode` is omitted, the backend infers it from `preferredWorkerId` and `numberOfWorkers`.
- `preferred_worker` implies one worker only.
- `open_count` means the customer wants a team of `numberOfWorkers`.
- `workerId` stays as the legacy top-level primary worker field.
- `workerId` is only populated once the required team is fulfilled and the booking is finalized to `worker_assigned`.
- `workerAcceptance` and `workerAssignments` are the source of truth for team progress.
- `roomAssignments` are the source of truth for per-room ownership.
- Customer room assignment is allowed before `in_progress`.
- Worker room claim is allowed only after the worker has accepted and while the booking is still `pending`.
- Do not use the top-level booking `status` alone for worker screens. Use `worker_order_status` or `myAssignment.status` for the authenticated worker.

---

## Endpoints
### User booking endpoints
- `POST /api/v1/user/cleaning/orders/estimate-price`
- `POST /api/v1/user/cleaning/orders`
- `GET /api/v1/user/cleaning/orders/{order}`
- `PATCH /api/v1/user/cleaning/orders/{order}/room-assignments`

### Worker booking endpoints
- `POST /api/v1/cleaning-bookings/{id}/accept`
- `POST /api/v1/cleaning-bookings/{id}/rooms/claim`
- `POST /api/v1/cleaning-bookings/{id}/reject`

---

## Create / Update Payload
The booking create and update payloads now accept team fields in addition to the existing cleaning fields.

```json
{
  "propertyType": "apartment",
  "propertyDetails": {
    "address": "Damascus, Mazzeh",
    "location_name": "Home",
    "rooms": 4,
    "bedrooms": 1,
    "bathrooms": 1,
    "kitchens": 1,
    "living_room_size": "medium",
    "room_size_breakdown": {
      "bedroom": { "small": 1, "medium": 0, "large": 0 },
      "bathroom": { "small": 1, "medium": 0, "large": 0 },
      "kitchen": { "small": 0, "medium": 1, "large": 0 },
      "living_room": { "small": 0, "medium": 1, "large": 0 },
      "balcony": { "small": 0, "medium": 0, "large": 0 }
    }
  },
  "assignmentMode": "open_count",
  "numberOfWorkers": 2,
  "scheduledDate": "2026-06-05",
  "scheduledTime": "09:00",
  "addressLatitude": 33.5138,
  "addressLongitude": 36.2765,
  "genderPreference": "any",
  "termsAccepted": true
}
```

Preferred-worker example:

```json
{
  "propertyType": "apartment",
  "propertyDetails": {
    "address": "Damascus, Mazzeh",
    "location_name": "Home"
  },
  "assignmentMode": "preferred_worker",
  "preferredWorkerId": 44,
  "numberOfWorkers": 1,
  "scheduledDate": "2026-06-05",
  "scheduledTime": "09:00",
  "termsAccepted": true
}
```

Validation notes:
- `preferredWorkerId` cannot be combined with `numberOfWorkers > 1`.
- `numberOfWorkers` remains bounded by the existing request validation.
- `assignmentMode` is additive; legacy clients can omit it.

---

## Response Shape
`CleaningBookingResource` now includes these additive fields:

- `assignmentMode`
- `numberOfWorkers`
- `workerAcceptance`
- `preferredWorker`
- `worker`
- `workerAssignments`
- `roomAssignments`
- `myAssignment`
- `worker_assignment` alias for `myAssignment`
- `order_status`: the real global cleaning booking status
- `worker_order_status`: the authenticated worker's status for this booking
- `required_workers_count`
- `accepted_workers_count`
- `pending_workers_count`
- `start_approved_workers_count`
- `not_start_approved_workers_count`

Customer-side example after one of two workers accepted:

```json
{
  "id": 123,
  "status": "pending",
  "order_status": "pending",
  "worker_order_status": "pending",
  "workerId": null,
  "assignmentMode": "open_count",
  "numberOfWorkers": 2,
  "required_workers_count": 2,
  "accepted_workers_count": 1,
  "pending_workers_count": 1,
  "start_approved_workers_count": 0,
  "not_start_approved_workers_count": 2,
  "workerAcceptance": {
    "required": 2,
    "accepted": 1,
    "remaining": 1,
    "startApproved": 0,
    "notStartApproved": 2,
    "isFulfilled": false,
    "isStartApproved": false
  },
  "workerAssignments": [
    {
      "id": 9001,
      "workerId": 44,
      "status": "accepted_waiting_for_order_start",
      "acceptedAt": "2026-06-03T18:30:00+03:00",
      "startApprovedAt": null,
      "roomCount": 1,
      "roomsWeight": 1.5,
      "serviceShareAmount": 18000,
      "travelFee": 2500,
      "adminMarginAmount": 1500,
      "workerAmount": 20500,
      "currency": "SYP",
      "roomIds": [501],
      "worker": {
        "id": 44,
        "firstName": "Ahmad",
        "name": "Ahmad Ali",
        "phone": "+963...",
        "averageRating": 4.8,
        "totalCompletedJobs": 92,
        "isVerified": true,
        "avatarUrl": null
      }
    }
  ],
  "myAssignment": null,
  "worker_assignment": null
}
```

Worker-side example for the accepted worker while the global order is still `pending`:

```json
{
  "id": 123,
  "status": "pending",
  "order_status": "pending",
  "worker_order_status": "accepted_waiting_for_order_start",
  "workerId": null,
  "required_workers_count": 2,
  "accepted_workers_count": 1,
  "pending_workers_count": 1,
  "start_approved_workers_count": 0,
  "not_start_approved_workers_count": 2,
  "workerAcceptance": {
    "required": 2,
    "accepted": 1,
    "remaining": 1,
    "startApproved": 0,
    "notStartApproved": 2,
    "isFulfilled": false,
    "isStartApproved": false
  },
  "roomAssignments": [
    {
      "id": 501,
      "roomKey": "bedroom.small.1",
      "roomType": "bedroom",
      "roomSize": "small",
      "displayLabel": "Bedroom 1 - Small",
      "weight": 1.0,
      "assignedWorkerId": 44,
      "assignmentSource": "customer",
      "assignedWorker": {
        "id": 44,
        "firstName": "Ahmad",
        "name": "Ahmad Ali",
        "phone": "+963...",
        "averageRating": 4.8,
        "totalCompletedJobs": 92,
        "isVerified": true,
        "avatarUrl": null
      }
    }
  ],
  "myAssignment": {
    "id": 9001,
    "workerId": 44,
    "status": "accepted_waiting_for_order_start",
    "acceptedAt": "2026-06-03T18:30:00+03:00",
    "startApprovedAt": null,
    "roomCount": 1,
    "roomsWeight": 1.5,
    "serviceShareAmount": 18000,
    "travelFee": 2500,
    "adminMarginAmount": 1500,
    "workerAmount": 20500,
    "currency": "SYP",
    "roomIds": [501]
  },
  "worker_assignment": {
    "id": 9001,
    "workerId": 44,
    "status": "accepted_waiting_for_order_start",
    "acceptedAt": "2026-06-03T18:30:00+03:00",
    "startApprovedAt": null,
    "roomCount": 1,
    "roomsWeight": 1.5,
    "serviceShareAmount": 18000,
    "travelFee": 2500,
    "adminMarginAmount": 1500,
    "workerAmount": 20500,
    "currency": "SYP",
    "roomIds": [501]
  }
}
```

`myAssignment` is resolved from the authenticated worker. For legacy single-worker bookings, it can fall back to a synthetic assignment even when `workerAssignments` is empty. `worker_assignment` is an additive snake_case alias of the same object for clients that prefer that naming style.

---

## Room Assignment Flow
### Customer assigns rooms
`PATCH /api/v1/user/cleaning/orders/{order}/room-assignments`

Payload:

```json
{
  "assignments": [
    { "roomId": 501, "workerId": 44 },
    { "roomId": 502, "workerId": 44 },
    { "roomId": 503, "workerId": null }
  ]
}
```

Rules:
- `roomId` must belong to the booking.
- `workerId` must be one of the accepted workers, or `null` to unassign.
- The backend keeps the booking `pending` until the team is fulfilled.
- Once the booking becomes `in_progress`, room reassignment is blocked.

### Worker claims rooms
`POST /api/v1/cleaning-bookings/{id}/rooms/claim`

Payload:

```json
{
  "roomIds": [501, 502]
}
```

Rules:
- The worker must already have an accepted assignment.
- The booking must still be `pending`.
- Only unassigned rooms can be claimed.
- If `roomIds` is omitted, the backend claims all currently unassigned rooms for that worker.

### Worker accepts
`POST /api/v1/cleaning-bookings/{id}/accept`

Payload:

```json
{
  "roomIds": [501, 502]
}
```

Rules:
- `roomIds` is optional.
- If provided, the backend claims those rooms during acceptance.
- If the required worker count is not yet met, the booking stays `pending`.
- The accepting worker row becomes `accepted_waiting_for_order_start`; duplicate accepts are rejected.
- When the required count is met, the backend finalizes the booking to `worker_assigned` and auto-balances any remaining unassigned rooms.

### Customer confirms start verification
`POST /api/v1/user/cleaning/orders/{order}/start-verification/confirm`

Payload:

```json
{
  "code": "1234"
}
```

Rules:
- The booking must be `awaiting_start_verification`.
- For current assignment-backed bookings, successful code verification does not immediately start work unless every required worker has already approved start.
- Accepted worker rows move to `awaiting_start_verification`.
- The global booking remains `awaiting_start_verification` while `not_start_approved_workers_count > 0`.
- Legacy single-worker bookings without assignment rows still move directly to `in_progress` for backward compatibility.

### Worker approves start
`POST /api/v1/cleaning-bookings/{id}/start-work`

Rules:
- The worker must have accepted the booking.
- The customer must already have verified the start code.
- The worker row moves to `start_approved` and gets `startApprovedAt`.
- If any required worker has not approved, the global booking remains `awaiting_start_verification`.
- When all required workers have approved, the global booking moves to `in_progress`.

---

## State Model
- `pending` means the order is still searching when accepted workers are fewer than required.
- `worker_assigned` means the required team is complete and the booking can move through the existing travel/start/completion lifecycle.
- `awaiting_start_verification` means the customer has verified/approved the start but one or more workers may still need to approve start.
- `in_progress` is only reached after all required assignment-backed workers are `start_approved`.
- `worker_id` remains the primary legacy worker pointer.
- Accepted workers are still visible through the booking resource and worker-facing screens while the booking is `pending`.

Worker-order statuses:
- `pending`
- `accepted_waiting_for_order_start`
- `awaiting_start_verification`
- `start_approved`
- `rejected`
- `withdrawn`
- `cancelled`
- `accepted` can appear on legacy rows and should be treated as accepted.

---

## Flutter Notes
### User app
- Show an assignment mode step after room selection.
- `preferred_worker` mode should reuse the previous-workers UI.
- `open_count` mode should show a worker-count selector.
- Render `pending` + incomplete team as "searching for workers".
- Show accepted workers, remaining slots, and room ownership in order details.
- During start verification, show `not_start_approved_workers_count` as the number of workers still needed before work begins.
- Send `assignmentMode` and `numberOfWorkers` on create, update, and estimate requests.
- Use `PATCH /room-assignments` for customer room edits.
- Refetch the booking when `cleaning_booking.team_updated` is received.

### Worker app
- Show accepted count, remaining count, and current worker participation on pending bookings.
- After accepting, render `worker_order_status = accepted_waiting_for_order_start` instead of showing the worker their order as plain `pending`.
- When `worker_order_status = awaiting_start_verification`, call `POST /api/v1/cleaning-bookings/{id}/start-work` to approve start.
- If `worker_order_status = start_approved`, keep the worker in a waiting UI until the global `order_status` becomes `in_progress`.
- Allow room selection on accept, and separate room claiming while the booking is still pending.
- Keep travel/start/completion disabled until the booking is `worker_assigned`.
- Use `workerAmount` from worker assignments for earnings and transactions.
- Refetch the booking when `cleaning_booking.team_updated` is received.

---

## Source of Truth
- `Modules/User/app/Services/UserCleaningOrderService.php`
- `Modules/Cleaning/app/Services/CleaningBookingTeamService.php`
- `Modules/Cleaning/app/Http/Resources/CleaningBookingResource.php`
- `Modules/Cleaning/app/Http/Controllers/API/CleaningBookingController.php`
- `Modules/User/app/Http/Controllers/API/UserCleaningOrderRoomAssignmentsController.php`
