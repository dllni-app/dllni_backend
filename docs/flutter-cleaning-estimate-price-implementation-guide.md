# Flutter Implementation Guide: Cleaning Estimate-Price + Create Order

Date: June 10, 2026  
Audience: `dllni-user-app` team  
Scope: `POST /api/v1/user/cleaning/orders/estimate-price` and `POST /api/v1/user/cleaning/orders`

---

## Executive summary

| Area | Status | Owner |
|------|--------|-------|
| Backend estimate-price algorithm | **Solved** | Backend |
| Multi-worker fields (`assignmentMode`, `numberOfWorkers`, `workerRoomAssignments`) | **Solved** | Backend + Flutter (mostly aligned) |
| Deep/regular cleaning on **estimate** | **Solved in Flutter** | Flutter already sends `propertyDetails.cleaning_mode` |
| Deep/regular cleaning on **create order** | **Not solved** | **Flutter bug** (wrong field name/shape) |
| `corridor` room bucket in `room_size_breakdown` | **Not solved** | **Backend missing** + **Flutter sends unsupported key** |
| Worker assignment for `corridor` rooms | **Not solved** | Backend does not derive `corridor.*` room keys |

**Bottom line:** the backend endpoint is implemented and tested (37 passing tests in `UserCleaningOrdersFlowTest`). The live Flutter home-cleaning flow still breaks when it sends `room_size_breakdown` because Flutter always includes `corridor`, which the backend validator rejects with `422`.

---

## Verification performed

Backend tests were run successfully:

```bash
php artisan test --filter=UserCleaningOrdersFlowTest
# 37 passed
```

Additional payload probes matching current Flutter request builders:

1. Flutter-like payload with `room_size_breakdown.corridor` → **422** (`propertyDetails.room_size_breakdown`)
2. Flutter-like create payload with top-level `cleaningType: deep_cleaning` (no `propertyDetails.cleaning_mode`) → **201**, but persisted `cleaning_mode` = **`regular`** (deep selection lost)

---

## Backend contract (source of truth)

### Endpoints

| Method | Path | Auth |
|--------|------|------|
| `POST` | `/api/v1/user/cleaning/orders/estimate-price` | Bearer token |
| `POST` | `/api/v1/user/cleaning/orders` | Bearer token |

### Supported `propertyDetails.room_size_breakdown` room types

Backend currently allows **only**:

- `bedroom`
- `bathroom`
- `kitchen`
- `living_room`
- `balcony`

`corridor` is **not** accepted. Any extra key in `room_size_breakdown` fails validation.

Relevant backend files:

- `Dllni_backend/Modules/User/app/Http/Requests/UserCleaningOrderEstimatePriceRequest.php`
- `Dllni_backend/Modules/User/app/Http/Requests/UserCleaningOrderStoreRequest.php`
- `Dllni_backend/Modules/Cleaning/app/Support/WorkerRoomAssignmentPlanner.php` (`ROOM_TYPE_ORDER` has no `corridor`)

### Cleaning type field

Backend expects:

```json
"propertyDetails": {
  "cleaning_mode": "regular"
}
```

Allowed values: `regular`, `deep`

Backend does **not** read top-level `cleaningType` (`deep_cleaning` / `regular_cleaning`).

### Multi-worker fields (additive)

```json
{
  "assignmentMode": "open_count",
  "numberOfWorkers": 2,
  "workerRoomAssignments": [
    {
      "workerSlot": 1,
      "preferredWorkerId": null,
      "rooms": [
        { "roomKey": "bedroom.small.1", "roomType": "bedroom", "roomSize": "small" }
      ]
    }
  ]
}
```

Rules:

- `preferred_worker` → exactly 1 worker; `preferredWorkerId` required for finalized travel pricing
- `open_count` → `numberOfWorkers >= 1`; do not send `preferredWorkerId`
- `roomKey` must exist in backend-derived room units from `room_size_breakdown`

### Estimate-price response shape

