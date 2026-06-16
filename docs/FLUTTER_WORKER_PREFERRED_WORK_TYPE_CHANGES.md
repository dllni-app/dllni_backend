# Flutter Worker Preferred Work Type Changes

## 1. Summary

Workers now have a preferred work type setting:

- Cleaning only
- Events only
- Both

The backend uses this setting when returning available pending orders to the authenticated worker. Existing workers default to `both`.

## 2. New Field

Worker profile responses now include:

```json
{
  "preferred_work_type": "both"
}
```

Allowed values:

```txt
cleaning
events
both
```

Meaning:

- `cleaning`: show cleaning orders only.
- `events`: show event assistance orders only.
- `both`: show cleaning and event assistance orders.

## 3. Profile Update Request

Endpoint:

```txt
PUT /api/v1/cleaning/worker/account/profile
```

Flutter can send the field by itself or with the existing profile fields:

```json
{
  "preferred_work_type": "cleaning"
}
```

Invalid values are rejected with normal validation errors.

## 4. Profile Response

Endpoints:

```txt
GET /api/v1/cleaning/worker/profile
GET /api/v1/cleaning/worker/account/profile
PUT /api/v1/cleaning/worker/account/profile
```

Response shape remains the existing `WorkerResource` shape. New field:

```json
{
  "data": {
    "id": 1,
    "userId": 10,
    "firstName": "Worker Name",
    "gender": "female",
    "preferred_work_type": "both",
    "avatar": null,
    "bio": null,
    "averageRating": 0,
    "totalCompletedJobs": 0,
    "trustScore": 100,
    "acceptanceRate": 0,
    "cancellationRate": 0,
    "openDisputesCount": 0,
    "isActive": true,
    "isSuspended": false,
    "suspendedUntil": null,
    "homeAddress": "Damascus",
    "homeLatitude": 33.5138,
    "homeLongitude": 36.2765,
    "defaultWorkingHours": {},
    "user": {
      "id": 10,
      "name": "Worker Name",
      "email": "worker@example.com",
      "phone": "+963991234567"
    },
    "createdAt": "2026-06-11 21:40:00",
    "updatedAt": "2026-06-11 21:40:00"
  }
}
```

Login/me payloads that include the loaded worker relationship also expose `workerPreferredWorkType` on the user resource.

## 5. Available Orders Behavior

Endpoint:

```txt
GET /api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[status]=pending
```

Behavior:

- If the worker selects `cleaning`, Flutter should expect only cleaning orders.
- If the worker selects `events`, Flutter should expect only event assistance orders.
- If the worker selects `both`, Flutter should expect both cleaning and event assistance orders.

The existing filters, pagination, sorting, status checks, gender preference checks, rejection checks, and assigned/accepted worker checks remain in place.

Mixed order rows now include a `type` field:

```json
{
  "id": 15,
  "type": "cleaning",
  "propertyType": "apartment",
  "status": "pending"
}
```

```json
{
  "id": 22,
  "type": "events",
  "propertyType": "event_assistance",
  "status": "pending"
}
```

## 6. UI Changes Needed in Flutter

Add a select/radio group in worker profile/settings:

- Cleaning only -> `cleaning`
- Events only -> `events`
- Both -> `both`

Default selected value should be `both`.

When updating the profile, send `preferred_work_type` with the selected value.

## 7. Backward Compatibility

- Existing workers default to `both` after migration.
- Flutter should handle missing `preferred_work_type` as `both` for old app versions or cached profile data.
- Existing order response fields remain unchanged; `type` is additive.
