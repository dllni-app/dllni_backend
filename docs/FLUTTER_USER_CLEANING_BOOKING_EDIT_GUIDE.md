# Flutter Guide: Edit User Cleaning Booking

## Backend status

The backend edit endpoint already exists. Do **not** add a second endpoint in Flutter or backend.

```http
PATCH /api/v1/user/cleaning/orders/{orderId}
Authorization: Bearer <token>
Accept: application/json
Content-Type: application/json
```

The route is authenticated and the backend scopes the booking to the authenticated customer. A user cannot update another user's cleaning booking.

The booking response now also includes:

```json
{
  "canEdit": true,
  "can_edit": true
}
```

`canEdit` is the primary Flutter field. `can_edit` is included as a compatibility alias.

The flag is returned anywhere the user app receives `UserCleaningBookingResource`, including cleaning booking details and the response returned by the update endpoint.

---

## `canEdit` behavior

Flutter should show/enable the generic **Edit booking** action only when:

```dart
booking.canEdit == true
```

The backend returns `canEdit = false` when the global booking status is one of:

- `in_progress`
- `completed`
- `cancelled`

For other booking statuses, `canEdit` is `true` because the update endpoint can still accept at least some customer edits.

### Important: accepted workers add field-level restrictions

`canEdit = true` does not mean every field is always editable.

When at least one worker has already accepted the booking (`accepted_workers_count > 0`), the backend blocks changes to room/pricing/assignment-related fields, including:

- `propertyType`
- `propertyDetails`
- `addressLatitude`
- `addressLongitude`
- `neighborhoodId`
- `neighborhood`
- `preferredWorkerId`
- `assignmentMode`
- `numberOfWorkers`
- `genderPreference`

For a generic full booking edit screen, Flutter should therefore use this rule:

```dart
final canOpenEdit = booking.canEdit;
final hasAcceptedWorkers = booking.acceptedWorkersCount > 0;
final canEditBookingConfiguration = canOpenEdit && !hasAcceptedWorkers;
```

When `hasAcceptedWorkers == true`, keep configuration/pricing/worker fields read-only. The current backend still permits partial updates such as schedule changes when the lifecycle permits them.

Room assignment changes are handled by the dedicated endpoint, not by the generic booking update payload:

```http
PATCH /api/v1/user/cleaning/orders/{orderId}/room-assignments
```

---

## Flutter model change

Add `canEdit` to the cleaning booking model/entity.

Example:

```dart
class CleaningBooking {
  final int id;
  final String status;
  final bool canEdit;
  final int acceptedWorkersCount;

  CleaningBooking({
    required this.id,
    required this.status,
    required this.canEdit,
    required this.acceptedWorkersCount,
  });

  factory CleaningBooking.fromJson(Map<String, dynamic> json) {
    return CleaningBooking(
      id: json['id'] as int,
      status: (json['status'] ?? '').toString(),
      canEdit: (json['canEdit'] ?? json['can_edit'] ?? false) == true,
      acceptedWorkersCount:
          (json['accepted_workers_count'] as num?)?.toInt() ?? 0,
    );
  }
}
```

If the app uses separate DTO/domain models, add the field to both and map it through the repository layer.

---

## Edit button behavior

Recommended UI behavior on the booking details page:

```dart
if (booking.canEdit) {
  // Show Edit booking action.
}
```

Do not duplicate the backend status list in Flutter as the primary source of truth. The backend flag should decide whether the edit action is enabled.

The status may still be used for labels/help text, but not as a second competing editability rule.

---

## Request behavior

The endpoint is a partial `PATCH` endpoint. Flutter should send **only fields that changed** instead of rebuilding and sending the whole create-order payload.

Example: schedule-only change

```json
{
  "scheduledDate": "2026-08-25",
  "scheduledTime": "11:30"
}
```

Example: property details change before workers accept

```json
{
  "propertyType": "apartment",
  "propertyDetails": {
    "address": "Damascus - Mazzeh",
    "rooms": 4,
    "bedrooms": 3,
    "bathrooms": 2,
    "kitchens": 1,
    "living_room_size": "large",
    "cleaning_mode": "regular"
  },
  "numberOfWorkers": 2,
  "assignmentMode": "open_count",
  "addressLatitude": 33.5138,
  "addressLongitude": 36.2765
}
```

Example: preferred worker change before workers accept

```json
{
  "assignmentMode": "preferred_worker",
  "preferredWorkerId": 15,
  "numberOfWorkers": 1
}
```

---

## Fields supported by the current update request

All fields are optional because this is `PATCH`.

### Booking/property fields

