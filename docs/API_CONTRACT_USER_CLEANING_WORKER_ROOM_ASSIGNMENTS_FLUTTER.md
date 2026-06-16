# Flutter Handoff: Cleaning Worker Room Assignments

Date: June 5, 2026

## Summary

The backend now supports additive pre-booking worker room assignments for the user cleaning flow.

New request field:

- `workerRoomAssignments`

This is supported on:

- `POST /api/v1/user/cleaning/orders/estimate-price`
- `POST /api/v1/user/cleaning/orders`

This does not replace the existing post-booking room edit flow:

- `PATCH /api/v1/user/cleaning/orders/{order}/room-assignments`

Legacy clients can ignore `workerRoomAssignments` and keep working without changes.

---

## What Changed

The user app can now send the room-to-worker-slot plan before the booking is created.

Backend behavior:

- validates the submitted room plan
- auto-fills any missing rooms across slots
- stores the planned slot per room on booking creation
- returns normalized `workerRoomAssignments` in estimate/create/show responses
- maps planned slots onto real accepted workers as the team gets filled

This is only for regular cleaning multi-room flows.

For `propertyType = event_assistance`:

- `workerRoomAssignments` is ignored

---

## Request Field

### `workerRoomAssignments`

Type: `array` of objects

Each item:

| Key | Type | Required | Notes |
| --- | --- | --- | --- |
| `workerSlot` | integer | yes | 1-based slot |
| `preferredWorkerId` | integer/null | yes | `null` in `open_count`, required match in `preferred_worker` |
| `rooms` | array | yes | planned rooms for that slot |

Each room item:

| Key | Type | Required | Notes |
| --- | --- | --- | --- |
| `roomKey` | string | yes | Example: `bedroom.small.1` |
| `roomType` | string | yes | Must match derived room |
| `roomSize` | string | yes | `small`, `medium`, `large` |

---

## Supported Modes

### `assignmentMode = open_count`

- `numberOfWorkers` must be the requested team size
- `workerSlot` must be between `1` and `numberOfWorkers`
- `preferredWorkerId` inside each slot must be `null`

### `assignmentMode = preferred_worker`

- only one slot is allowed
- `workerSlot` must be `1`
- slot `preferredWorkerId` must match top-level `preferredWorkerId`
- `numberOfWorkers` must be `1`

---

## Backend Normalization Rules

The backend does not require the Flutter app to assign every room manually.

If some rooms are missing:

- backend derives all rooms from `propertyDetails.room_size_breakdown`
- backend auto-distributes unassigned rooms across the existing slots

That means Flutter should:

- send the rooms the user explicitly selected
- then trust the normalized response returned by backend

Do not assume the response will exactly match the raw request.

---

## Estimate Endpoint

### Request Example: team mode

```json
{
  "propertyType": "apartment",
  "assignmentMode": "open_count",
  "numberOfWorkers": 2,
  "propertyDetails": {
    "room_size_breakdown": {
      "bedroom": { "small": 1, "medium": 0, "large": 0 },
      "bathroom": { "small": 1, "medium": 0, "large": 0 },
      "kitchen": { "small": 0, "medium": 1, "large": 0 },
      "living_room": { "small": 0, "medium": 1, "large": 0 },
      "balcony": { "small": 0, "medium": 0, "large": 0 }
    }
  },
  "workerRoomAssignments": [
    {
      "workerSlot": 1,
      "preferredWorkerId": null,
      "rooms": [
        { "roomKey": "bedroom.small.1", "roomType": "bedroom", "roomSize": "small" }
      ]
    },
    {
      "workerSlot": 2,
      "preferredWorkerId": null,
      "rooms": [
        { "roomKey": "bathroom.small.1", "roomType": "bathroom", "roomSize": "small" }
      ]
    }
  ]
}
```

### Response additions

Estimate response may now include:

```json
{
  "workerRoomAssignments": [
    {
      "workerSlot": 1,
      "preferredWorkerId": null,
      "roomsWeight": 2.5,
      "estimatedServiceShareAmount": 5500,
      "rooms": [
        { "roomKey": "bedroom.small.1", "roomType": "bedroom", "roomSize": "small" },
        { "roomKey": "kitchen.medium.1", "roomType": "kitchen", "roomSize": "medium" }
      ]
    },
    {
      "workerSlot": 2,
      "preferredWorkerId": null,
      "roomsWeight": 2.6,
      "estimatedServiceShareAmount": 5700,
      "rooms": [
        { "roomKey": "bathroom.small.1", "roomType": "bathroom", "roomSize": "small" },
        { "roomKey": "living_room.medium.1", "roomType": "living_room", "roomSize": "medium" }
      ]
    }
  ]
}
```

Meaning:

- `roomsWeight` is the normalized total room weight for that slot
- `estimatedServiceShareAmount` is a pricing preview only
- this is not the final worker payout contract

Flutter should use this response to refresh the preview after room planning.

---

## Create Endpoint

### Request

Use the same `workerRoomAssignments` shape in:

- `POST /api/v1/user/cleaning/orders`

### Response additions

Create/show responses now include:

- `workerRoomAssignments`
- `roomAssignments[].plannedWorkerSlot`
- `roomAssignments[].plannedPreferredWorkerId`

Example:

```json
{
  "order": {
    "assignmentMode": "open_count",
    "numberOfWorkers": 2,
    "workerRoomAssignments": [
      {
        "workerSlot": 1,
        "preferredWorkerId": null,
        "roomsWeight": 1.8,
        "rooms": [
          { "roomKey": "bedroom.small.1", "roomType": "bedroom", "roomSize": "small" },
          { "roomKey": "bathroom.small.1", "roomType": "bathroom", "roomSize": "small" }
        ]
      },
      {
        "workerSlot": 2,
        "preferredWorkerId": null,
        "roomsWeight": 3.45,
        "rooms": [
          { "roomKey": "kitchen.medium.1", "roomType": "kitchen", "roomSize": "medium" },
          { "roomKey": "living_room.medium.1", "roomType": "living_room", "roomSize": "medium" }
        ]
      }
    ],
    "roomAssignments": [
      {
        "id": 501,
        "roomKey": "bedroom.small.1",
        "plannedWorkerSlot": 1,
        "plannedPreferredWorkerId": null,
        "assignedWorkerId": null
      }
    ]
  }
}
```

---

## Existing Room Assignment Endpoint Still Exists

After booking creation, customer room edits still happen here:

- `PATCH /api/v1/user/cleaning/orders/{order}/room-assignments`

This endpoint is still based on accepted real workers:

```json
{
  "assignments": [
    { "roomId": 501, "workerId": 44 },
    { "roomId": 502, "workerId": 44 },
    { "roomId": 503, "workerId": null }
  ]
}
```

Difference:

- `workerRoomAssignments` = pre-booking slot planning
- `PATCH /room-assignments` = post-booking real worker assignment

Flutter should not mix these two payload shapes.

---

## Validation Errors Flutter Should Handle

Possible `422` cases:

- invalid `workerSlot`
- duplicated slot
- duplicated room across slots
- invalid `roomKey`
- `roomType` mismatch with room key
- `roomSize` mismatch with room key
- preferred-worker mismatch

Expected error paths include:

- `workerRoomAssignments.0.workerSlot`
- `workerRoomAssignments.0.preferredWorkerId`
- `workerRoomAssignments.0.rooms.0.roomKey`
- `workerRoomAssignments.0.rooms.0.roomType`
- `workerRoomAssignments.0.rooms.0.roomSize`

Flutter should map these field errors to the room planning UI, not only show a generic toast.

---

## Flutter Work Required

### 1. Build room-unit keys from `room_size_breakdown`

Flutter must derive room units in the same format used by backend:

- `bedroom.small.1`
- `bathroom.small.1`
- `kitchen.medium.1`
- `living_room.medium.1`

This should come from `propertyDetails.room_size_breakdown`.

### 2. Add planning state before schedule

Recommended flow:

1. user fills property details
2. app builds room unit list
3. user selects assignment mode
4. if `open_count`, user chooses worker count
5. user distributes rooms across slots
6. app sends `workerRoomAssignments` to estimate
7. app re-renders using normalized response
8. app sends same normalized payload on create

### 3. Support both modes

#### Team mode

- show slot-based assignment UI
- labels like `Worker 1`, `Worker 2`, etc.
- each slot has `preferredWorkerId = null`

#### Preferred worker mode

- only one slot
- slot `preferredWorkerId` equals top-level `preferredWorkerId`

### 4. Trust backend normalization

After estimate response:

- replace local worker-room plan with backend `workerRoomAssignments`
- do not keep stale local-only room grouping

### 5. Update models

Add request/response models for:

- `workerRoomAssignments`
- `roomAssignments[].plannedWorkerSlot`
- `roomAssignments[].plannedPreferredWorkerId`

---

## Suggested Dart Models

```dart
class WorkerRoomAssignment {
  final int workerSlot;
  final int? preferredWorkerId;
  final double? roomsWeight;
  final double? estimatedServiceShareAmount;
  final List<WorkerRoomAssignmentRoom> rooms;
}

class WorkerRoomAssignmentRoom {
  final String roomKey;
  final String roomType;
  final String roomSize;
}
```

Extend room assignment response model with:

```dart
class CleaningRoomAssignment {
  final int id;
  final String roomKey;
  final String roomType;
  final String roomSize;
  final int? plannedWorkerSlot;
  final int? plannedPreferredWorkerId;
  final int? assignedWorkerId;
}
```

---

## Rollout Notes

- This change is additive.
- Old app versions remain valid.
- New app version should send `workerRoomAssignments` only when room planning UI is available.

Safe fallback:

- if app does not collect room planning yet, omit `workerRoomAssignments`

---

## Implementation Checklist For Flutter

- [ ] derive room unit keys from `room_size_breakdown`
- [ ] add pre-schedule room-to-slot assignment UI
- [ ] send `workerRoomAssignments` in estimate request
- [ ] replace local preview with normalized backend response
- [ ] send normalized `workerRoomAssignments` in create request
- [ ] read `workerRoomAssignments` from booking response
- [ ] read `plannedWorkerSlot` and `plannedPreferredWorkerId` from `roomAssignments`
- [ ] keep using `PATCH /room-assignments` for post-booking edits
- [ ] handle `422` validation paths on the room planning screen

---

## Backend Source of Truth

- `Modules/User/app/Http/Controllers/API/UserCleaningOrderEstimatePriceController.php`
- `Modules/User/app/Services/UserCleaningOrderService.php`
- `Modules/Cleaning/app/Services/CleaningBookingTeamService.php`
- `Modules/Cleaning/app/Http/Resources/CleaningBookingResource.php`
- `Modules/Cleaning/app/Support/WorkerRoomAssignmentPlanner.php`
