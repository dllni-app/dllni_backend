# Flutter API Contract: Cleaning Worker Notifications (`/api/v1/notifications`)

**Audience:** Flutter cleaning worker app  
**Backend repo:** `Dllni_backend`  
**Auth:** `Authorization: Bearer <sanctum_token>` (required)

## 1) Scope

This contract covers the worker notification center endpoints currently exposed by backend:

1. `GET /api/v1/notifications` (paginated feed)
2. `PATCH /api/v1/notifications/{id}/read` (mark one notification as read)

Notes:
- `markAllAsRead()` exists in controller code, but **no route is currently registered** in `routes/api.php`.
- Payload shape is normalized by `UserNotificationResource` + `NotificationFeedNormalizer`.

---

## 2) Notification Item Schema

Each item in `data[]` has this shape:

| Field | Type | Nullable | Description |
|---|---|---|---|
| `id` | string | no | Laravel notification UUID. |
| `module` | string | yes | Usually `cleaning` for worker notifications. |
| `icon` | string (url) | yes | Absolute icon URL. |
| `type` | string | yes | Legacy type (`new_order`, `extension_request`, `dispute_opened`). |
| `canonicalType` | string | yes | Canonical key, e.g. `cleaning.booking.new_order_request`. |
| `canonical_type` | string | yes | Same value as `canonicalType` (snake_case duplicate). |
| `category` | string | no | Usually `orders` or `system`. |
| `priority` | string | no | `high` or `normal`. |
| `title` | string | no | Localized title. |
| `body` | string | no | Localized body. |
| `data` | object | no | Extra payload for app routing (see section 5). |
| `readAt` | string (ISO-8601) | yes | Read timestamp in UTC offset format. |
| `read_at` | string (ISO-8601) | yes | Same value as `readAt` (snake_case duplicate). |
| `createdAt` | string (ISO-8601) | yes | Creation timestamp. |
| `created_at` | string (ISO-8601) | yes | Same value as `createdAt` (snake_case duplicate). |

---

## 3) List Notifications

### Endpoint

`GET /api/v1/notifications`

### Query params

| Param | Type | Required | Rules |
|---|---|---|---|
| `perPage` | integer | no | min `1`, max `100`, default `10`. |
| `page` | integer | no | min `1`. |
| `filter[unread]` | boolean | no | `true`/`false`, `1`/`0`. |

### Example request

```http
GET /api/v1/notifications?perPage=10&page=1&filter[unread]=true
Authorization: Bearer <token>
Accept: application/json
```

### Success response (200)

```json
{
  "data": [
    {
      "id": "7ab1c2de-45f6-7890-a123-b45c6789def0",
      "module": "cleaning",
      "icon": "https://api.example.com/images/notifications/cleaning.svg",
      "type": "new_order",
      "canonicalType": "cleaning.booking.new_order_request",
      "canonical_type": "cleaning.booking.new_order_request",
      "category": "orders",
      "priority": "high",
      "title": "New order request",
      "body": "A new cleaning booking CB-1042 is waiting for your response.",
      "data": {
        "bookingId": 1042
      },
      "readAt": null,
      "read_at": null,
      "createdAt": "2026-05-17T08:30:22+00:00",
      "created_at": "2026-05-17T08:30:22+00:00"
    }
  ],
  "links": {
    "first": "https://api.example.com/api/v1/notifications?page=1",
    "last": "https://api.example.com/api/v1/notifications?page=3",
    "prev": null,
    "next": "https://api.example.com/api/v1/notifications?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 3,
    "path": "https://api.example.com/api/v1/notifications",
    "per_page": 10,
    "to": 10,
    "total": 24
  }
}
```

### Validation error (422)

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "perPage": ["The per page must be at least 1."],
    "filter.unread": ["The filter.unread field must be true or false."]
  }
}
```

---

## 4) Mark Notification As Read

### Endpoint

`PATCH /api/v1/notifications/{id}/read`

### Path param

| Param | Type | Required | Description |
|---|---|---|---|
| `id` | string (uuid) | yes | Notification UUID belonging to authenticated user. |

### Example request

```http
PATCH /api/v1/notifications/7ab1c2de-45f6-7890-a123-b45c6789def0/read
Authorization: Bearer <token>
Accept: application/json
```

### Success response

- Status: `204 No Content`
- Body: empty

### Not found / not owned (404)

If the notification does not exist for current user, backend returns `404`.

---

## 5) Cleaning Worker Notification Types (Routing Contract)

Flutter should route by `canonicalType` first (fallback to `type` only for backward compatibility).

| Canonical type | Legacy `type` | `data` keys | Category | Priority | Suggested screen |
|---|---|---|---|---|---|
| `cleaning.booking.new_order_request` | `new_order` | `bookingId` | `orders` | `high` | Booking request details |
| `cleaning.booking.extension_request` | `extension_request` | `bookingId` (optional), `timeWarningId` | `orders` | `high` | Extension decision screen |
| `cleaning.booking.dispute_opened` | `dispute_opened` | `bookingId` (optional), `disputeId` | `system` | `high` | Dispute details/chat |

Notes:
- `bookingId` is optional for extension/dispute notifications when related booking is missing.
- `data` may include additional keys in future; Flutter should ignore unknown fields safely.

---

## 6) Flutter Integration Rules

1. Keep `notification.id` as string UUID (do not parse as int).
2. Use `canonicalType` for deep-link decisions.
3. Use `readAt == null` to display unread badge.
4. Optimistic update after `PATCH .../read`:
   - set local `readAt` immediately,
   - rollback only if request fails.
5. Pagination:
   - load next page from `links.next`,
   - stop when `links.next == null`.

---

## 7) Source of Truth (backend code)

- `routes/api.php`
- `app/Http/Controllers/API/UserNotificationController.php`
- `app/Http/Requests/UserNotificationRequests/UserNotificationIndexRequest.php`
- `app/Http/Resources/UserNotificationResource.php`
- `app/Notifications/Core/NotificationFeedNormalizer.php`
- `app/Notifications/Cleaning/NewOrderRequestNotification.php`
- `app/Notifications/Cleaning/ExtensionRequestNotification.php`
- `app/Notifications/Cleaning/DisputeOpenedNotification.php`
- `config/notification_types.php`
