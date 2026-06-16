# Flutter API Change Guide: Latest Cleaning/SOS Sessions

Generated from the latest implementation sessions and a scan of:

- `dllni-user-app`
- `dllni_cleaning_owner_app`

This guide is for Flutter developers implementing or reviewing the API changes from the latest sessions. The main contract in this file is the event/private-occasion cleaning flow, and the session change log below points to related contracts for travel fee, cleaning status/extension, and SOS changes.

## Latest Session Changes

### 1. Event Assistance Time Pricing

Backend changed event/private-occasion cleaning from service-selection pricing to time-based pricing:

- Event orders use `propertyType = "event_assistance"`.
- Flutter must send `propertyDetails.customService` and `propertyDetails.hours`.
- Flutter must not send `serviceIds` for event assistance.
- Backend calculates `eventHourlyRate` from `cleaning_financial_settings.extension_rate_per_30_minutes * 2`.
- Backend returns `services: []` and `pricing.serviceLines: []` for event assistance.
- Backend returns `pricing.eventHourlyRate`, `pricing.eventHours`, `recommendation.customService`, and `recommendation.hours`.

Flutter impact:

- `dllni-user-app` is mostly aligned after the latest app-side implementation.
- `dllni_cleaning_owner_app` still needs event display/parsing updates so event orders do not appear as regular home-cleaning tasks.

Detailed contract: this file and `docs/API_CONTRACT_FLUTTER_EVENT_ASSISTANCE_TIME_PRICING_CHANGES.md`.

### 2. Cleaning Travel Fee Details

Backend did not rename the travel-fee field. Flutter should continue reading:

```text
travelFee
```

The important behavior change is how Flutter explains `travelFee = 0`:

- `travelFee = 0` is valid when pricing is provisional.
- `isPricingFinal = false` means Flutter should show a provisional pricing note.
- For open-count/multi-worker orders, booking-level travel/admin pricing can remain provisional until the required team is fulfilled.

Detailed contract: `docs/API_CONTRACT_FLUTTER_CLEANING_ORDER_TRAVEL_FEE_DETAILS.md`.

### 3. Cleaning Order Status and Extension Flow

Cleaning completion and extension sessions added/confirmed these status transitions:

- Worker completes work: `in_progress` -> `awaiting_customer_completion`.
- Customer confirms completion: `awaiting_customer_completion` -> `completed`.
- Customer rejects completion: `awaiting_customer_completion` -> `in_progress`.
- Customer requests more time: `awaiting_customer_completion` -> `time_extension_requested`.
- Worker accepts/rejects extension through cleaning time warning endpoints.

Flutter impact:

- User app must drive completion actions from current backend status, not local assumptions.
- Owner app must treat `time_extension_requested` as an actionable state.
- Realtime payloads should be treated as status refresh triggers for both apps.

Detailed contract: `docs/API_CONTRACT_CLEANING_EXTENDING_TIME_WORKFLOW_FLUTTER.md`.

### 4. SOS Flow

The SOS session added/confirmed Flutter-facing SOS APIs:

- `POST /api/v1/user/sos` for user-created SOS tied to a restaurant order.
- `GET /api/v1/sos-alerts` for paginated SOS alert list.
- `GET /api/v1/sos-alerts/{id}` for alert details.

Flutter impact:

- User SOS creation requires authenticated user ownership of the order.
- SOS alert timestamps are not all the same format; parse list/show timestamps as `yyyy-MM-dd HH:mm:ss`, while user SOS creation returns `created_at` as ISO 8601.
- `booking` in SOS alert responses is polymorphic and should be parsed as a dynamic map.

Detailed contract: `docs/SOS_FLOW_API_CONTRACT_FLUTTER.md`.

### 5. App Scan From This Session

The latest scan found:

- `dllni-user-app` already sends event `customService` and `hours`, omits event `serviceIds`, parses new estimate fields, and carries event data through route args.
- `dllni-user-app` should still verify no production flow sends `venueType = "home"` because the backend accepts `house`.
- `dllni_cleaning_owner_app` still parses `estimatedHours` and `totalHours` as `int?` in the orders list model; event hours can be fractional.
- `dllni_cleaning_owner_app` still needs event-aware service titles, task fallback, card metadata, and accept-sheet display.