```json
{
  "size": {
    "estimatedSqm": 115,
    "estimatedHours": 4,
    "sizeTier": "medium"
  },
  "pricing": {
    "basePrice": 920,
    "travelFee": 0,
    "addonsTotal": 0,
    "totalPrice": 920,
    "distanceKm": null,
    "adminMargin": 0,
    "isPricingFinal": false,
    "currency": "SYP",
    "serviceLines": []
  },
  "assignmentMode": "open_count",
  "workerAcceptance": {
    "required": 2,
    "accepted": 0,
    "remaining": 2,
    "isFulfilled": false
  },
  "recommendation": null,
  "workerRoomAssignments": [],
  "algorithmVersion": "2026-06-03-v3"
}
```

Flutter parser in `estimate_price_response_model.dart` already supports camelCase and snake_case aliases.

---

## Current Flutter behavior vs backend

### What Flutter does correctly today

| Item | Flutter file | Notes |
|------|--------------|-------|
| Estimate endpoint path | `cl_main_remote_data_source.dart` | `/api/v1/user/cleaning/orders/estimate-price` |
| Estimate cleaning mode | `estimate_cleaning_price_use_case.dart` | Sends `propertyDetails.cleaning_mode` (`deep` / `regular`) |
| Assignment mode values | `cleaning_assignment_mode.dart` | `preferred_worker` / `open_count` |
| Worker room assignments request | `cl_worker_room_assignment.dart` | Correct `workerSlot`, `preferredWorkerId`, `rooms[]` |
| Response parsing | `estimate_price_response_model.dart` | Parses `workerRoomAssignments`, pricing, recommendation |

### What breaks today

#### 1) `corridor` always sent in breakdown (**blocking**)

`CleaningRoomSizeBreakdown.toJson()` always serializes all room types including `corridor`, even when counts are zero.

Because backend validation is strict (`array:bedroom,bathroom,kitchen,living_room,balcony`), requests fail with:

```json
{
  "message": "validation.array",
  "errors": {
    "propertyDetails.room_size_breakdown": ["validation.array"]
  }
}
```

Impact:

- Home cleaning estimate fails once `room_size_breakdown` is included
- Create order fails for the same reason
- Worker assignments referencing `corridor.*` room keys will also fail even after corridor omission, until backend adds corridor support

**Classification:** backend feature gap + Flutter must stop sending unsupported keys until backend ships corridor support.

#### 2) Create order uses wrong cleaning field (**functional bug**)

| Flow | Current Flutter payload | Backend expects |
|------|------------------------|-----------------|
| Estimate | `propertyDetails.cleaning_mode: deep` | OK |
| Create | top-level `cleaningType: deep_cleaning` | `propertyDetails.cleaning_mode: deep` |

`CreateCleaningOrderParams._buildPropertyDetails()` does not include `cleaning_mode`.

Result: user selects deep cleaning, estimate price is deep, but created order is stored as `regular`.

**Classification:** Flutter bug.

---

## Required Flutter changes (do not ship backend workarounds)

### Change 1 — Strip unsupported room types from breakdown JSON

**Files:**

- `lib/features/cl_main/domain/models/cleaning_room_size_breakdown.dart`
- `lib/features/cl_main/domain/models/cl_worker_room_assignment.dart`

**Implement:**

1. Introduce a backend-supported room type list without `corridor` for API payloads.
2. Update `toJson()` to include only supported room types **with total > 0** (recommended), or at minimum omit `corridor` until backend supports it.
3. Update `enumerateRoomUnits()` used for API assignment payloads to exclude `corridor` from request generation (UI may still show corridor locally).

Suggested helper:

```dart
const backendAcceptedRoomSizeBreakdownKeys = <CleaningRoomType>[
  CleaningRoomType.bedroom,
  CleaningRoomType.bathroom,
  CleaningRoomType.kitchen,
  CleaningRoomType.livingRoom,
  CleaningRoomType.balcony,
];

Map<String, dynamic> toBackendJson() {
  final json = <String, dynamic>{};
  for (final roomType in backendAcceptedRoomSizeBreakdownKeys) {
    final bucket = bucketFor(roomType);
    if (bucket.total > 0) {
      json[roomType.apiKey] = bucket.toJson();
    }
  }
  return json;
}
```

