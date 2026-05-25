# API Contract: User Cleaning Event Assistance (`propertyType=event_assistance`)

## Purpose
This contract guides Flutter implementation of the new **event assistance** flow using existing cleaning order APIs.

- Base path: `/api/v1/user/cleaning/orders`
- Auth: `Authorization: Bearer <token>`
- Content-Type: `application/json`
- No new user endpoints were introduced.

## Event Assistance Mode
Event assistance mode is enabled by:

- `propertyType = "event_assistance"`

When this mode is used, backend applies service-based pricing from `cleaning_services` + `service_pricing` and persists data in `cleaning_bookings`.

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
- `serviceIds`: non-empty array of `cleaning_services.id`

## Optional fields
- `genderPreference`: `any | male | female`
- `numberOfWorkers`: integer >= 1 (if absent, backend uses suggested team size)
- `propertyDetails.specialRequirement`: string
- `propertyDetails.notes`: string
- existing scheduling/location fields remain unchanged where relevant (`scheduledDate`, `scheduledTime`, `addressLatitude`, `addressLongitude`, ...)

## Important constraints
- For `propertyType=event_assistance`, every selected service id must belong to an active `cleaning_services` row with `category=event_assistance`.
- Coupon is **not** supported in this phase.

---

## Example Payloads

### Estimate price payload
```json
{
  "propertyType": "event_assistance",
  "propertyDetails": {
    "eventType": "birthday",
    "guestCount": 45,
    "venueType": "apartment"
  },
  "serviceIds": [12, 15]
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
    "specialRequirement": "Male helpers only",
    "notes": "Call before arrival"
  },
  "serviceIds": [12, 15],
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
    "notes": "Need early arrival"
  },
  "serviceIds": [15, 20]
}
```

---

## Response Additions for Event Assistance

Estimate endpoints now include a recommendation block:

```json
{
  "recommendation": {
    "eventType": "birthday",
    "guestCount": 45,
    "venueType": "apartment",
    "selectedServiceCount": 2,
    "suggestedTeamSize": 6
  }
}
```

Price estimate includes service pricing breakdown:

```json
{
  "pricing": {
    "basePrice": 550,
    "addonsTotal": 0,
    "travelFee": 0,
    "adminMargin": 0,
    "totalPrice": 550,
    "currency": "SYP",
    "serviceLines": [
      {
        "cleaningServiceId": 12,
        "name": "Event serving support",
        "quantity": 1,
        "unitPrice": 300,
        "totalPrice": 300,
        "minHours": 3
      },
      {
        "cleaningServiceId": 15,
        "name": "Event cleanup support",
        "quantity": 1,
        "unitPrice": 250,
        "totalPrice": 250,
        "minHours": 2
      }
    ]
  }
}
```

Create/update responses continue returning `order` (`CleaningBookingResource`) and now reflect:
- `propertyType = "event_assistance"`
- event metadata in `order.propertyDetails`
- persisted `genderPreference`
- `numberOfWorkers` (auto-suggested if not provided)
- related `services` when loaded

---

## Validation/Error Contract

## 422 examples

### Missing required event fields
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "propertyDetails.eventType": ["..."],
    "propertyDetails.venueType": ["..."],
    "serviceIds": ["..."]
  }
}
```

### Invalid/non-event service IDs
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "pricing": [
      "One or more selected event assistance services are invalid."
    ]
  }
}
```

---

## Flutter Implementation Notes
- Reuse existing cleaning order data sources/repositories.
- Branch request payloads by `propertyType`:
  - normal cleaning: existing payload (and optional `serviceIds` as documented in `API_CONTRACT_USER_CLEANING_REGULAR_SERVICES.md`)
  - event assistance: include `eventType`, `guestCount`, `venueType`, `serviceIds`
- Use `recommendation.suggestedTeamSize` as default UI worker count when user does not override.
- Keep lifecycle calls unchanged (`cancel`, `start-verification`, `completion/*`, `review`).
