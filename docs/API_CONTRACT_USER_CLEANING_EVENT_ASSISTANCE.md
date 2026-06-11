# API Contract: User Cleaning Event Assistance (`propertyType=event_assistance`)

## Purpose
This contract guides Flutter implementation of the **event/private occasion** flow using existing cleaning order APIs.

- Base path: `/api/v1/user/cleaning/orders`
- Auth: `Authorization: Bearer <token>`
- Content-Type: `application/json`
- No new user endpoints were introduced.

## Event Assistance Mode
Event assistance mode is enabled by:

- `propertyType = "event_assistance"`

When this mode is used:

- The user describes the requested help manually in `propertyDetails.customService`.
- The user selects the booked duration in `propertyDetails.hours`.
- Pricing is **time-based only**: `basePrice = eventHourlyRate × hours`.
- `serviceIds` are **not used** and must not be sent.
- No `cleaning_services` / `service_pricing` rows affect event order totals.

## Hourly Rate Source
The hourly rate is read from dashboard financial settings:

- `cleaning_financial_settings.extension_rate_per_30_minutes`
- API exposes it as `pricing.eventHourlyRate = extension_rate_per_30_minutes × 2`

Regular cleaning orders are unchanged.

## Supported Endpoints

### 1) Estimate size/time
`POST /api/v1/user/cleaning/orders/estimate-size`

### 2) Estimate price
`POST /api/v1/user/cleaning/orders/estimate-price`

### 3) Create order
`POST /api/v1/user/cleaning/orders`

### 4) Update order
`PATCH /api/v1/user/cleaning/orders/{orderId}`

### 5) List/show order (existing)
- `GET /api/v1/user/cleaning/orders`
- `GET /api/v1/user/cleaning/orders/{orderId}`

### 6) Owner filtering support (existing owner endpoint)
`GET /api/v1/cleaning-bookings?filter[propertyType]=event_assistance`

---

## Request Shape (Event Assistance)

## Required fields
- `propertyType`: `"event_assistance"`
- `propertyDetails.eventType`: `family_dinner | birthday | large_gathering | funeral | other`
- `propertyDetails.guestCount`: integer >= 1
- `propertyDetails.venueType`: `apartment | villa | house | office | studio`
- `propertyDetails.customService`: string, max 255
- `propertyDetails.hours`: number, 1..24 (half-hour increments after normalization)

## Prohibited fields
- `serviceIds` (for event assistance only)

## Optional fields
- `genderPreference`: `any | male | female`
- `numberOfWorkers`: integer >= 1 (if absent, backend uses suggested team size)
- `propertyDetails.specialRequirement`: string
- `propertyDetails.notes`: string
- existing scheduling/location fields remain unchanged where relevant (`scheduledDate`, `scheduledTime`, `addressLatitude`, `addressLongitude`, ...)

## Important constraints
- Coupon is **not** supported in this phase.
- Do not send `room_size_breakdown`, `cleaning_mode`, or `workerRoomAssignments` for event assistance.

---

## Example Payloads

### Estimate price payload
```json
{
  "propertyType": "event_assistance",
  "propertyDetails": {
    "eventType": "birthday",
    "guestCount": 45,
    "venueType": "apartment",
    "customService": "تجهيز طاولات الضيافة وتنظيف بعد المناسبة",
    "hours": 4
  }
}
```

### Create payload
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
  "scheduledDate": "2026-05-26",
  "scheduledTime": "18:30",
  "genderPreference": "male",
  "termsAccepted": true
}
```

### Update payload
```json
{
  "propertyType": "event_assistance",
  "propertyDetails": {
    "eventType": "large_gathering",
    "guestCount": 60,
    "venueType": "apartment",
    "customService": "دعم إضافي لمنطقة الضيافة",
    "hours": 6,
    "notes": "Need early arrival"
  }
}
```

---

## Response Additions for Event Assistance

Estimate endpoints include:

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
    "adminMargin": 0,
    "totalPrice": 1200,
    "currency": "SYP",
    "eventHourlyRate": 300,
    "eventHours": 4,
    "serviceLines": []
  },
  "recommendation": {
    "eventType": "birthday",
    "guestCount": 45,
    "venueType": "apartment",
    "customService": "تجهيز طاولات الضيافة وتنظيف بعد المناسبة",
    "hours": 4,
    "suggestedTeamSize": 5
  },
  "algorithmVersion": "2026-06-11-v4"
}
```

Create/update responses continue returning `order` (`CleaningBookingResource`) and now reflect:
- `propertyType = "event_assistance"`
- `propertyDetails.custom_service`
- `propertyDetails.hours`
- `totalHours` / `estimatedHours` aligned with selected hours
- `order.services` remains empty for event assistance orders

---

## Validation/Error Contract

### Missing required event fields
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "propertyDetails.eventType": ["..."],
    "propertyDetails.venueType": ["..."],
    "propertyDetails.customService": ["..."],
    "propertyDetails.hours": ["..."]
  }
}
```

### Sending serviceIds on event assistance
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "serviceIds": ["The service ids field is prohibited."]
  }
}
```

### Hourly rate not configured in dashboard
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "pricing": [
      "Event assistance hourly rate is not configured."
    ]
  }
}
```

---

## Flutter Implementation Notes
- Reuse existing cleaning order data sources/repositories.
- Branch request payloads by `propertyType`:
  - normal cleaning: existing payload (optional `serviceIds`)
  - event assistance: send `customService` + `hours`; do **not** send `serviceIds`
- Use `pricing.eventHourlyRate` and `pricing.eventHours` to render the price breakdown.
- Use `recommendation.suggestedTeamSize` as default UI worker count when user does not override.
- Keep lifecycle calls unchanged (`cancel`, `start-verification`, `completion/*`, `review`).

## Flutter files to update (reference only; backend does not change Flutter)
- `lib/features/cl_main/domain/usecases/estimate_cleaning_price_use_case.dart`
- `lib/features/cl_main/domain/usecases/create_cleaning_order_use_case.dart`
- `lib/features/cl_main/view/screens/cl_main_occasion_description_screen.dart`
- `lib/features/cl_main/view/screens/cl_main_occasion_schedule_screen.dart`
- `lib/features/cl_main/view/data/cl_main_route_args.dart`