Use `toBackendJson()` in:

- `EstimateCleaningPriceParams._buildPropertyDetails()`
- `CreateCleaningOrderParams._buildPropertyDetails()`

**UI note:** keep corridor visible in the picker, but do not send it to API yet. Optionally show a non-blocking banner: "الموزع سيتم دعمه قريباً في التسعير".

### Change 2 — Align create-order cleaning type with estimate

**File:** `lib/features/cl_main/domain/usecases/create_cleaning_order_use_case.dart`

Mirror estimate behavior:

```dart
// Remove top-level cleaningType from getBody()
// Add inside propertyDetails for non-event flows:
if (cleaningType != null) 'cleaning_mode': cleaningType!.cleaningModeValue,
```

Mapping (already exists in `CleaningTypeX`):

| UI enum | API value |
|---------|-----------|
| `CleaningType.regularCleaning` | `regular` |
| `CleaningType.deepCleaning` | `deep` |

Ensure schedule screen passes the same `args.cleaningType` used during estimate (already wired in `cl_main_service_schedule_screen.dart`).

### Change 3 — Keep estimate/create payloads consistent

For the same booking session, these fields must match between estimate and create:

- `propertyType`
- `propertyDetails` (including `room_size_breakdown`, `cleaning_mode`)
- `addressLatitude` / `addressLongitude`
- `assignmentMode`
- `numberOfWorkers`
- `preferredWorkerId`
- `serviceIds` (if any)
- `workerRoomAssignments`

Backend test `creates a cleaning order with totals matching a prior estimate for the same inputs` enforces this parity.

### Change 4 — Handle new estimate response fields in UI (optional but recommended)

Backend returns additive team preview fields:

- `assignmentMode`
- `workerAcceptance`
- `workerRoomAssignments` (with `roomsWeight`, `estimatedServiceShareAmount`)

Flutter already parses them. Recommended UI usage on schedule step:

- Show required team size from `workerAcceptance.required`
- If assignments returned, prefer backend-normalized `workerRoomAssignments` when building create payload (already done in `cl_main_service_schedule_screen.dart`)

---

## Request examples Flutter should send

### A) Regular home cleaning estimate (no corridor yet)

```json
{
  "propertyType": "apartment",
  "propertyDetails": {
    "bedrooms": 4,
    "rooms": 1,
    "bathrooms": 1,
    "balconies": 0,
    "living_room_size": "medium",
    "cleaning_mode": "regular",
    "room_size_breakdown": {
      "bedroom": { "small": 1, "medium": 0, "large": 0 },
      "bathroom": { "small": 1, "medium": 0, "large": 0 },
      "kitchen": { "small": 0, "medium": 1, "large": 0 },
      "living_room": { "small": 0, "medium": 1, "large": 0 }
    }
  },
  "addressLatitude": 33.5138,
  "addressLongitude": 36.2765,
  "assignmentMode": "open_count",
  "numberOfWorkers": 2,
  "workerRoomAssignments": [
    {
      "workerSlot": 1,
      "preferredWorkerId": null,
      "rooms": [
        { "roomKey": "bedroom.small.1", "roomType": "bedroom", "roomSize": "small" },
        { "roomKey": "bathroom.small.1", "roomType": "bathroom", "roomSize": "small" }
      ]
    },
    {
      "workerSlot": 2,
      "preferredWorkerId": null,
      "rooms": [
        { "roomKey": "kitchen.medium.1", "roomType": "kitchen", "roomSize": "medium" },
        { "roomKey": "living_room.medium.1", "roomType": "living_room", "roomSize": "medium" }
      ]
    }
  ]
}
```

### B) Matching create order payload

Same property/team fields as estimate, plus:

