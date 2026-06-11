# Flutter Guide: Cleaning Extended Time Flow and Data Settings

Audience: Flutter developers implementing the customer app and cleaning owner/worker app.

This file covers only the cleaning extended-time feature. It is separate from the full cleaning order contract so the app teams can implement the flow without scanning unrelated order docs.

## Feature Summary

Extended time starts after the worker says the cleaning work is finished.

1. Worker completes the booking.
2. Backend moves the booking to `awaiting_customer_completion`.
3. Customer chooses one of three actions:
   - Confirm completion.
   - Reject completion.
   - Request extended time.
4. If the customer requests extended time, backend creates a `cleaning_time_warnings` row with quoted minutes and price.
5. Backend moves the booking to `time_extension_requested`.
6. Worker receives the request and accepts or rejects it.
7. If worker accepts, backend applies the quoted extension fee to the booking total exactly once.

The Flutter apps should treat backend status and API responses as the source of truth. Do not calculate final extension prices locally.

## Status Values

Relevant cleaning booking statuses:

| Status | Meaning for Flutter |
| --- | --- |
| `in_progress` | Worker is actively doing the job. |
| `awaiting_customer_completion` | Worker marked work as finished. Customer must confirm, reject, or request extended time. |
| `time_extension_requested` | Customer requested extended time. Worker must accept or reject the request. |
| `completed` | Customer confirmed completion. |

Customer app action visibility:

| Status | Show confirm/reject/extend buttons? |
| --- | --- |
| `awaiting_customer_completion` | Yes. |
| `time_extension_requested` | No. Show pending worker decision state. |
| `completed` | No. |
| `in_progress` | No completion-gate actions. |

Owner/worker app action visibility:

| Status / Warning | Show accept/reject extension? |
| --- | --- |
| Booking status `time_extension_requested` and warning `workerResponse = null` | Yes. |
| Warning already has `workerResponse` | No. Show final decision. |

## Backend Data Settings

Extended-time pricing is configured in the backend admin panel, not in Flutter.

Current pricing source:

- Table: `cleaning_extended_time_prices`
- Backend service: `CleaningExtendedTimePricingService`
- Currency: `config('app.currency', 'SYP')`

Fixed minute ranges:

| Range | Backend match |
| --- | --- |
| 0-15 minutes | `startMinutes = 0`, `endMinutes = 15` |
| 16-30 minutes | `startMinutes = 16`, `endMinutes = 30` |
| 31-45 minutes | `startMinutes = 31`, `endMinutes = 45` |
| 46-60 minutes | `startMinutes = 46`, `endMinutes = 60` |
| 61-75 minutes | `startMinutes = 61`, `endMinutes = 75` |
| 76-90 minutes | `startMinutes = 76`, `endMinutes = 90` |

Important Flutter rules:

- Send `additionalMinutes` as an integer from `0` to `90`.
- Prefer offering only the configured ranges in the UI, for example 15, 30, 45, 60, 75, 90 minutes.
- The backend returns the matched range and calculated price.
- Display the returned price from `extensionPricing.calculatedExtensionPrice` or warning `additionalAmount`.
- Do not derive the final price from an hourly rate or from local app constants.

## Customer App API

Base path:

```text
/api/v1/user
```

### Request Extended Time

```text
POST /api/v1/user/cleaning/orders/{orderId}/completion/extend-time
```

Request:

```json
{
  "additionalMinutes": 30
}
```

Validation:

| Field | Type | Rules |
| --- | --- | --- |
| `additionalMinutes` | integer | Required, min `0`, max `90` |

Valid booking status:

```text
awaiting_customer_completion
```

Success behavior:

- Creates a pending extension warning.
- Stores requested minutes in `additional_minutes`.
- Stores quoted price in `quoted_amount`.
- Stores currency in `quoted_currency`.
- Updates booking status to `time_extension_requested`.
- Returns updated booking in `data`.
- Returns quote details in top-level `extensionPricing`.

