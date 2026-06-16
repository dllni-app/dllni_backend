# API Contract: Cleaning Booking Gender Preference (Flutter)

## Purpose
Allow the user (customer) to set worker gender preference when creating/updating a cleaning booking:
- `male`
- `female`
- `any` (no preference)

Backend filters booking distribution and worker visibility based on this value before a worker can receive/accept the order.

## Base URL
`/api/v1`

## Auth
All endpoints below require `Authorization: Bearer <token>`.

---

## 1) Create Cleaning Booking
### Endpoint
`POST /cleaning-bookings`

### Request Body (new field)
- `genderPreference` (optional): `any | male | female`

If omitted or sent as `null`, backend normalizes to `any`.

### Example Request
```json
{
  "customerId": 123,
  "propertyType": "apartment",
  "propertyDetails": {
    "bedrooms": 2,
    "bathrooms": 1,
    "kitchens": 1
  },
  "scheduledDate": "2026-05-19",
  "scheduledTime": "10:00",
  "estimatedHours": 3,
  "totalHours": 3,
  "basePrice": 120000,
  "addonsTotal": 0,
  "travelFee": 10000,
  "totalPrice": 130000,
  "termsAccepted": true,
  "genderPreference": "female"
}
```

### Example Response (excerpt)
```json
{
  "data": {
    "id": 991,
    "customerId": 123,
    "workerId": null,
    "preferredWorkerId": null,
    "genderPreference": "female",
    "status": "pending",
    "propertyType": "apartment",
    "scheduledDate": "2026-05-19",
    "scheduledTime": "10:00",
    "totalPrice": 130000
  }
}
```

---

## 2) Update Cleaning Booking
### Endpoint
`PUT /cleaning-bookings/{id}`

### Request Body (new field)
- `genderPreference` (optional): `any | male | female`

### Notes
- If `genderPreference` is included as `null`, backend stores it as `any`.
- Existing behavior remains backward-compatible for old clients that do not send this field.

---

## 3) Booking Read Models (where field appears)
`genderPreference` is now returned in booking responses:
- `GET /cleaning-bookings`
- `GET /cleaning-bookings/{id}`
- responses of accept/reject/start-travel/arrive/start-work/complete/cancel endpoints

Field format:
```json
"genderPreference": "any"
```

---

## 4) Worker-Side Filtering Behavior

### Distribution filter (notifications)
Pending booking notifications are sent only to workers matching:
- `genderPreference = any` -> all eligible workers
- `genderPreference = male` -> only workers with `worker.gender = male`
- `genderPreference = female` -> only workers with `worker.gender = female`

### Worker visible pending list filter
When worker app queries pending jobs via:
- `GET /cleaning-bookings?filter[forCurrentWorker]=true`

backend returns pending unassigned bookings only if worker matches booking gender preference (or preference is `any`).

### Worker homepage new orders counter
`GET /cleaning/worker/homepage` -> `newOrdersCount` now respects same gender preference filter.

---

## 5) Accept Guard (server-side safety)
### Endpoint
`POST /cleaning-bookings/{id}/accept`

Even if a worker attempts direct accept API call, backend rejects mismatch.

### Validation Error Example (422)
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "status": [
      "Booking gender preference does not match worker profile."
    ]
  }
}
```

---

## 6) Flutter Integration Notes
- Add enum in app layer:
  - `any`
  - `male`
  - `female`
- Default UI selection should be `any` ("???? ?????").
- Include `genderPreference` in create/update payloads for cleaning bookings.
- Parse `genderPreference` from all booking responses.
- On worker app, no extra client filtering is required; backend already filters pending list + notification eligibility + accept action.