```json
{
  "propertyDetails": {
    "address": "دمشق - المزة",
    "location_name": "المنزل",
    "bedrooms": 4,
    "rooms": 1,
    "bathrooms": 1,
    "living_room_size": "medium",
    "cleaning_mode": "regular",
    "room_size_breakdown": { }
  },
  "scheduledDate": "2026-06-11",
  "scheduledTime": "09:00",
  "genderPreference": "any",
  "termsAccepted": true
}
```

### C) Event assistance estimate (unchanged)

```json
{
  "propertyType": "event_assistance",
  "propertyDetails": {
    "eventType": "family_dinner",
    "guestCount": 20,
    "venueType": "apartment",
    "specialRequirement": "تجهيز طاولات",
    "notes": "الوصول قبل ساعة"
  },
  "serviceIds": [12, 15],
  "assignmentMode": "open_count",
  "numberOfWorkers": 3
}
```

Do not send `room_size_breakdown`, `cleaning_mode`, or `workerRoomAssignments` for event assistance.

---

## Tests Flutter team should update

| Test file | Update |
|-----------|--------|
| `test/features/cl_main/domain/usecases/estimate_cleaning_price_use_case_test.dart` | Expect `corridor` omitted from API JSON (or only when backend supports it) |
| `test/features/cl_main/domain/usecases/create_cleaning_order_use_case_test.dart` | Expect `propertyDetails.cleaning_mode`, not top-level `cleaningType` |
| `test/features/cl_main/data/models/estimate_price_response_model_test.dart` | Keep parsing coverage for `workerRoomAssignments` |
| Add integration test | estimate → create parity for deep cleaning totals |

---

## Backend follow-up (not Flutter)

Track separately with backend team:

1. Add `corridor` to request validation (`EstimatePrice`, `Store`, `Update`)
2. Include `corridor` in sqm/hours pricing in `UserCleaningOrderEstimationService`
3. Add `corridor` to `WorkerRoomAssignmentPlanner::ROOM_TYPE_ORDER`
4. Add Pest tests for corridor breakdown + corridor room assignments

Until those ship, Flutter must not send `corridor` in API payloads.

---

## Implementation checklist for Flutter

- [ ] Add `toBackendJson()` (or equivalent) and use it in estimate/create params
- [ ] Exclude `corridor` from `enumerateRoomUnits()` for API assignment payloads
- [ ] Send `propertyDetails.cleaning_mode` on create order; remove top-level `cleaningType`
- [ ] Verify estimate and create bodies are identical for pricing-related fields
- [ ] Update unit tests listed above
- [ ] Manual QA: home flow with rooms selected, deep cleaning selected, team mode 2 workers
- [ ] Manual QA: preferred worker mode with finalized travel pricing when worker selected

---

## Quick diagnosis matrix

| Symptom | Likely cause | Fix side |
|---------|--------------|----------|
| `422` on `propertyDetails.room_size_breakdown` | Flutter sent `corridor` | Flutter now; backend later |
| Estimate works, order saved as regular after choosing deep | Create sends `cleaningType` not `cleaning_mode` | Flutter |
| `422` on `workerRoomAssignments.*.rooms.*.roomKey` | Room key not in backend breakdown (often `corridor.*`) | Flutter omit corridor assignments |
| `422` on `propertyDetails.cleaning_mode` | Sent `deep_cleaning` instead of `deep` | Flutter |
| Price differs between estimate and create with same inputs | Payload mismatch between steps | Flutter parity fix |

---

## References

- Backend contract: `Dllni_backend/docs/API_CONTRACT_USER_CLEANING_MULTI_WORKER_ROOMS.md`
- Backend tests: `Dllni_backend/tests/Feature/UserModule/UserCleaningOrdersFlowTest.php`
- Flutter estimate params: `lib/features/cl_main/domain/usecases/estimate_cleaning_price_use_case.dart`
- Flutter create params: `lib/features/cl_main/domain/usecases/create_cleaning_order_use_case.dart`
- Flutter remote call: `lib/features/cl_main/data/source/cl_main_remote_data_source.dart`