## Summary

Event assistance uses the existing cleaning order APIs with:

```json
{
  "propertyType": "event_assistance"
}
```

The important change is that event assistance is no longer priced from selected cleaning services. It is priced from booked time:

```text
eventHourlyRate = cleaning_financial_settings.extension_rate_per_30_minutes * 2
basePrice = eventHourlyRate * propertyDetails.hours
addonsTotal = 0
```

For event assistance:

- The user types or selects the requested task text in `propertyDetails.customService`.
- The user selects booked duration in `propertyDetails.hours`.
- Do not send `serviceIds`.
- Do not send `workerRoomAssignments`.
- Response `services` is expected to be an empty list.
- Event hours are normalized by the backend to the nearest half hour.

Regular cleaning orders are unchanged.

## Endpoints Affected

User app endpoints:

- `POST /api/v1/user/cleaning/orders/estimate-price`
- `POST /api/v1/user/cleaning/orders`
- `PATCH /api/v1/user/cleaning/orders/{orderId}`
- `GET /api/v1/user/cleaning/orders`
- `GET /api/v1/user/cleaning/orders/{orderId}`

Cleaning owner app endpoints:

- `GET /api/v1/cleaning-bookings`
- `GET /api/v1/cleaning-bookings/{id}`
- `GET /api/v1/cleaning-bookings?filter[propertyType]=event_assistance`

Important: the current user event flow should use `estimate-price`. Do not move the event flow to `estimate-size` unless the backend contract is reviewed again.

## Request Contract

### Estimate Price

`POST /api/v1/user/cleaning/orders/estimate-price`

```json
{
  "propertyType": "event_assistance",
  "propertyDetails": {
    "eventType": "birthday",
    "guestCount": 45,
    "venueType": "apartment",
    "customService": "Serving and cleanup support",
    "hours": 4
  },
  "assignmentMode": "open_count",
  "numberOfWorkers": 5
}
```

### Create Order

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
    "customService": "Manual hospitality support",
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

### Validation Rules

When `propertyType = "event_assistance"`:

- `propertyDetails.eventType` is required and must be one of `family_dinner`, `birthday`, `large_gathering`, `funeral`, `other`.
- `propertyDetails.guestCount` is required, integer, minimum `1`, maximum `5000`.
- `propertyDetails.venueType` is required and must be one of `apartment`, `villa`, `house`, `office`, `studio`.
- `propertyDetails.customService` is required, string, maximum `255`.
- `propertyDetails.hours` is required, numeric, minimum `1`, maximum `24`.
- `serviceIds` is prohibited.
- `preferredWorkerId` cannot be sent with `assignmentMode = "open_count"`.
- Preferred worker mode supports only one worker.

Do not send these regular-cleaning fields for event assistance:

- `propertyDetails.cleaning_mode`
- `propertyDetails.room_size_breakdown`
- `workerRoomAssignments`
- `serviceIds`

## Estimate Response

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
      "customService": "Serving and cleanup support",
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
    "customService": "Serving and cleanup support",
    "hours": 4,
    "suggestedTeamSize": 5
  },
  "workerRoomAssignments": null,
  "algorithmVersion": "2026-06-11-v4"
}
```

Flutter should read:

- `pricing.eventHourlyRate` for the hourly rate display.
- `pricing.eventHours` for the normalized booked duration.
- `pricing.serviceLines` as an empty list.
- top-level `recommendation.suggestedTeamSize` as a default team size.
- `workerAcceptance` for open-count fulfillment state.

If no preferred worker is selected, pricing may be provisional:

- `travelFee = 0`
- `adminMargin = 0`
- `isPricingFinal = false`

When a preferred worker is selected and coordinates are available, the backend can return finalized travel/admin pricing.

## Order Response Shape

Create/update responses return the order under `order`. List/show endpoints return the existing resource shape.

Relevant event fields:

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
    "custom_service": "Manual hospitality support",
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

- Request uses `eventType`, `guestCount`, `venueType`, `customService`, `hours`.
- Stored response uses `event_type`, `guest_count`, `venue_type`, `custom_service`, `hours`.

Flutter models should parse both camelCase and snake_case where practical because different endpoints/resources may expose either style.

## Error Responses

Missing event fields:

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

Sending `serviceIds` on event assistance:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "serviceIds": ["The service ids field is prohibited."]
  }
}
```

