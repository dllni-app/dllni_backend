# API Contract: Event Assistance Time Pricing Changes for Flutter

This document covers only the new event/private-occasion changes. It is not a full cleaning order contract.

## Scope

Applies only when:

```json
{
  "propertyType": "event_assistance"
}
```

This change affects:

- `POST /api/v1/user/cleaning/orders/estimate-price`
- `POST /api/v1/user/cleaning/orders`
- `PATCH /api/v1/user/cleaning/orders/{orderId}`
- `GET /api/v1/user/cleaning/orders`
- `GET /api/v1/user/cleaning/orders/{orderId}`
- `GET /api/v1/cleaning-bookings`
- `GET /api/v1/cleaning-bookings/{id}`

Not changed:

- Regular cleaning orders.
- Cleaning add-on/service pricing for regular cleaning.
- Cleaning lifecycle endpoints such as accept, arrive, complete, cancel, review, and verification.

Important: do not use `POST /api/v1/user/cleaning/orders/estimate-size` for the new event time-pricing flow. The user app event flow currently uses `estimate-price`, and the new event fields are implemented there.

## Backend Behavior

For event/private-occasion orders:

- User can type the requested service manually in `propertyDetails.customService`.
- User selects booked duration in `propertyDetails.hours`.
- Price is calculated from order time only.
- Selected services are not used for price.
- `serviceIds` is prohibited.
- `services` in order responses is empty for event assistance.

Pricing formula:

```text
eventHourlyRate = cleaning_financial_settings.extension_rate_per_30_minutes * 2
basePrice = eventHourlyRate * propertyDetails.hours
addonsTotal = 0
totalPrice = basePrice + travelFee + adminMargin
```

If no preferred worker is selected, current backend provisional pricing keeps `travelFee = 0`, `adminMargin = 0`, and `totalPrice = basePrice`.

## User App Request Contract

### Estimate price

`POST /api/v1/user/cleaning/orders/estimate-price`

```json
{
  "propertyType": "event_assistance",
  "propertyDetails": {
    "eventType": "birthday",
    "guestCount": 45,
    "venueType": "apartment",
    "customService": "تجهيز طاولات الضيافة وتنظيف بعد المناسبة",
    "hours": 4
  },
  "assignmentMode": "open_count",
  "numberOfWorkers": 5
}
```

### Create order

`POST /api/v1/user/cleaning/orders`

```json
{
  "propertyType": "event_assistance",
  "propertyDetails": {
    "address": "Damascus, Mazzeh",
    "location_name": "Family Hall",
    "eventType": "family_dinner",
    "guestCount": 40,
    "venueType": "apartment",
    "customService": "مساعدة يدوية في تجهيز الضيافة",
    "hours": 5,
    "specialRequirement": "Male helpers only",
    "notes": "Call before arrival"
  },
  "scheduledDate": "2026-06-20",
  "scheduledTime": "18:30",
  "genderPreference": "male",
  "assignmentMode": "open_count",
  "numberOfWorkers": 4,
  "termsAccepted": true
}
```

### Update order

`PATCH /api/v1/user/cleaning/orders/{orderId}`

```json
{
  "propertyType": "event_assistance",
  "propertyDetails": {
    "address": "Damascus - updated",
    "eventType": "large_gathering",
    "guestCount": 60,
    "venueType": "apartment",
    "customService": "دعم إضافي لمنطقة الضيافة",
    "hours": 6,
    "notes": "Need early arrival"
  }
}
```

## Validation Rules

For `propertyType = event_assistance`:

- `propertyDetails.eventType`: required, one of `family_dinner`, `birthday`, `large_gathering`, `funeral`, `other`.
- `propertyDetails.guestCount`: required integer, min `1`, max `5000`.
- `propertyDetails.venueType`: required, one of `apartment`, `villa`, `house`, `office`, `studio`.
- `propertyDetails.customService`: required string, max `255`.
- `propertyDetails.hours`: required numeric, min `1`, max `24`.
- `serviceIds`: prohibited.
- `workerRoomAssignments`: do not send for event assistance.
- `propertyDetails.cleaning_mode` and `propertyDetails.room_size_breakdown`: do not send for event assistance.

Backend normalizes `hours` up to the nearest half hour and stores it in `propertyDetails.hours`.

## Estimate Price Response

Example when `extension_rate_per_30_minutes = 150` and `hours = 4`:

```json
{
  "size": {
    "estimatedSqm": 90,
    "estimatedHours": 4,
    "sizeTier": "medium"
  },
  "pricing": {
    "basePrice": 1200,
    "addonsTotal": 0,
    "travelFee": 0,
    "distanceKm": null,
    "adminMargin": 0,
    "isPricingFinal": false,
    "totalPrice": 1200,
    "currency": "SYP",
    "serviceLines": [],
    "eventHourlyRate": 300,
    "eventHours": 4,
    "recommendation": {
      "eventType": "birthday",
      "guestCount": 45,
      "venueType": "apartment",
      "customService": "تجهيز طاولات الضيافة وتنظيف بعد المناسبة",
      "hours": 4,
      "suggestedTeamSize": 5
    }
  },
  "assignmentMode": "open_count",
  "workerAcceptance": {
    "required": 5,
    "accepted": 0,
    "remaining": 5,
    "isFulfilled": false
  },
  "recommendation": {
    "eventType": "birthday",
    "guestCount": 45,
    "venueType": "apartment",
    "customService": "تجهيز طاولات الضيافة وتنظيف بعد المناسبة",
    "hours": 4,
    "suggestedTeamSize": 5
  },
  "workerRoomAssignments": null,
  "algorithmVersion": "2026-06-11-v4"
}
```

