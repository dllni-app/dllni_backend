   # API contract: user notifications (`/api/v1/user/notifications`)

Routes are registered in `Modules/User/routes/api.php` inside the authenticated user group (`auth:sanctum`).

**Base URL:** `{baseUrl}/api` (example: `http://Dllni.test/api`)

**Full prefix:** `/v1/user/notifications`

**Authentication:** `Authorization: Bearer {token}` (Laravel Sanctum)

**Content type:** `application/json`

---

## Overview

This contract covers the user app notifications feed and read state actions:

1. `GET /v1/user/notifications` (paginated feed)
2. `PATCH /v1/user/notifications/{id}/read` (mark one)
3. `PATCH /v1/user/notifications/read-all` (mark all unread)

Notification items are formatted by `UserNotificationResource`.

---

## Notification item schema (`data[]`)

| Field | Type | Notes |
| --- | --- | --- |
| `id` | string (UUID) | Database notification id. |
| `module` | string \| null | `restaurant`, `supermarket`, `cleaning`, or `null` if unknown. |
| `icon` | string (URL) \| null | If payload contains `data.icon`, it is returned as-is; otherwise backend generates module default icon URL. |
| `type` | string \| null | Notification domain type (e.g. `new_order`, `order_update`). |
| `title` | string | Human-readable title. |
| `body` | string | Human-readable message body. |
| `data` | object | Optional deep-link payload keys only when present: `bookingId`, `timeWarningId`, `disputeId`. |
| `readAt` | string (ISO-8601) \| null | `null` means unread. |
| `createdAt` | string (ISO-8601) | Creation timestamp. |

### Module resolution rules

Backend resolves `module` in this order:

1. Explicit payload key `data.module` when one of `restaurant|supermarket|cleaning`.
2. Notification class namespace match:
   - `\Cleaning\` -> `cleaning`
   - `\Supermarket\` -> `supermarket`
   - `\Resturants\` / `\Restaurant\` -> `restaurant`
3. Known payload `data.type` mapping:
   - `new_order`, `extension_request`, `dispute_opened` -> `cleaning`
   - `smart_list_scheduled_order_sent`, `smart_list_scheduled_order_failed` -> `supermarket`
4. Otherwise `null`.

### Icon resolution rules

Backend resolves `icon` in this order:

1. Explicit payload key `data.icon` if non-empty string.
2. Default icon URL by resolved module:
   - `cleaning` -> `/images/notifications/cleaning.svg`
   - `supermarket` -> `/images/notifications/supermarket.svg`
   - `restaurant` -> `/images/notifications/restaurant.svg`
3. Otherwise `null`.

---

## 1) List notifications

| Property | Value |
| --- | --- |
| Method | `GET` |
| Path | `/v1/user/notifications` |
| Success | `200 OK` |

### Query parameters

| Query key | Type | Notes |
| --- | --- | --- |
| `perPage` | integer | Optional; `1..100`; default `10`. |
| `page` | integer | Optional; min `1`. |
| `filter[unread]` | boolean | Optional; when true returns unread only (`read_at IS NULL`). |

### Response shape

Laravel paginator response:

- `data` (array of notification items)
- `links`
- `meta`

### Example response

```json
{
  "data": [
    {
      "id": "550e8400-e29b-41d4-a716-446655440000",
      "module": "cleaning",
      "icon": "http://Dllni.test/images/notifications/cleaning.svg",
      "type": "new_order",
      "title": "طلب جديد",
      "body": "طلب تنظيف جديد: CLN-2026-0001.",
      "data": {
        "bookingId": 42
      },
      "readAt": null,
      "createdAt": "2026-04-14T10:00:00+00:00"
    }
  ],
  "links": {
    "first": "http://Dllni.test/api/v1/user/notifications?page=1",
    "last": "http://Dllni.test/api/v1/user/notifications?page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "per_page": 10,
    "total": 1
  }
}
```

### Errors

| Status | Meaning |
| --- | --- |
| `401` | Unauthenticated or invalid token. |
| `422` | Invalid query params (e.g. `perPage > 100`, non-boolean `filter[unread]`). |

---

## 2) Mark one notification as read

| Property | Value |
| --- | --- |
| Method | `PATCH` |
| Path | `/v1/user/notifications/{id}/read` |
| Success | `204 No Content` |

### Path param

| Param | Type | Notes |
| --- | --- | --- |
| `id` | string (UUID) | Must belong to authenticated user. |

### Behavior

- Finds notification by id under current user only.
- Sets `read_at` to now.
- If already read, remains read (idempotent effect).

### Errors

| Status | Meaning |
| --- | --- |
| `401` | Unauthenticated. |
| `404` | Notification not found for this user. |

---

## 3) Mark all unread notifications as read

| Property | Value |
| --- | --- |
| Method | `PATCH` |
| Path | `/v1/user/notifications/read-all` |
| Success | `204 No Content` |

### Behavior

- Marks all unread notifications of authenticated user as read.
- Safe to call repeatedly (idempotent from client perspective).
- Does not affect other users.

### Errors

| Status | Meaning |
| --- | --- |
| `401` | Unauthenticated. |

---

## Client integration notes

- Use `module` and `type` together for routing decisions.
- Use `icon` directly in UI; fallback to app-level placeholder only when `icon` is `null`.
- Keep `id` for optimistic read updates:
  - one item: call `PATCH /{id}/read`
  - bulk read: call `PATCH /read-all`
- Use `filter[unread]=1` for notification badge pages.

---

## Postman

See `postman/Dllni-User-Module.postman_collection.json`:

- `Notifications list (requires token)`
- `Notifications - Mark As Read`
- `Notifications - Mark All As Read`