Hourly rate missing in dashboard financial settings:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "pricing": ["Event assistance hourly rate is not configured."]
  }
}
```

## `dllni-user-app` Scan Result

Current status: mostly aligned with the new backend contract.

Scanned files:

- `lib/features/cl_main/domain/usecases/estimate_cleaning_price_use_case.dart`
- `lib/features/cl_main/domain/usecases/create_cleaning_order_use_case.dart`
- `lib/features/cl_main/view/data/cl_main_route_args.dart`
- `lib/features/cl_main/data/models/estimate_price_response_model.dart`
- `lib/features/cl_main/view/screens/cl_main_occasion_description_screen.dart`
- `lib/features/cl_main/view/screens/cl_main_occasion_schedule_screen.dart`
- `lib/features/orders/data/models/cleaning_orders_api_models.dart`
- Related tests under `test/features/cl_main`

What is already implemented:

- `EstimateCleaningPriceParams.eventAssistance` requires `eventType`, `guestCount`, `venueType`, `customService`, and `hours`.
- `CreateCleaningOrderParams.eventAssistance` requires the same event fields plus schedule fields.
- Event request body builds `propertyDetails.customService` and `propertyDetails.hours`.
- Event request body does not include `serviceIds`.
- Route args carry `customService`, `hours`, `numberOfWorkers`, and event labels.
- Occasion description validates `customService` is present and not longer than `255`.
- Estimate model parses `pricing.eventHourlyRate`, `pricing.eventHours`, `pricing.serviceLines`, `recommendation.customService`, and `recommendation.hours`.
- Schedule screen uses event hours to calculate end time and submits create order with event fields.
- User order list/detail models already parse `totalHours` as `double?` and parse `services` as a list that can be empty.

Recommended user app cleanup/checks:

- Ensure no production flow sends `venueType = "home"`. Backend accepts `house`, not `home`. Some tests use `home`; that is fine only if the test is verifying local body shape and not real backend validation.
- If half-hour booking is required in UI, update the event duration selector. Current description flow uses integer hour count.
- Treat `services: []` and `pricing.serviceLines: []` as the correct event state, not as an error.
- Prefer `pricing.eventHours` or `recommendation.hours` for displayed duration after estimate, because backend can normalize.
- Keep coupon behavior disabled for event assistance unless backend support is added.

Suggested user app model fields:

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

## `dllni_cleaning_owner_app` Scan Result

Current status: needs updates for event assistance display and parsing.

Scanned files:

- `lib/features/orders/data/models/fetch_orders_usecase_model.dart`
- `lib/features/orders/data/models/arrive_model.dart`
- `lib/features/orders/view/widgets/accept_order_bottom_sheet.dart`
- `lib/features/orders/view/widgets/order_details/order_details_mission_body.dart`
- `lib/features/orders/view/widgets/order_details/order_details_body.dart`
- `lib/core/widgets/order_card.dart`

Important gaps found:

- `FetchOrdersUsecaseModelDataItem.estimatedHours` and `totalHours` are `int?`; event hours can be fractional. Change them to `double?` and parse with `_asDouble`.
- `PropertyDetailsData` only parses regular cleaning fields. Add event fields from `propertyDetails`.
- `OrderCard._serviceName()` does not handle `event_assistance`, so event orders display as home cleaning.
- `AcceptOrderBottomSheet._serviceName()` does not handle `event_assistance`.
- `AcceptOrderBottomSheet` displays "no detailed service items" when `services` is empty. For event assistance, use `propertyDetails.customService` instead.
- `OrderDetailsMissionBody._tasks` falls back to default room-cleaning tasks when services/addons are empty. For event assistance, use `customService` as the task.
- `OrderCard` shows estimated sqm. For event assistance, guest count and booked hours are more useful.
- `order_details_body.dart` calls `widget.order.totalHours.toString()`. After changing to `double?`, format null and fractional values cleanly.

Owner model changes to make:

```dart
class FetchOrdersUsecaseModelDataItem {
  double? estimatedHours;
  double? totalHours;
}