Success response shape:

```json
{
  "data": {
    "id": 123,
    "status": "time_extension_requested",
    "bookingNumber": "CLN-USER-AB12CD34",
    "extensionFeeTotal": 0,
    "totalPrice": 125000
  },
  "message": "Extension request sent successfully.",
  "extensionPricing": {
    "requestedMinutes": 30,
    "matchedRange": {
      "id": 2,
      "startMinutes": 16,
      "endMinutes": 30,
      "label": "16 - 30 minutes"
    },
    "calculatedExtensionPrice": 4500,
    "currency": "SYP"
  }
}
```

Flutter handling:

- Replace local order state with `data`.
- Show status as pending worker approval.
- Show quoted fee from `extensionPricing.calculatedExtensionPrice`.
- Keep `extensionFeeTotal` as the already-applied total. Immediately after request it is normally still `0`.
- Refetch order details after realtime events or after returning to the details screen.

Common errors:

| HTTP | Meaning |
| --- | --- |
| `404` | Order does not belong to authenticated customer or does not exist. |
| `422` | Invalid `additionalMinutes`, no matching range, or booking is not in `awaiting_customer_completion`. |

## Worker App API

Base path:

```text
/api/v1
```

### List Extension Requests

```text
GET /api/v1/cleaning-time-warnings
```

Response item:

```json
{
  "id": 77,
  "bookingId": 123,
  "bookingType": "cleaning_booking",
  "customerResponse": "extend_time",
  "workerResponse": null,
  "sentAt": "2026-06-11 10:15:00",
  "customerRespondedAt": "2026-06-11 10:15:00",
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
  "createdAt": "2026-06-11 10:15:00",
  "updatedAt": "2026-06-11 10:15:00"
}
```

Use these fields in Flutter:

| Field | Use |
| --- | --- |
| `id` | Warning id for accept/reject endpoints. |
| `bookingId` | Booking id. |
| `workerResponse` | `null` means pending. Non-null means already answered. |
| `requestedMinutes` | Main field to display requested time. |
| `additionalAmount` | Quoted extension fee to display. |
| `currency` | Fee currency. |
| `priceAppliedAt` | Non-null means accepted price was applied to booking totals. |
| `workerRejectMessage` | Display reject reason if present. |
| `booking.status` | Use for order card/details state. |

### Accept Extended Time

```text
POST /api/v1/cleaning-time-warnings/{warningId}/accept
```

Request:

```json
{
  "additionalMinutes": 30
}
```

Notes:

- `additionalMinutes` is optional/backward-compatible on accept.
- Pricing does not come from this accept request.
- Backend applies the original quoted amount stored on the warning.
- Backend sets `workerResponse = "extend_time"`.
- Backend sets `priceAppliedAt`.
- Backend increments booking `extensionFeeTotal` and `totalPrice` once.

Flutter handling:

- Disable accept/reject after tap until the response returns.
- Replace warning state with response `data`.
- Refetch the booking details so totals are fresh.
- Treat `422` as "already responded" or invalid warning state and refresh.

### Reject Extended Time

```text
POST /api/v1/cleaning-time-warnings/{warningId}/reject
```

Request:

```json
{
  "message": "Cannot extend due to next booking"
}
```

Validation:

| Field | Type | Rules |
| --- | --- | --- |
| `message` | string | Optional, max `500` |

Success behavior:

- Backend sets `workerResponse = "commit_current_time"`.
- Backend stores `workerRejectMessage`.
- No extension fee is applied.

## Realtime Events

### Channels

Customer app:

```text
private-cleaning-booking.{bookingId}
```

Worker app:

```text
private-cleaning-worker.{workerId}
private-cleaning-booking.{bookingId}
```

### Events To Handle

`CleaningBookingTrackingUpdated`

```json
{
  "tracking": {
    "cleaningBookingId": 123,
    "status": "time_extension_requested",
    "workerId": 52,
    "updatedAt": "2026-06-11T10:15:00+00:00"
  }
}
```

