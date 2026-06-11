# SOS Flow API Contract (Flutter)

## Scope
This contract documents the currently implemented SOS API endpoints in `Dllni_backend` for Flutter clients.

- Base URL: `{{BASE_URL}}/api/v1`
- Auth: `Bearer <sanctum_token>` required for all endpoints below
- Content-Type: `application/json`

## Endpoints

| Method | Path | Purpose |
|---|---|---|
| POST | `/user/sos` | Create a user SOS for a restaurant order |
| GET | `/sos-alerts` | List SOS alerts (paginated) |
| GET | `/sos-alerts/{id}` | Get single SOS alert details |

## 1) Create User SOS

### Request
`POST /api/v1/user/sos`

Compatibility alias: `POST /api/user/sos`

### Body
```json
{
  "order_id": 1,
  "message": "The worker did not arrive and I need urgent help."
}
```

### Validation
| Field | Type | Required | Notes |
|---|---|---|---|
| `order_id` | integer | Yes | Must exist in `orders.id` and belong to the authenticated user |
| `message` | string | Yes | Trimmed, non-empty, max `1000` characters |

### Success Response (201)
```json
{
  "success": true,
  "message": "SOS request sent successfully.",
  "data": {
    "id": 1,
    "order_id": 1,
    "message": "The worker did not arrive and I need urgent help.",
    "status": "pending",
    "created_at": "2026-06-11T00:00:00.000000Z"
  }
}
```

### Error Cases
- `401 Unauthorized`: Missing/invalid token
- `403 Forbidden`: The order exists but does not belong to the authenticated user
- `422 Unprocessable Entity`: Missing/invalid `order_id` or empty/too-long `message`

## 2) List SOS Alerts

### Request
`GET /api/v1/sos-alerts`

### Query Params
| Param | Type | Required | Notes |
|---|---|---|---|
| `perPage` | integer | No | Range: `1..100`, default pagination size from backend is `10` |
| `filter[status]` | string | No | `pending` \| `triggered` \| `acknowledged` \| `resolved` |
| `filter[emergencyType]` | string | No | `safety_threat` \| `medical_emergency` \| `severe_conflict` |
| `filter[source]` | string | No | `booking` \| `user` |
| `sort` | string | No | `triggeredAt` \| `-triggeredAt` \| `createdAt` \| `-createdAt` |

### Success Response (200)
```json
{
  "data": [
    {
      "id": 15,
      "userId": null,
      "orderId": null,
      "bookingId": 987,
      "bookingType": "Modules\\Cleaning\\app\\Models\\CleaningBooking",
      "emergencyType": "medical_emergency",
      "message": null,
      "source": "booking",
      "status": "triggered",
      "latitude": 33.5138,
      "longitude": 36.2765,
      "triggeredAt": "2026-06-01 10:35:22",
      "resolvedAt": null,
      "booking": {
        "id": 987
      },
      "createdAt": "2026-06-01 10:35:22",
      "updatedAt": "2026-06-01 10:35:22"
    }
  ],
  "links": {
    "first": "https://example.com/api/v1/sos-alerts?page=1",
    "last": "https://example.com/api/v1/sos-alerts?page=3",
    "prev": null,
    "next": "https://example.com/api/v1/sos-alerts?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 3,
    "path": "https://example.com/api/v1/sos-alerts",
    "per_page": 10,
    "to": 10,
    "total": 21
  }
}
```

## 3) Show SOS Alert

### Request
`GET /api/v1/sos-alerts/{id}`

### Success Response (200)
```json
{
  "data": {
    "id": 15,
    "userId": null,
    "orderId": null,
    "bookingId": 987,
    "bookingType": "Modules\\Cleaning\\app\\Models\\CleaningBooking",
    "emergencyType": "medical_emergency",
    "message": null,
    "source": "booking",
    "status": "acknowledged",
    "latitude": 33.5138,
    "longitude": 36.2765,
    "triggeredAt": "2026-06-01 10:35:22",
    "resolvedAt": null,
    "booking": {
      "id": 987
    },
    "createdAt": "2026-06-01 10:35:22",
    "updatedAt": "2026-06-01 10:40:10"
  }
}
```

## Field Contract
| Field | Type | Nullable | Description |
|---|---|---|---|
| `id` | integer | No | SOS alert ID |
| `userId` | integer | Yes | User who submitted a user SOS |
| `orderId` | integer | Yes | Restaurant order linked to a user SOS |
| `bookingId` | integer | Yes | Related booking ID |
| `bookingType` | string | Yes | Polymorphic booking model class |
| `emergencyType` | string | Yes | Emergency category enum |
| `message` | string | Yes | User-entered SOS issue description |
| `source` | string | Yes | `booking` for existing booking SOS, `user` for user-created SOS |
| `status` | string | Yes | SOS status enum |
| `latitude` | number | No | Float latitude |
| `longitude` | number | No | Float longitude |
| `triggeredAt` | string | Yes | Datetime, format `YYYY-MM-DD HH:mm:ss` |
| `resolvedAt` | string | Yes | Datetime, format `YYYY-MM-DD HH:mm:ss` |
| `booking` | object | Yes | Loaded related booking object (shape depends on booking type) |
| `createdAt` | string | No | Datetime, format `YYYY-MM-DD HH:mm:ss` |
| `updatedAt` | string | No | Datetime, format `YYYY-MM-DD HH:mm:ss` |

## Enums
- `status`: `pending`, `triggered`, `acknowledged`, `resolved`
- `emergencyType`: `safety_threat`, `medical_emergency`, `severe_conflict`

## Error Cases
- `401 Unauthorized`: Missing/invalid token
- `404 Not Found`: SOS alert ID does not exist
- `422 Unprocessable Entity`: Invalid filter/sort/perPage

## Flutter Integration Notes
- Parse `latitude`/`longitude` as `double`.
- `triggeredAt`, `resolvedAt`, `createdAt`, `updatedAt` are not ISO 8601; parse with `yyyy-MM-dd HH:mm:ss`.
- `POST /user/sos` returns `created_at` as ISO 8601.
- `booking` is polymorphic and may vary by booking type; treat it as dynamic/map.
- User SOS creation is order-bound and requires an authenticated user who owns the selected restaurant order.

## Source of Truth (Code)
- `Modules/User/routes/api.php` (`POST /api/v1/user/sos`)
- `routes/api.php` (`POST /api/user/sos` compatibility alias)
- `Modules/User/app/Http/Controllers/API/UserSosController.php`
- `Modules/User/app/Http/Requests/UserSosStoreRequest.php`
- `Modules/User/app/Http/Resources/UserSosResource.php`
- `routes/api.php` (`/api/v1/sos-alerts` resource: `index`, `show`)
- `app/Http/Controllers/API/SosAlertController.php`
- `app/Http/Resources/SosAlertResource.php`
- `app/Http/Requests/SosAlertRequests/SosAlertFilterRequest.php`
