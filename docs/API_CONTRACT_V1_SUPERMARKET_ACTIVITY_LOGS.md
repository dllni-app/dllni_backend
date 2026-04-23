# API contract: supermarket owner activity logs (`/api/v1/store-owner/activity-logs`)


**Base URL:** `{baseUrl}/api` (example: `http://Dllni.test/api`)

**Full path:** `/v1/store-owner/activity-logs`

**Method:** `GET`

**Authentication:** `Authorization: Bearer {token}` (Laravel Sanctum)

**Content type:** `application/json`

---

## Overview

This endpoint returns paginated activity log records scoped to the authenticated store owner's store.

Backend implementation details:

- Controller: `Modules/Supermarket/app/Http/Controllers/API/StoreOwner/StoreOwnerActivityLogController.php`
- Resource transformer: `app/Http/Resources/ActivityLogResource.php`
- Source model: `Spatie\Activitylog\Models\Activity`


---

## Request

### HTTP request

```http
GET /api/v1/store-owner/activity-logs?logName=orders&perPage=15
Authorization: Bearer {token}
Accept: application/json
```

### Query parameters

| Query key | Type | Required | Validation | Default | Notes |
| --- | --- | --- | --- | --- | --- |
| `logName` | string | No | Must be one of: `products`, `offers`, `orders`, `inventory`, `system` | `null` (no filter) | Filters by `activity_log.log_name`. |
| `perPage` | integer | No | `min:1`, `max:100` | `15` | Number of records per page. |
| `page` | integer | No | Laravel paginator standard | `1` | Page index. |

---

## Response

### Success response

| Status | Meaning |
| --- | --- |
| `200 OK` | Activity logs returned successfully. |

The endpoint returns Laravel paginator JSON:

- `data` (array of activity log items)
- `links` (pagination links)
- `meta` (pagination metadata)

### Activity log item schema (`data[]`)

Serialized by `ActivityLogResource`.

| Field | Type | Nullable | Description |
| --- | --- | --- | --- |
| `id` | integer | No | Activity log row id. |
| `description` | string | Yes | Human-readable activity description. |
| `event` | string | Yes | Event name (for example `created`, `updated`, `deleted`). |
| `logName` | string | Yes | Activity group/category (`products`, `offers`, `orders`, `inventory`, `system`, ...). |
| `causer` | object | Yes | Actor user object if available. |
| `causer.id` | integer | No (when `causer` exists) | User id of action actor. |
| `causer.name` | string | Yes | Actor name. |
| `causer.avatarUrl` | string (URL) | Yes | Actor avatar URL. |
| `subjectType` | string | Yes | Fully-qualified class name of subject model. |
| `subjectId` | integer \| string | Yes | Subject model key. |
| `properties` | object | Yes | Extra metadata JSON saved with the activity record. |
| `createdAt` | string (`Y-m-d H:i:s`) | Yes | Creation timestamp in server datetime string format. |

---

## Example success response

```json
{
  "data": [
    {
      "id": 9321,
      "description": "Order accepted by store owner",
      "event": "updated",
      "logName": "orders",
      "causer": {
        "id": 77,
        "name": "Store Owner",
        "avatarUrl": "https://Dllni.test/storage/avatars/77.png"
      },
      "subjectType": "Modules\\Supermarket\\app\\Models\\SmOrder",
      "subjectId": 4512,
      "properties": {
        "store_id": 14,
        "order_id": 4512,
        "old_status": "pending",
        "new_status": "accepted"
      },
      "createdAt": "2026-04-23 11:18:42"
    }
  ],
  "links": {
    "first": "http://Dllni.test/api/v1/store-owner/activity-logs?page=1",
    "last": "http://Dllni.test/api/v1/store-owner/activity-logs?page=3",
    "prev": null,
    "next": "http://Dllni.test/api/v1/store-owner/activity-logs?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 3,
    "links": [
      {
        "url": null,
        "label": "&laquo; Previous",
        "active": false
      },
      {
        "url": "http://Dllni.test/api/v1/store-owner/activity-logs?page=1",
        "label": "1",
        "active": true
      },
      {
        "url": "http://Dllni.test/api/v1/store-owner/activity-logs?page=2",
        "label": "2",
        "active": false
      },
      {
        "url": "http://Dllni.test/api/v1/store-owner/activity-logs?page=3",
        "label": "3",
        "active": false
      },
      {
        "url": "http://Dllni.test/api/v1/store-owner/activity-logs?page=2",
        "label": "Next &raquo;",
        "active": false
      }
    ],
    "path": "http://Dllni.test/api/v1/store-owner/activity-logs",
    "per_page": 15,
    "to": 15,
    "total": 45
  }
}
```

---

## Error responses

### `401 Unauthorized`

Returned when no valid Sanctum token is provided.

Example:

```json
{
  "message": "Unauthenticated."
}
```

### `422 Unprocessable Entity`

Returned when validation fails for query params.

Possible cases:

- `logName` is not one of allowed enum values.
- `perPage < 1` or `perPage > 100`.
- `perPage` is not an integer.

Example:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "logName": [
      "The selected log name is invalid."
    ],
    "perPage": [
      "The per page must be between 1 and 100."
    ]
  }
}
```

---

## Business and integration notes

- Use `logName` tabs/chips in UI to segment feed by domain (`orders`, `inventory`, etc.).
- Keep `perPage` between `10` and `25` for smooth mobile pagination.
- `properties` keys are event-dependent and should be treated as dynamic payload.
- Sort order is newest first (`created_at DESC`).

---

## cURL examples

### List all activity logs (default pagination)

```bash
curl --request GET \
  --url '{baseUrl}/api/v1/store-owner/activity-logs' \
  --header 'Accept: application/json' \
  --header 'Authorization: Bearer {token}'
```

### Filter only order logs with custom page size

```bash
curl --request GET \
  --url '{baseUrl}/api/v1/store-owner/activity-logs?logName=orders&perPage=20' \
  --header 'Accept: application/json' \
  --header 'Authorization: Bearer {token}'
```