- `propertyType`
- `propertyDetails`
- `scheduledDate`
- `scheduledTime`
- `addressLatitude`
- `addressLongitude`
- `neighborhoodId`
- `neighborhood`
- `preferredWorkerId`
- `numberOfWorkers`
- `assignmentMode`
- `genderPreference`
- `workEnvironmentConfirmation` when required for a female-worker preference
- `cleaning_services` (legacy string-array field)

### Property details supported by update

- `address`
- `location_name`
- `bedrooms`
- `rooms`
- `bathrooms`
- `toilets`
- `kitchens`
- `balconies`
- `sheds`
- `living_room_size`
- `cleaning_mode`
- `room_size_breakdown`
- event-assistance fields: `eventType`, `guestCount`, `venueType`, `customService`, `hours`, `specialRequirement`, `notes`

### Fields Flutter must not send

The backend calculates these values and rejects them if the client sends them:

- `estimatedSqm`
- `estimatedHours`
- `totalHours`
- `basePrice`
- `travelFee`
- `addonsTotal`
- `totalPrice`

The current request also prohibits `serviceIds`; do not send it to this endpoint. Use only the contract supported by the deployed backend.

---

## Response

Successful update:

```json
{
  "order": {
    "id": 123,
    "status": "pending",
    "scheduledDate": "2026-08-25",
    "scheduledTime": "11:30",
    "canEdit": true,
    "can_edit": true
  }
}
```

Flutter should replace/refetch the local booking with the returned `order` instead of assuming the submitted values are the final state. The backend can normalize property details, assignment mode, worker count, pricing and other calculated values.

---

## Recommended repository method

```dart
Future<CleaningBooking> updateCleaningBooking({
  required int orderId,
  required Map<String, dynamic> changes,
}) async {
  final response = await dio.patch(
    '/api/v1/user/cleaning/orders/$orderId',
    data: changes,
  );

  return CleaningBooking.fromJson(
    Map<String, dynamic>.from(response.data['order'] as Map),
  );
}
```

Do not call the API when `booking.canEdit == false`.

---

## Recommended edit flow

1. Open booking details.
2. Read `canEdit` from the backend response.
3. Hide/disable the edit action when `canEdit == false`.
4. If editing is allowed, check `accepted_workers_count`.
5. If workers already accepted, keep configuration/pricing/assignment fields read-only and only expose still-valid partial edits such as schedule fields.
6. Keep the original booking snapshot when the edit screen opens.
7. On save, build a map containing only changed fields.
8. Call `PATCH /api/v1/user/cleaning/orders/{orderId}`.
9. On `200`, replace the local booking with `response.order` and close the edit screen.
10. On `422`, show the backend validation message and keep the user's entered values.
11. On `404`, remove/refresh the stale booking because the authenticated user can no longer resolve that order.
12. Refetch booking details after important realtime lifecycle events because editability may change while the screen is open.

---

## Error handling

### Booking is no longer editable

The backend returns `422` when the booking reaches a terminal edit state between opening the page and pressing Save.

Example:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "order": [
      "Order cannot be edited in current status."
    ]
  }
}
```

Flutter should:

1. show the message,
2. refetch the booking,
3. update `canEdit`,
4. close/disable the edit UI if the new value is `false`.

### Worker accepted while user was editing

For protected fields, the backend can return:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "order": [
      "Order cannot change room or pricing fields after workers have accepted."
    ]
  }
}
```

Flutter should refetch the booking and switch protected fields to read-only.

### Validation error

Use the normal Laravel `422` field errors and render the first relevant message next to the field when possible.

---

## Realtime handling

Editability is lifecycle-dependent. When the user app receives a cleaning booking realtime event:

1. refetch the current booking,
2. replace the local booking model,
3. re-evaluate `canEdit`,
4. if the edit screen is currently open and editing becomes unavailable, prevent submission and show a lifecycle-change message.

The backend remains the source of truth for booking state and editability.

---

## Acceptance checklist for Flutter

- [ ] Add `canEdit` to cleaning booking DTO/entity.
- [ ] Read camelCase `canEdit`, with `can_edit` as fallback.
- [ ] Show edit action only when `canEdit == true`.
- [ ] Read `accepted_workers_count` to lock configuration fields after worker acceptance.
- [ ] Send partial PATCH payloads containing only changed fields.
- [ ] Never send backend-calculated price/time fields.
- [ ] Do not send `serviceIds` to the current update endpoint.
- [ ] Use the dedicated room-assignment endpoint for room assignment changes.
- [ ] Replace local state with `response.order` after a successful update.
- [ ] Handle `422` by displaying backend validation and refetching lifecycle state when appropriate.
- [ ] Refetch after relevant realtime events so `canEdit` stays current.
