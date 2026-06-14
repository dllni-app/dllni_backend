# SOS Flow API Contract

## Scope
This contract documents the mobile SOS submission flow implemented in `Dllni_backend` for Flutter clients.

- Base URL: `{{BASE_URL}}/api/v1`
- Auth: `Bearer <sanctum_token>` required
- Content-Type: `application/json`

## Endpoint

| Method | Path | Purpose |
|---|---|---|
| POST | `/user/sos` | Create a user SOS for a restaurant order |

## Create User SOS

### Request
`POST /api/v1/user/sos`

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
    "created_at": "2026-06-14T18:30:00.000000Z"
  }
}
```

### Side Effects
- A `sos_alerts` row is created with `source = user` and `status = pending`.
- Active dashboard admins receive a database notification.
- The Filament SOS Alerts resource shows the new alert for review.

### Error Cases
- `401 Unauthorized`: Missing or invalid token
- `403 Forbidden`: The order exists but does not belong to the authenticated user
- `422 Unprocessable Entity`: Missing/invalid `order_id` or invalid `message`

## Notes

- The legacy `POST /api/user/sos` alias is removed.
- The old public `GET /api/v1/sos-alerts` and `GET /api/v1/sos-alerts/{id}` endpoints are removed from the mobile API surface.

## Source of Truth
- `Modules/User/routes/api.php`
- `Modules/User/app/Http/Controllers/API/UserSosController.php`
- `Modules/User/app/Http/Requests/UserSosStoreRequest.php`
- `Modules/User/app/Actions/CreateUserSosAlertAction.php`
- `app/Notifications/NewUserSosDashboardNotification.php`
- `app/Filament/Resources/SosAlerts/SosAlertResource.php`
