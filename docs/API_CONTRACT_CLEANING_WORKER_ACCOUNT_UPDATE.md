# API Contract: Cleaning Worker Account Update

## Overview
- Module: `Cleaning`
- Audience: Worker mobile app
- Auth: `Bearer token` via `auth:sanctum`
- Base URL: `/api/v1`

---

## Endpoint: Update Worker Account Profile

- Method: `PUT`
- URL: `/cleaning/worker/account/profile`
- Content-Type:
  - `multipart/form-data` when uploading `avatar`
  - `application/json` otherwise

### Request Body

| Field | Type | Required | Rules |
|---|---|---|---|
| `name` | string/null | No | max:255 |
| `email` | string/null | No | email, max:255, unique in `users.email` (ignores current user) |
| `phone` | string/null | No | max:255, unique in `users.phone` (ignores current user) |
| `bio` | string/null | No | string |
| `avatar` | file/null | No | mimes: jpeg,png,jpg,gif,svg,webp, max: 2048 KB |
| `isActive` | boolean/null | No | boolean |

### Success Response (`200 OK`)
- Returns `WorkerResource`.

```json
{
  "id": 12,
  "userId": 34,
  "firstName": "Ahmad",
  "avatar": {
    "id": 77,
    "url": "https://example.com/media/worker-avatar.jpg"
  },
  "isActive": true,
  "user": {
    "id": 34,
    "name": "Ahmad Mohammed",
    "email": "worker@example.com",
    "phone": "+963987654321"
  },
  "zones": [],
  "availability": [],
  "createdAt": "2026-03-26 09:00:00",
  "updatedAt": "2026-03-26 09:30:00"
}
```

### Error Responses
- `401 Unauthorized`
```json
{
  "message": "Unauthenticated."
}
```

- `403 Forbidden` (authenticated user has no related worker)
```json
{
  "message": "User must have an associated worker."
}
```

- `422 Unprocessable Entity` (validation errors)
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "phone": ["The phone has already been taken."]
  }
}
```

### Pending Fields (Not Implemented Yet)
- `city`
- `gender`
- `birthday`

These are not currently available in `users`/`workers` schema, so they are not accepted by the endpoint yet.

---

## Endpoint: Update Worker Account Password

- Method: `PUT`
- URL: `/cleaning/worker/account/password`
- Content-Type: `application/json`

### Request Body

| Field | Type | Required | Rules |
|---|---|---|---|
| `currentPassword` | string | Yes | must match current authenticated user password |
| `newPassword` | string | Yes | min:8, max:255 |
| `newPasswordConfirmation` | string | Yes | must be equal to `newPassword` |

### Example Request
```json
{
  "currentPassword": "old-password",
  "newPassword": "new-password-123",
  "newPasswordConfirmation": "new-password-123"
}
```

### Success Response (`200 OK`)
```json
{
  "message": "Password updated successfully."
}
```

### Error Responses
- `401 Unauthorized`
```json
{
  "message": "Unauthenticated."
}
```

- `403 Forbidden` (authenticated user has no related worker)
```json
{
  "message": "User must have an associated worker."
}
```

- `422 Unprocessable Entity` (validation errors)
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "currentPassword": ["The password is incorrect."]
  }
}
```

---

## Related Endpoints (Already Existing)
- `PATCH /cleaning/worker/account/status`
- `PUT /cleaning/worker/account/work-areas`
- `PUT /cleaning/worker/account/working-hours`
- `GET /cleaning/worker/account/profile`
