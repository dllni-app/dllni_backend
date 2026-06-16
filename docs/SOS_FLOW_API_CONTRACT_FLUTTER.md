# SOS Flow API Contract

## Purpose
This guide documents the mobile SOS submission flow for Flutter.

The flow is intentionally simple:

1. Flutter sends one `POST` request.
2. Backend validates ownership of the selected order.
3. Backend creates one `sos_alerts` record.
4. Backend notifies dashboard admins through database notifications.
5. Admin handles the alert in Filament.

## Base API

- Base URL: `{{BASE_URL}}/api/v1`
- Auth: `Bearer <sanctum_token>`
- Content-Type: `application/json`

## Endpoint

| Method | Path | Purpose |
|---|---|---|
| POST | `/user/sos` | Create a user SOS for a restaurant order |

## Request

`POST /api/v1/user/sos`

### Example body
```json
{
  "order_id": 1,
  "emergency_type": "safety_threat",
  "lat": 33.5138,
  "lng": 36.2765,
  "message": "The worker did not arrive and I need urgent help."
}
```

## Field Guide

| Field | Type | Required | Notes |
|---|---|---|---|
| `order_id` | integer | Yes | The restaurant order owned by the authenticated user |
| `message` | string | Yes | Trimmed, non-empty, max `1000` characters |
| `emergency_type` | string | No | Supported client enum for the SOS UI; current backend stores `safety_threat` internally for this MVP |
| `lat` | number | No | Optional latitude for future location-aware handling |
| `lng` | number | No | Optional longitude for future location-aware handling |

## Emergency Type Enum

These are the backend enum values from `App\Enums\EmergencyType`:

| Value | Meaning |
|---|---|
| `safety_threat` | The user feels unsafe or threatened |
| `medical_emergency` | The user has a medical emergency |
| `severe_conflict` | There is a serious conflict requiring urgent attention |

### Flutter recommendation
Use these exact string values in the app UI and state models:

```dart
const emergencyTypes = <String>[
  'safety_threat',
  'medical_emergency',
  'severe_conflict',
];
```

If the backend later starts accepting `emergency_type` from the request, the app will already be aligned.

## Success Response

### Status
`201 Created`

### Body
```json
{
  "success": true,
  "message": "SOS request sent successfully.",
  "data": {
    "id": 1,
    "order_id": 1,
    "message": "The worker did not arrive and I need urgent help.",
    "status": "pending",
    "created_at": "2026-06-14T18:30:00.000000Z"
  }
}
```

### Response fields
| Field | Type | Notes |
|---|---|---|
| `success` | boolean | Always `true` for a successful request |
| `message` | string | Human-readable success text |
| `data.id` | integer | Created SOS alert ID |
| `data.order_id` | integer | Linked order ID |
| `data.message` | string | Stored message, trimmed |
| `data.status` | string | `pending` on creation |
| `data.created_at` | string | ISO 8601 timestamp |

## Backend Behavior

- The backend verifies the authenticated user owns the selected order.
- The backend creates one `sos_alerts` row per request.
- The backend currently stores `emergency_type = safety_threat` for this MVP.
- The backend sends a database notification to active dashboard admins.
- Filament shows the SOS in the admin resource and notifications area.

## Validation and Errors

### `401 Unauthorized`
Missing or invalid Sanctum token.

### `403 Forbidden`
The order exists but does not belong to the authenticated user.

### `422 Unprocessable Entity`
Typical causes:

- `order_id` missing
- `order_id` invalid
- `message` missing
- `message` shorter than 3 characters
- `message` longer than 1000 characters

## Flutter Integration Notes

- Submit one message only. The backend MVP does not require a preselected reason list.
- If the UI lets the user choose an emergency type, map it to the enum table above.
- Keep `lat` and `lng` optional in the client for now.
- Do not depend on `GET /sos-alerts` endpoints. They are not part of the mobile contract.
- Do not depend on the removed compatibility alias `POST /api/user/sos`.

## Notes

- The mobile SOS write endpoint is versioned and should stay at `POST /api/v1/user/sos`.
- The dashboard SOS list lives in Filament, not in a public mobile API.

## Source of Truth

- `app/Enums/EmergencyType.php`
- `Modules/User/routes/api.php`
- `Modules/User/app/Http/Controllers/API/UserSosController.php`
- `Modules/User/app/Http/Requests/UserSosStoreRequest.php`
- `Modules/User/app/Actions/CreateUserSosAlertAction.php`
- `app/Notifications/NewUserSosDashboardNotification.php`
- `app/Filament/Resources/SosAlerts/SosAlertResource.php`