class PropertyDetailsData {
  String? locationName;
  String? address;
  int? rooms;
  int? bedRooms;
  int? bathrooms;
  bool? kitchen;

  String? eventType;
  int? guestCount;
  String? venueType;
  String? customService;
  double? hours;
  String? specialRequirement;
  String? notes;
}
```

Owner parsing guidance:

```dart
factory PropertyDetailsData.fromJson(Map<String, dynamic> json) {
  return PropertyDetailsData(
    locationName: _asString(json['location_name'] ?? json['locationName']),
    address: _asString(json['address']),
    rooms: _asInt(json['rooms']),
    bedRooms: _asInt(json['bedrooms']),
    bathrooms: _asInt(json['bathrooms']),
    kitchen: _asBool(json['kitchen_included']),
    eventType: _asString(json['event_type'] ?? json['eventType']),
    guestCount: _asInt(json['guest_count'] ?? json['guestCount']),
    venueType: _asString(json['venue_type'] ?? json['venueType']),
    customService: _asString(json['custom_service'] ?? json['customService']),
    hours: _asDouble(json['hours']),
    specialRequirement: _asString(
      json['special_requirement'] ?? json['specialRequirement'],
    ),
    notes: _asString(json['notes']),
  );
}
```

Owner UI guidance:

```dart
bool get isEventAssistance =>
    (order.propertyType ?? '').toLowerCase() == 'event_assistance';

String get serviceTitle {
  if (isEventAssistance) {
    return order.propertyDetails?.customService ?? 'Event assistance';
  }
  return regularCleaningServiceTitle;
}
```

For task lists:

```dart
if (isEventAssistance) {
  final task = widget.order.propertyDetails?.customService?.trim();
  if (task != null && task.isNotEmpty) {
    return <_TaskItem>[
      _TaskItem(
        label: task,
        detail: widget.order.totalHours == null
            ? null
            : '${widget.order.totalHours} hours',
      ),
    ];
  }
}
```

For event order cards, display:

- service title: `propertyDetails.customService`
- guest count: `propertyDetails.guestCount`
- venue type: `propertyDetails.venueType`
- booked hours: `propertyDetails.hours ?? totalHours ?? estimatedHours`
- price: existing `totalPrice`

## QA Checklist

Use these checks before handing the flow back to QA:

- Estimate event assistance without preferred worker returns `pricing.eventHourlyRate`, `pricing.eventHours`, `serviceLines: []`, and `isPricingFinal: false`.
- Estimate event assistance with `serviceIds` returns validation error for `serviceIds`.
- Create event assistance stores response `propertyDetails.custom_service` and `propertyDetails.hours`.
- User app order list/detail does not treat empty `services` as failed or incomplete event data.
- Owner app list card labels event assistance correctly and does not show home-cleaning text.
- Owner accept sheet shows the custom service text instead of "no detailed service items".
- Owner mission task list uses the custom service text instead of default bedroom/bathroom/kitchen tasks.
- Fractional hours such as `2.5` render correctly and countdown logic uses minutes.

## Existing Reference Docs

Related backend docs:

- `docs/API_CONTRACT_FLUTTER_EVENT_ASSISTANCE_TIME_PRICING_CHANGES.md`
- `docs/API_CONTRACT_USER_CLEANING_EVENT_ASSISTANCE.md`
- `docs/API_CONTRACT_USER_CLEANING_ESTIMATE_PRICE.md`