Flutter should read:

- `pricing.eventHourlyRate`
- `pricing.eventHours`
- `pricing.serviceLines` as an empty list for event assistance.
- top-level `recommendation.suggestedTeamSize` for default worker count.

## Create/Update/List/Show Order Response

Order responses continue to use `order` for create/update and the existing order resource shape for list/show.

Relevant fields:

```json
{
  "id": 101,
  "propertyType": "event_assistance",
  "propertyDetails": {
    "address": "Damascus, Mazzeh",
    "location_name": "Family Hall",
    "event_type": "family_dinner",
    "guest_count": 40,
    "venue_type": "apartment",
    "custom_service": "مساعدة يدوية في تجهيز الضيافة",
    "hours": 5,
    "special_requirement": "Male helpers only",
    "notes": "Call before arrival"
  },
  "estimatedHours": "5.00",
  "totalHours": 5,
  "basePrice": 1500,
  "addonsTotal": 0,
  "totalPrice": 1500,
  "services": []
}
```

Note the casing difference:

- Request uses `customService`, `eventType`, `guestCount`, `venueType`.
- Stored response uses `custom_service`, `event_type`, `guest_count`, `venue_type`.

## Error Responses

### Missing event fields

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "propertyDetails.eventType": ["The property details event type field is required."],
    "propertyDetails.venueType": ["The property details venue type field is required."],
    "propertyDetails.customService": ["The property details custom service field is required."],
    "propertyDetails.hours": ["The property details hours field is required."]
  }
}
```

### Sending serviceIds for an event order

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "serviceIds": ["The service ids field is prohibited."]
  }
}
```

### Hourly rate not configured

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "pricing": ["Event assistance hourly rate is not configured."]
  }
}
```

## `dllni-user-app` Implementation Notes

Scanned files show the current event flow still depends on selected service IDs:

- `lib/features/cl_main/domain/usecases/estimate_cleaning_price_use_case.dart`
- `lib/features/cl_main/domain/usecases/create_cleaning_order_use_case.dart`
- `lib/features/cl_main/view/screens/cl_main_occasion_description_screen.dart`
- `lib/features/cl_main/view/screens/cl_main_occasion_schedule_screen.dart`
- `lib/features/cl_main/view/data/cl_main_route_args.dart`
- `lib/features/cl_main/data/models/estimate_price_response_model.dart`

Required user app changes:

- Replace required `serviceIds` in `EstimateCleaningPriceParams.eventAssistance` with required `customService` and `hours`.
- Replace required `serviceIds` in `CreateCleaningOrderParams.eventAssistance` with required `customService` and `hours`.
- In event `_buildPropertyDetails()`, add:
  - `customService`
  - `hours`
- Do not add `serviceIds` to the body when `_isEventAssistance == true`.
- In `ClMainOccasionDescriptionArgs` / `ClMainOccasionScheduleArgs`, carry the selected manual service text and hours instead of `serviceIds`.
- In `cl_main_occasion_description_screen.dart`, submit estimate with typed/custom service and hours.
- In `cl_main_occasion_schedule_screen.dart`, submit create order with the same `customService` and `hours`.
- Update `EstimatePricingModel` to parse:
  - `eventHourlyRate`
  - `eventHours`
- Update `EstimateRecommendationModel` to parse:
  - `customService`
  - `hours`
  - remove dependency on `selectedServiceCount` for event UI.
- Update tests that currently expect event `serviceIds`.

Suggested Dart model additions:

```dart
class EstimatePricingModel {
  final double? eventHourlyRate;
  final double? eventHours;
}

class EstimateRecommendationModel {
  final String? customService;
  final double? hours;
}
```

## `dllni_cleaning_owner_app` Implementation Notes

Scanned files:

- `lib/features/orders/data/models/fetch_orders_usecase_model.dart`
- `lib/features/orders/view/widgets/accept_order_bottom_sheet.dart`
- `lib/features/orders/view/widgets/order_details/order_details_mission_body.dart`
- `lib/core/widgets/order_card.dart`

Required owner app changes:

- Extend `PropertyDetailsData` to parse event fields:
  - `event_type`
  - `guest_count`
  - `venue_type`
  - `custom_service`
  - `hours`
  - `special_requirement`
  - `notes`
- Parse `estimatedHours` and `totalHours` as `double?`, not `int?`, because event hours may be fractional after half-hour normalization.
- For event orders, do not rely on `services` for task display; it will be empty.
- In accept/order detail UI, if `propertyType == "event_assistance"`, display:
  - `propertyDetails.customService` as the requested service/task.
  - `propertyDetails.hours` or `totalHours` as booked hours.
  - event type / guest count / venue type where useful.
- Mission countdown should use the numeric `estimatedHours` / `totalHours` as a double duration.

Suggested owner model fields:

```dart
class PropertyDetailsData {
  final String? eventType;
  final int? guestCount;
  final String? venueType;
  final String? customService;
  final double? hours;
  final String? specialRequirement;
  final String? notes;
}
```

Suggested owner UI fallback:

```dart
final isEvent = order.propertyType == 'event_assistance';
final taskTitle = isEvent
    ? (order.propertyDetails?.customService ?? 'مساعدة مناسبة')
    : serviceListTitle;
```

## Compatibility Notes

- Backend keeps regular cleaning service/add-on behavior unchanged.
- Backend returns `services: []` for event assistance; Flutter must treat this as expected, not as missing data.
- Existing owner lifecycle actions remain unchanged.
- If the dashboard hourly rate is `0` or missing, event estimate/create/update can return a validation error under `pricing`.