`CompletionDecisionMade`

```json
{
  "cleaningBookingId": 123,
  "workerId": 52,
  "decision": "extension_requested",
  "message": null,
  "decidedAt": "2026-06-11T10:15:00+00:00",
  "version": 1
}
```

`ServiceExtensionRequested`

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

Recommended event handling:

- Customer app should refetch `GET /api/v1/user/cleaning/orders/{bookingId}` for extension-related events.
- Worker app should refresh warning list or fetch warning details when `ServiceExtensionRequested` arrives.
- Worker app should refresh booking details after accepting or rejecting.
- Use realtime as a sync trigger, not as the only persistent state.

## Suggested Flutter Models

```dart
class ExtensionPricingDto {
  final int requestedMinutes;
  final ExtensionRangeDto matchedRange;
  final num calculatedExtensionPrice;
  final String currency;
}

class ExtensionRangeDto {
  final int id;
  final int startMinutes;
  final int endMinutes;
  final String label;
}

class CleaningTimeWarningDto {
  final int id;
  final int bookingId;
  final String bookingType;
  final String? workerResponse;
  final int? requestedMinutes;
  final num? additionalAmount;
  final String? currency;
  final DateTime? priceAppliedAt;
  final String? workerRejectMessage;
}
```

Parsing notes:

- Parse money fields as `num` or decimal-safe app type. Backend may serialize decimals as numbers.
- Parse warning timestamps like `2026-06-11 10:15:00`.
- Parse realtime timestamps as ISO 8601 when they include `T` and timezone.
- Treat `additionalMinutes` and `requestedMinutes` as aliases in warning responses. Prefer `requestedMinutes` in new UI code.

## UI Checklist

Customer app:

- Show extend-time action only when booking status is `awaiting_customer_completion`.
- Let the user choose a valid duration between `0` and `90` minutes.
- After successful request, show `time_extension_requested` pending state.
- Show quoted amount and currency returned by backend.
- Do not add the quote to the displayed paid total until backend returns updated totals after worker acceptance.

Worker app:

- Show pending extension cards from `GET /cleaning-time-warnings`.
- Display booking id/number, requested minutes, quoted amount, and currency.
- Show accept and reject only when `workerResponse == null`.
- On accept, refresh booking totals.
- On reject, display the optional reject message and stop showing action buttons.

Shared:

- Disable duplicate taps for accept/reject/request actions.
- Refetch after realtime events and after app resumes.
- Use backend status strings exactly as returned.

## QA Scenarios

1. Worker completes booking, customer app receives/loads `awaiting_customer_completion`, and the extend-time button is visible.
2. Customer requests `30` minutes and receives `status = time_extension_requested` plus `extensionPricing.matchedRange = 16 - 30 minutes`.
3. Customer tries a value above `90` and receives `422`.
4. Worker receives `ServiceExtensionRequested` with `warningId`, `requestedMinutes`, `additionalAmount`, and `currency`.
5. Worker accepts once and booking totals increase by the quoted amount.
6. Worker tries to accept or reject the same warning again and receives `422`; Flutter refreshes state and hides actions.
7. Worker rejects with a message and no booking extension fee is applied.
8. Customer and worker both refresh order details after realtime events and show the same status.

## Implementation References

Backend source files:

- `Modules/User/app/Http/Requests/UserCleaningOrderCompletionExtendTimeRequest.php`
- `Modules/User/app/Http/Controllers/API/UserCleaningOrderCompletionExtendTimeController.php`
- `Modules/User/app/Services/UserCleaningOrderService.php`
- `Modules/Cleaning/app/Services/CleaningExtendedTimePricingService.php`
- `Modules/Cleaning/app/Http/Resources/CleaningTimeWarningResource.php`
- `Modules/Cleaning/app/Services/CleaningTimeWarningService.php`
- `Modules/Cleaning/app/Events/ServiceExtensionRequested.php`

