# Cleaning User Orders API Contract

Repository: `dllni-app/dllni_backend`  
Base URL: `https://alnadha.net/api/v1/user`  
Authentication: `Authorization: Bearer <sanctum_token>`  
Content type: `application/json`

This contract documents the user-facing cleaning order endpoints:

- `GET /cleaning/orders/previous-workers`
- `POST /cleaning/orders/estimate-price`
- `POST /cleaning/orders`

It also explains the key response props and the order lifecycle scenarios used by the Flutter user app.

---

## 1. Shared Rules

### Authentication

All endpoints are inside the authenticated user API group. The client must send a valid Sanctum bearer token.

```http
Authorization: Bearer <token>
Accept: application/json
Content-Type: application/json
```

### Common Validation Error Shape

Laravel validation errors return HTTP `422`.

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "fieldName": [
      "Validation message"
    ]
  }
}
```

### Common Property Types

Allowed `propertyType` values:

| Value | Meaning |
|---|---|
| `apartment` | Apartment cleaning |
| `villa` | Villa cleaning |
| `house` | House cleaning |
| `office` | Office cleaning |
| `studio` | Studio cleaning |
| `event_assistance` | Special event assistance order |

### Cleaning Modes

| Value | Meaning |
|---|---|
| `regular` | Regular cleaning |
| `deep` | Deep cleaning; applies a deeper cleaning multiplier in pricing |

### Gender Preference

| Value | Meaning |
|---|---|
| `any` | No gender preference |
| `male` | Male worker preferred |
| `female` | Female worker requested; triggers the female worker safety confirmation flow |

### Assignment Modes

| Value | Meaning |
|---|---|
| `preferred_worker` | User requests one specific worker. Requires `preferredWorkerId` and resolves `numberOfWorkers` to `1`. |
| `open_count` | Open dispatch flow. User can request one or many workers. Workers accept until the required count is fulfilled. |

Important normalization behavior:

- `preferredWorkerIds` is normalized from either `preferredWorkerIds` or `preferredWorkerId`.
- If more than one preferred worker ID is sent, the request is normalized to `assignmentMode = open_count`.
- If `assignmentMode = preferred_worker` but multiple workers are requested, the backend resolves it as `open_count`.
- In estimate-price response, if one valid `preferredWorkerId` is sent and requested workers is `1`, assignment mode becomes `preferred_worker`; otherwise it becomes `open_count`.

---

## 2. GET `/cleaning/orders/previous-workers`

### Purpose

Returns up to the latest 20 workers who previously completed cleaning bookings for the authenticated user. The endpoint is used to populate the "previous workers" / "favorite previous worker" selection UI.

### Method and URL

```http
GET /api/v1/user/cleaning/orders/previous-workers
```

### Query Parameters

| Param | Type | Required | Allowed Values | Description |
|---|---:|---:|---|---|
| `propertyType` | string | No | `apartment`, `villa`, `house`, `office`, `studio`, `event_assistance` | Optional filter. If provided, workers are filtered by their preferred work type matching this property type. |

### Success Response `200`

```json
{
  "workers": [
    {
      "workerId": 12,
      "name": "سارة",
      "gender": "female",
      "avatarUrl": "https://example.com/avatar.jpg",
      "description": "عاملة تنظيف محترفة...",
      "ratings": {
        "average": 4.8,
        "count": 19
      },
      "averageRating": 4.8,
      "completedJobsWithUser": 3,
      "lastWorkedDate": "2026-06-25"
    }
  ]
}
```

### Response Props

| Prop | Type | Meaning |
|---|---|---|
| `workers` | array | List of previous workers; empty array means no history. |
| `workerId` | integer | Worker ID to pass later as `preferredWorkerId`. |
| `name` | string | Worker first name. |
| `gender` | string | Worker gender. |
| `avatarUrl` | string/null | Worker avatar URL. Falls back to user primary image if no worker avatar exists. |
| `description` | string/null | Worker bio. |
| `ratings.average` | number | Average customer rating. |
| `ratings.count` | integer | Count of customer ratings. |
| `averageRating` | number | Backward-compatible flat average rating. |
| `completedJobsWithUser` | integer | Number of completed bookings between this user and worker. |
| `lastWorkedDate` | string | Most recent completed booking date with this worker. |

### Scenario Notes

1. The endpoint only checks completed bookings for the authenticated user.
2. Bookings without `worker_id` are ignored.
3. Results are grouped by `worker_id`, ordered by latest worked date, and limited to 20 workers.
4. If `propertyType` is sent, a worker may be removed from the list if their preferred work type does not match the requested property type.

---

## 3. POST `/cleaning/orders/estimate-price`

### Purpose

Calculates a preview before creating the order. Use it after the user selects property details, address, worker preference, worker count, gender preference, services, and optional room assignments.

The endpoint returns:

- estimated size and hours
- pricing preview
- assignment mode
- worker acceptance requirement
- optional worker room assignment preview
- female worker safety confirmation requirements
- extended time pricing ranges
- pricing algorithm version

### Method and URL

```http
POST /api/v1/user/cleaning/orders/estimate-price
```

### Request Body: Regular Cleaning Example

```json
{
  "propertyType": "villa",
  "propertyDetails": {
    "address": "حلب - الحمدانية - شارع القدس - بناء 12 - طابق 3",
    "location_name": "المنزل",
    "bedrooms": 2,
    "rooms": 1,
    "bathrooms": 1,
    "toilets": 0,
    "kitchens": 1,
    "balconies": 0,
    "living_room_size": "small",
    "cleaning_mode": "regular",
    "room_size_breakdown": {
      "bedroom": { "small": 0, "medium": 1, "large": 1 },
      "bathroom": { "small": 0, "medium": 0, "large": 1 },
      "kitchen": { "small": 1, "medium": 0, "large": 0 }
    }
  },
  "addressLatitude": 36.1795,
  "addressLongitude": 37.1082,
  "preferredWorkerId": 1,
  "assignmentMode": "preferred_worker",
  "numberOfWorkers": 1,
  "genderPreference": "any"
}
```

### Request Body: Open Count / Multiple Workers Example

```json
{
  "propertyType": "apartment",
  "propertyDetails": {
    "address": "Aleppo - Example Street",
    "rooms": 4,
    "bathrooms": 2,
    "kitchens": 1,
    "balconies": 1,
    "living_room_size": "medium",
    "cleaning_mode": "deep"
  },
  "addressId": 5,
  "assignmentMode": "open_count",
  "numberOfWorkers": 3,
  "genderPreference": "male",
  "workerRoomAssignments": [
    {
      "roomKey": "bedroom_1",
      "workerIndex": 1
    },
    {
      "roomKey": "bathroom_1",
      "workerIndex": 2
    }
  ]
}
```

### Request Body: Event Assistance Example

```json
{
  "propertyType": "event_assistance",
  "propertyDetails": {
    "address": "حلب - قاعة المثال",
    "eventType": "birthday",
    "guestCount": 120,
    "venueType": "villa",
    "customService": "Hospitality and cleanup during event",
    "hours": 5,
    "specialRequirement": "Female staff preferred",
    "notes": "Arrive 30 minutes before event"
  },
  "addressLatitude": 36.1795,
  "addressLongitude": 37.1082,
  "assignmentMode": "open_count",
  "numberOfWorkers": 4,
  "genderPreference": "female"
}
```

### Request Fields

| Field | Type | Required | Description |
|---|---:|---:|---|
| `propertyType` | string | Yes | One of the allowed property types. |
| `propertyDetails` | object | Yes | Room/property/event details used for estimation. |
| `propertyDetails.bedrooms` | integer | No | Legacy/summary room count. |
| `propertyDetails.rooms` | integer | No | Bedrooms/rooms count. |
| `propertyDetails.bathrooms` | integer | No | Bathrooms count. |
| `propertyDetails.toilets` | integer | No | Toilets count. |
| `propertyDetails.kitchens` | integer | No | Kitchens count. |
| `propertyDetails.balconies` | integer | No | Balconies count. |
| `propertyDetails.living_room_size` | string | No | `small`, `medium`, `large`, `very_large`. |
| `propertyDetails.cleaning_mode` | string | No | `regular` or `deep`. |
| `propertyDetails.room_size_breakdown` | object | No | Detailed room counts by size. Supports `bedroom`, `bathroom`, `toilet`, `kitchen`, `living_room`, `balcony`, `corridor`; each has `small`, `medium`, `large`. |
| `propertyDetails.eventType` | string | Required for `event_assistance` | `family_dinner`, `birthday`, `large_gathering`, `funeral`, `other`. |
| `propertyDetails.guestCount` | integer | Required for `event_assistance` | Guest count from 1 to 5000. |
| `propertyDetails.venueType` | string | Required for `event_assistance` | Any property type except `event_assistance`. |
| `propertyDetails.customService` | string | Required for `event_assistance` | Free-text service requested by the user; prohibited for normal cleaning. |
| `propertyDetails.hours` | number | Required for `event_assistance` | Event service hours from 1 to 24; prohibited for normal cleaning. |
| `serviceIds` | array<int> | No, regular cleaning only | Add-on cleaning service IDs. Prohibited for event assistance. |
| `addressId` | integer | No | Existing user address. If used, backend resolves `addressLatitude` and `addressLongitude` from the address. |
| `addressLatitude` | number | No | Latitude, required indirectly if `addressId` is used and address must include coordinates. |
| `addressLongitude` | number | No | Longitude, required indirectly if `addressId` is used and address must include coordinates. |
| `preferredWorkerIds` | array<int> | No | Multiple previous/preferred workers. Normalizes assignment mode to `open_count` if more than one is sent. |
| `preferredWorkerId` | integer | No | One selected worker. Can make pricing final because travel/admin margin can be calculated for that worker. |
| `assignmentMode` | string | No | `preferred_worker` or `open_count`. |
| `numberOfWorkers` | integer | No | 1 to 20. If missing, backend uses recommended team size or 1. |
| `genderPreference` | string | No | `any`, `male`, `female`. |
| `workerRoomAssignments` | array | No | Optional room-to-worker plan for non-event cleaning orders. |

### Success Response `200`

```json
{
  "size": {
    "estimatedSqm": 85,
    "estimatedHours": 4.5,
    "sizeTier": "medium"
  },
  "pricing": {
    "basePrice": 45000,
    "addonsTotal": 5000,
    "travelFee": 10000,
    "distanceKm": 7.2,
    "adminMargin": 6000,
    "isPricingFinal": true,
    "totalPrice": 60000,
    "currency": "SYP",
    "serviceLines": [],
    "roomPricingLines": [],
    "pricingAlgorithm": {
      "baseUnitPrice": 1000,
      "deepCleaningMultiplier": 1.5,
      "areaMarginMultiplier": 1.1,
      "setupBufferMinutes": 30,
      "unitTotal": 40,
      "modeMultiplier": 1
    },
    "eventHourlyRate": null,
    "eventHours": null,
    "recommendation": null
  },
  "assignmentMode": "preferred_worker",
  "workerAcceptance": {
    "required": 1,
    "accepted": 0,
    "remaining": 1,
    "isFulfilled": false
  },
  "recommendation": null,
  "workerRoomAssignments": null,
  "workEnvironmentConfirmation": {
    "required": false
  },
  "extendedTimeRanges": [
    {
      "id": 1,
      "startMinutes": 0,
      "endMinutes": 15,
      "label": "0 - 15 minutes",
      "price": 10000,
      "currency": "SYP"
    }
  ],
  "algorithmVersion": "2026-06-22-room-db-v1"
}
```

### Response Props

| Prop | Type | Meaning |
|---|---|---|
| `size.estimatedSqm` | number | Estimated property/event size in square meters. |
| `size.estimatedHours` | number | Estimated required work hours. |
| `size.sizeTier` | string | Backend size tier derived from area. |
| `pricing.basePrice` | number | Base cleaning/event price before travel and final additions. |
| `pricing.addonsTotal` | number | Add-on services total. |
| `pricing.travelFee` | number | Worker travel fee; final only when a preferred worker is selected. |
| `pricing.distanceKm` | number/null | Distance to selected worker when final pricing is possible. |
| `pricing.adminMargin` | number | Admin margin amount. |
| `pricing.isPricingFinal` | boolean | `true` when pricing is tied to a specific worker; `false` for open dispatch preview. |
| `pricing.totalPrice` | number | Total preview price. |
| `pricing.currency` | string | Currency from app config, usually `SYP`. |
| `pricing.serviceLines` | array | Add-on service pricing lines. |
| `pricing.roomPricingLines` | array | Room-size pricing details for normal cleaning. |
| `pricing.pricingAlgorithm` | object/null | Algorithm details for regular cleaning. Null for event assistance. |
| `pricing.eventHourlyRate` | number/null | Hourly rate used for event assistance. |
| `pricing.eventHours` | number/null | Rounded event hours used for event assistance. |
| `assignmentMode` | string | Final resolved assignment mode after backend normalization. |
| `workerAcceptance.required` | integer | Number of workers required to fulfill the order. |
| `workerAcceptance.accepted` | integer | Always `0` in estimate response because order is not created yet. |
| `workerAcceptance.remaining` | integer | Same as required in estimate response. |
| `workerAcceptance.isFulfilled` | boolean | Always `false` in estimate response. |
| `recommendation` | object/null | Suggested team/event details, especially for `event_assistance`. |
| `workerRoomAssignments` | array/null | Preview of planned worker-room assignments if provided and valid. |
| `workEnvironmentConfirmation.required` | boolean | `true` only when `genderPreference = female`. |
| `extendedTimeRanges` | array | Admin-configured time extension ranges shown during completion flow. |
| `algorithmVersion` | string | Pricing/estimation algorithm version. Current value: `2026-06-22-room-db-v1`. |

### Female Worker Safety Flow in Estimate

When `genderPreference != female`:

```json
{
  "workEnvironmentConfirmation": {
    "required": false
  }
}
```

When `genderPreference = female`, response includes `required: true` plus the policy payload from `FemaleWorkerSafetyPolicyService`. The Flutter app must show the confirmation screen before calling `POST /cleaning/orders`.

---

## 4. POST `/cleaning/orders`

### Purpose

Creates the real cleaning/event order. The request body is similar to `estimate-price`, but it also requires schedule, address text, terms acceptance, and female worker safety confirmation when requesting a female worker.

### Method and URL

```http
POST /api/v1/user/cleaning/orders
```

### Request Body: Regular Cleaning Example

```json
{
  "propertyType": "villa",
  "propertyDetails": {
    "address": "حلب - الحمدانية - شارع القدس - بناء 12 - طابق 3",
    "location_name": "المنزل",
    "bedrooms": 2,
    "rooms": 1,
    "bathrooms": 1,
    "toilets": 0,
    "kitchens": 1,
    "balconies": 0,
    "living_room_size": "small",
    "cleaning_mode": "regular",
    "room_size_breakdown": {
      "bedroom": { "small": 0, "medium": 1, "large": 1 },
      "bathroom": { "small": 0, "medium": 0, "large": 1 }
    }
  },
  "cleaning_services": [
    "تنظيف النوافذ",
    "تعقيم المطبخ"
  ],
  "scheduledDate": "2026-07-01",
  "scheduledTime": "09:00",
  "addressLatitude": 36.1795,
  "addressLongitude": 37.1082,
  "neighborhoodId": 3,
  "preferredWorkerId": 1,
  "assignmentMode": "preferred_worker",
  "numberOfWorkers": 1,
  "genderPreference": "any",
  "cancellationPolicyId": 1,
  "billingPolicyId": 1,
  "termsAccepted": true
}
```

### Request Body: Female Worker Example

```json
{
  "propertyType": "apartment",
  "propertyDetails": {
    "address": "حلب - الشهباء",
    "rooms": 2,
    "bathrooms": 1,
    "kitchens": 1,
    "living_room_size": "medium",
    "cleaning_mode": "regular"
  },
  "scheduledDate": "2026-07-01",
  "scheduledTime": "10:30",
  "addressLatitude": 36.1795,
  "addressLongitude": 37.1082,
  "assignmentMode": "open_count",
  "numberOfWorkers": 1,
  "genderPreference": "female",
  "workEnvironmentConfirmation": {
    "beneficiaryPresence": "female_present",
    "pledgeAccepted": true,
    "pledgeVersion": "CURRENT_POLICY_VERSION_FROM_ESTIMATE_RESPONSE"
  },
  "termsAccepted": true
}
```

### Request Body: Event Assistance Example

```json
{
  "propertyType": "event_assistance",
  "propertyDetails": {
    "address": "حلب - قاعة المثال",
    "location_name": "قاعة المثال",
    "eventType": "large_gathering",
    "guestCount": 300,
    "venueType": "villa",
    "customService": "Serving guests and cleaning during the event",
    "hours": 6,
    "specialRequirement": "Uniform required",
    "notes": "Main hall and outdoor area"
  },
  "scheduledDate": "2026-07-05",
  "scheduledTime": "18:00",
  "addressLatitude": 36.1795,
  "addressLongitude": 37.1082,
  "assignmentMode": "open_count",
  "numberOfWorkers": 6,
  "genderPreference": "any",
  "termsAccepted": true
}
```

### Request Fields

| Field | Type | Required | Description |
|---|---:|---:|---|
| `propertyType` | string | Yes | One of the allowed property types. |
| `propertyDetails` | object | Yes | Must include only allowed keys. |
| `propertyDetails.address` | string | Yes | Customer location/address text, max 500 chars. If `addressId` is sent, backend can fill this from saved address. |
| `propertyDetails.location_name` | string | No | Label/name for the location. |
| `propertyDetails.bedrooms` | integer | No | 0 to 100. |
| `propertyDetails.rooms` | integer | No | 0 to 100. |
| `propertyDetails.bathrooms` | integer | No | 0 to 100. |
| `propertyDetails.toilets` | integer | No | 0 to 100. |
| `propertyDetails.kitchens` | integer | No | 0 to 100. |
| `propertyDetails.balconies` | integer | No | 0 to 100. |
| `propertyDetails.living_room_size` | string | No | `small`, `medium`, `large`, `very_large`. |
| `propertyDetails.cleaning_mode` | string | No | `regular` or `deep`. |
| `propertyDetails.room_size_breakdown` | object | No | Detailed counts by room type and size. |
| `propertyDetails.eventType` | string | Required for `event_assistance` | `family_dinner`, `birthday`, `large_gathering`, `funeral`, `other`. |
| `propertyDetails.guestCount` | integer | Required for `event_assistance` | 1 to 5000. |
| `propertyDetails.venueType` | string | Required for `event_assistance` | Any property type except `event_assistance`. |
| `propertyDetails.customService` | string | Required for `event_assistance` | Free-text service, max 255. Prohibited for normal cleaning. |
| `propertyDetails.hours` | number | Required for `event_assistance` | 1 to 24. Prohibited for normal cleaning. |
| `propertyDetails.specialRequirement` | string | No | Special request, max 255. |
| `propertyDetails.notes` | string | No | Notes, max 2000. |
| `cleaning_services` | array<string> | No | Optional free-text services selected/typed by user. |
| `serviceIds` | prohibited | No | Do not send this on create. The create endpoint accepts `cleaning_services` strings instead. |
| `scheduledDate` | date | Yes | Must be today or future date in app timezone. |
| `scheduledTime` | `HH:mm` | Yes | Scheduled start time. |
| `addressId` | integer | No | Existing user address ID. |
| `addressLatitude` | number | No | Latitude. If `addressId` is used, saved address must contain coordinates. |
| `addressLongitude` | number | No | Longitude. If `addressId` is used, saved address must contain coordinates. |
| `neighborhoodId` | integer | No | Active cleaning neighborhood ID. Can be auto-filled from saved address. |
| `neighborhood` | string | No | Neighborhood name fallback. |
| `preferredWorkerIds` | array<int> | No | Worker IDs. More than one switches flow to `open_count`. |
| `preferredWorkerId` | integer | No | Preferred worker ID. |
| `assignmentMode` | string | No | `preferred_worker` or `open_count`. |
| `numberOfWorkers` | integer | No | 1 to 20. |
| `workerRoomAssignments` | array | No | Optional customer-planned room assignment payload. |
| `genderPreference` | string | No | `any`, `male`, `female`. |
| `workEnvironmentConfirmation` | object | Required only when `genderPreference = female` | Female-worker safety confirmation object. |
| `workEnvironmentConfirmation.beneficiaryPresence` | string | Required only for female worker | `female_present` or `male_alone`. `male_alone` blocks order creation. |
| `workEnvironmentConfirmation.pledgeAccepted` | boolean | Required only for female worker | Must be accepted/true. |
| `workEnvironmentConfirmation.pledgeVersion` | string | Required only for female worker | Must match current policy version returned by estimate response. |
| `cancellationPolicyId` | integer | No | Cancellation policy ID. Defaults to backend default when omitted. |
| `billingPolicyId` | integer | No | Cleaning billing policy ID. Defaults to backend default when omitted. |
| `termsAccepted` | boolean | Yes | Must be accepted/true. |

### Create Success Response `201`

```json
{
  "order": {
    "id": 101,
    "customerId": 55,
    "workerId": null,
    "preferredWorkerId": 1,
    "assignmentMode": "preferred_worker",
    "assignmentModeLabel": "Preferred worker",
    "numberOfWorkers": 1,
    "workerAcceptance": {
      "required": 1,
      "accepted": 0,
      "remaining": 1,
      "isFulfilled": false,
      "startApproved": 0,
      "notStartApproved": 1
    },
    "genderPreference": "any",
    "bookingNumber": "CL-20260630-0001",
    "displayTitle": "طلب تنظيف #CL-20260630-0001",
    "status": "pending",
    "statusLabel": "Pending",
    "order_status": "pending",
    "worker_order_status": "pending",
    "type": "cleaning",
    "required_workers_count": 1,
    "accepted_workers_count": 0,
    "pending_workers_count": 1,
    "start_approved_workers_count": 0,
    "not_start_approved_workers_count": 1,
    "propertyType": "villa",
    "propertyTypeLabel": "Villa",
    "propertyDetails": {},
    "cleaning_services": [
      "تنظيف النوافذ"
    ],
    "services": [
      {
        "id": null,
        "name": "تنظيف النوافذ",
        "quantity": 1,
        "unitPrice": null,
        "totalPrice": null,
        "sort": 0
      }
    ],
    "address": {
      "fullAddress": "حلب - الحمدانية - شارع القدس - بناء 12 - طابق 3",
      "locationName": "المنزل",
      "neighborhoodId": 3,
      "neighborhoodName": "الحمدانية",
      "latitude": 36.1795,
      "longitude": 37.1082
    },
    "estimatedSqm": 85,
    "estimatedHours": 4.5,
    "scheduledDate": "2026-07-01",
    "scheduledTime": "09:00",
    "totalHours": 4.5,
    "basePrice": 45000,
    "addonsTotal": 0,
    "extensionFeeTotal": 0,
    "travelFee": 10000,
    "deliveryFee": 10000,
    "travelDistanceKm": 7.2,
    "adminMargin": 6000,
    "isPricingFinal": true,
    "cancellationFee": 0,
    "totalPrice": 55000,
    "currency": "SYP",
    "termsAccepted": true,
    "workStartedAt": null,
    "workFinishedAt": null,
    "startedTravelAt": null,
    "arrivedAt": null,
    "customerConfirmedAt": null,
    "cancelledAt": null,
    "cancellationReason": null,
    "preferredWorker": {},
    "worker": null,
    "workerAssignments": [],
    "workerRoomAssignments": [],
    "roomAssignments": [],
    "myAssignment": null,
    "timeWarnings": [],
    "disputes": [],
    "createdAt": "2026-06-30 12:00:00",
    "updatedAt": "2026-06-30 12:00:00",
    "workTimer": {
      "expectedFinishAt": null,
      "remainingWorkSeconds": null,
      "overdueWorkSeconds": 0,
      "isWorkOverdue": false,
      "shouldShowWorkTimer": false
    }
  }
}
```

---

## 5. Important Order Response Props

### Identity and Assignment Props

| Prop | Type | Meaning |
|---|---|---|
| `id` | integer | Cleaning booking ID. Used in order lifecycle endpoints. |
| `bookingNumber` | string | Human-readable order number. |
| `customerId` | integer | Authenticated customer user ID. |
| `workerId` | integer/null | Main assigned worker. Null while order is pending/open. |
| `preferredWorkerId` | integer/null | Preferred worker selected by customer. |
| `assignmentMode` | string | `preferred_worker` or `open_count`. |
| `numberOfWorkers` | integer | Required team size. |
| `workerAcceptance.required` | integer | Required number of accepted workers. |
| `workerAcceptance.accepted` | integer | Number of workers who accepted. |
| `workerAcceptance.remaining` | integer | Workers still needed. |
| `workerAcceptance.isFulfilled` | boolean | True when accepted workers >= required workers. |
| `required_workers_count` | integer | Same idea as `workerAcceptance.required`; flat legacy prop. |
| `accepted_workers_count` | integer | Same idea as `workerAcceptance.accepted`; flat legacy prop. |
| `pending_workers_count` | integer | Workers still needed. |
| `start_approved_workers_count` | integer | Workers who approved start after customer code verification. |
| `not_start_approved_workers_count` | integer | Workers who accepted but have not approved start yet. |
| `workerAssignments` | array | Assignment rows per worker, including per-worker status. |
| `workerRoomAssignments` / `roomAssignments` | array | Room assignment planning/actual assignment payload. |
| `myAssignment` | object/null | Current authenticated worker assignment when the worker app calls the same resource. For user app it is usually null. |

### Status Props

| Prop | Type | Meaning |
|---|---|---|
| `status` | string | Canonical booking status. Use this for UI lifecycle. |
| `statusLabel` | string | Translated status label. |
| `order_status` | string | Backward-compatible duplicate of `status`. |
| `order_status_label` | string | Backward-compatible duplicate of `statusLabel`. |
| `worker_order_status` | string | Worker-specific display status when a worker has an assignment. |
| `worker_order_status_label` | string | Label for `worker_order_status`. |
| `type` | string | `cleaning` for normal property cleaning, `events` for `event_assistance`. |

### Property and Address Props

| Prop | Type | Meaning |
|---|---|---|
| `propertyType` | string | Original normalized property type. |
| `propertyTypeLabel` | string | Translated label. |
| `propertyDetails` / `property_details` | object | Normalized property details plus labels. |
| `cleaning_services` | array/null | Free-text cleaning services selected by user. |
| `services` | array | UI-friendly services list generated from `cleaning_services`. IDs and prices are null because create uses strings. |
| `neighborhoodId` | integer/null | Matched cleaning neighborhood ID. |
| `neighborhoodName` | string/null | Matched/fallback neighborhood name. |
| `address.fullAddress` | string/null | Address text. |
| `address.locationName` | string/null | User label/location name. |
| `address.latitude` / `address.longitude` | number/null | Coordinates. |
| `addressLatitude` / `addressLongitude` | number/null | Flat coordinate fields. |

### Pricing Props

| Prop | Type | Meaning |
|---|---|---|
| `estimatedSqm` | number | Estimated area. |
| `estimatedHours` | number | Estimated work hours. |
| `totalHours` | number | Stored total work hours. |
| `basePrice` / `servicePrice` / `service_price` | number | Base cleaning/event price. |
| `addonsTotal` | number | Add-on total. |
| `extensionFeeTotal` | number | Accepted time extension fee total. |
| `travelFee` / `deliveryFee` | number | Worker travel/delivery fee. |
| `travelDistanceKm` | number/null | Worker travel distance. |
| `adminMargin` | number | Admin margin. |
| `isPricingFinal` | boolean | True when travel/margin has been finalized. |
| `cancellationFee` | number | Applied cancellation fee. |
| `totalPrice` | number | Final/stored price. |
| `currency` | string | Currency. |
| `extendedTimeRanges` | array | Available extension ranges/prices. |

### Lifecycle Date Props

| Prop | Type | Meaning |
|---|---|---|
| `scheduledDate` | string | `YYYY-MM-DD`. |
| `scheduledTime` | string | `HH:mm`. |
| `startedTravelAt` | datetime/null | Worker tapped start travel. |
| `arrivedAt` | datetime/null | Worker arrived and order is waiting for security-code verification. |
| `customerConfirmedAt` | datetime/null | Customer confirmed start or completion depending on lifecycle step. |
| `workStartedAt` | datetime/null | Work officially started. |
| `workFinishedAt` | datetime/null | Worker requested completion or finished. |
| `cancelledAt` | datetime/null | Cancellation time. |
| `createdAt` / `updatedAt` | datetime | Audit fields. |

### Work Timer Props

| Prop | Type | Meaning |
|---|---|---|
| `workTimer.expectedFinishAt` | datetime/null | Expected finish time derived from `workStartedAt + totalHours`. |
| `workTimer.remainingWorkSeconds` | integer/null | Remaining seconds while work is active. |
| `workTimer.overdueWorkSeconds` | integer | Overdue seconds if expected finish passed. |
| `workTimer.isWorkOverdue` | boolean | True if active work is overdue. |
| `workTimer.shouldShowWorkTimer` | boolean | True when UI should show the timer. |

---

## 6. Order Lifecycle Scenarios

### Status Values

| Status | Meaning | Typical Actor |
|---|---|---|
| `pending` | Order created and waiting for worker acceptance. | User creates order; workers see request. |
| `worker_assigned` | Required worker count is fulfilled; worker(s) assigned. | Worker accepts. |
| `awaiting_start_verification` | Worker arrived and customer must verify the start code. | Worker arrives; customer sees verification UI. |
| `awaiting_worker_start_confirmation` | Customer verified the code; now worker(s) must confirm/approve start. | Customer confirms code; worker app approves start. |
| `in_progress` | Work has started. | Worker start approval fulfilled. |
| `awaiting_customer_completion` | Worker marked completion; customer must confirm/reject/extend. | Worker requests completion. |
| `time_extension_requested` | Customer asked to extend time. Worker/admin response pending. | Customer extension flow. |
| `under_dispute` | Worker requested admin review instead of normal finish. | Worker finish review/dispute path. |
| `completed` | Order completed and finalized. | Customer confirms completion or worker finish success path. |
| `cancelled` | Order cancelled. | Customer/worker/system cancellation. |

### Normal Preferred Worker Flow

1. User calls `POST /cleaning/orders/estimate-price` with `preferredWorkerId` and `assignmentMode = preferred_worker`.
2. Backend returns `assignmentMode = preferred_worker`, `workerAcceptance.required = 1`, and pricing can be final because worker-specific travel fee/margin can be calculated.
3. User calls `POST /cleaning/orders`.
4. Created order starts as `pending`.
5. Preferred worker accepts.
6. Status becomes `worker_assigned`.
7. Worker starts travel. Status remains `worker_assigned`, but `startedTravelAt` is set.
8. Worker arrives. Status becomes `awaiting_start_verification`, and `arrivedAt` is set.
9. Customer confirms security code. Status becomes `awaiting_worker_start_confirmation`.
10. Worker approves start. Status becomes `in_progress`, and `workStartedAt` is set.
11. Worker marks completion. Status becomes `awaiting_customer_completion`, and `workFinishedAt` is set.
12. Customer confirms completion. Status becomes `completed`.

### Open Count / Multi-Worker Flow

1. User calls estimate/create with `assignmentMode = open_count` and `numberOfWorkers > 1`.
2. Created order starts as `pending`.
3. Each worker acceptance updates `workerAssignments`.
4. While accepted count is less than required count:
   - `status = pending`
   - `workerAcceptance.accepted` increases
   - `workerAcceptance.remaining` decreases
   - `workerAcceptance.isFulfilled = false`
5. When accepted count reaches `numberOfWorkers`:
   - `status = worker_assigned`
   - `workerAcceptance.isFulfilled = true`
6. At start verification:
   - Customer verifies code once.
   - Status becomes `awaiting_worker_start_confirmation`.
   - Every accepted assignment moves toward start approval.
7. Each accepted worker approves start.
8. When `start_approved_workers_count >= required_workers_count`, order becomes `in_progress`.
9. Completion flow then follows the normal path.

### Female Worker Flow

1. User calls estimate with `genderPreference = female`.
2. Backend returns `workEnvironmentConfirmation.required = true` plus policy data.
3. Flutter must show the confirmation UI.
4. If user selects `male_alone`, order creation must be blocked. If sent anyway, backend returns validation error on `workEnvironmentConfirmation.beneficiaryPresence`.
5. If user selects `female_present`, accepts pledge, and sends matching `pledgeVersion`, order can be created.
6. Missing/false `pledgeAccepted` returns validation error.
7. Wrong pledge version returns validation error asking the app to refresh the confirmation screen.

### Event Assistance Flow

1. Use `propertyType = event_assistance`.
2. `propertyDetails.eventType`, `guestCount`, `venueType`, `customService`, and `hours` are required.
3. `serviceIds` are prohibited.
4. `workerRoomAssignments` are ignored/not used for event assistance.
5. Estimate uses event hourly pricing and event hours.
6. Response `type` becomes `events` after order creation.
7. Lifecycle statuses remain the same as cleaning orders.

### Cancellation Flow

1. Customer can cancel only when status is `pending` or `worker_assigned`.
2. Backend sets:
   - `status = cancelled`
   - `cancelledAt`
   - `cancellationReason`
3. Cancel is rejected for statuses such as `in_progress`, `completed`, or `cancelled`.

### Completion Decision Flow

When status is `awaiting_customer_completion`, customer has three options:

| Customer Action | Result |
|---|---|
| Confirm completion | Status becomes `completed`; worker admin fees can be debited/finalized. |
| Reject completion | Status returns to `in_progress`; `workFinishedAt` is cleared. |
| Request time extension | Status becomes `time_extension_requested`; extension pricing is quoted from `extendedTimeRanges`. |

---

## 7. Recommended Flutter Integration

### Create Order Flow

1. Collect property details.
2. Call `POST /cleaning/orders/estimate-price`.
3. Display:
   - size estimate
   - price
   - worker count requirement
   - worker room assignments preview
   - female worker confirmation if required
4. If `genderPreference = female`, require the user to complete the safety confirmation.
5. Call `POST /cleaning/orders` using user input, not price fields.
6. Use returned `order.status` and `workerAcceptance` to drive the order tracking UI.

### Do Not Send These Fields on Create

These fields are calculated by backend and are explicitly prohibited:

```json
{
  "estimatedSqm": 85,
  "estimatedHours": 4.5,
  "totalHours": 4.5,
  "basePrice": 45000,
  "travelFee": 10000,
  "addonsTotal": 0,
  "totalPrice": 55000
}
```

### Main UI Conditions

| UI Condition | Backend Signal |
|---|---|
| Waiting for workers | `status = pending`, `workerAcceptance.remaining > 0` |
| Worker(s) assigned | `status = worker_assigned` |
| Show worker tracking | `status = worker_assigned` and `startedTravelAt != null` |
| Show start verification code screen | `status = awaiting_start_verification` |
| Waiting for worker start approval | `status = awaiting_worker_start_confirmation` |
| Show active work timer | `workTimer.shouldShowWorkTimer = true` |
| Show completion confirmation actions | `status = awaiting_customer_completion` |
| Show extension pending state | `status = time_extension_requested` |
| Show completed state | `status = completed` |
| Show cancelled state | `status = cancelled` |

---

## 8. Source Files Used

- `Modules/User/routes/api.php`
- `Modules/User/app/Http/Controllers/API/UserCleaningPreviousWorkersController.php`
- `Modules/User/app/Http/Requests/UserCleaningPreviousWorkersRequest.php`
- `Modules/User/app/Http/Controllers/API/UserCleaningOrderEstimatePriceController.php`
- `Modules/User/app/Http/Requests/UserCleaningOrderEstimatePriceRequest.php`
- `Modules/User/app/Http/Controllers/API/UserCleaningOrderStoreController.php`
- `Modules/User/app/Http/Requests/UserCleaningOrderStoreRequest.php`
- `Modules/User/app/Services/UserCleaningOrderService.php`
- `Modules/User/app/Services/UserCleaningOrderEstimationService.php`
- `Modules/Cleaning/app/Http/Resources/CleaningBookingResource.php`
- `Modules/Cleaning/app/Enums/CleaningBookingStatus.php`
- `Modules/Cleaning/app/Enums/CleaningBookingWorkerAssignmentStatus.php`
- `Modules/Cleaning/app/Services/CleaningBookingService.php`
