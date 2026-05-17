# API Contract: `GET /api/v1/notifications` (Cleaning Worker)

**Audience:** Flutter cleaning worker app  
**Module:** Notifications  
**Auth:** Required (`Bearer` Sanctum token)

---

## 1) Endpoint Summary

Returns a paginated list of notifications for the **authenticated user**.

For cleaning workers, common notification types are:
- new cleaning order request
- booking extension request
- dispute opened

---

## 2) HTTP Request

### Method & URL

```http
GET /api/v1/notifications
```

### Headers

```http
Authorization: Bearer <access_token>
Accept: application/json
```

### Query Parameters

| Name | Type | Required | Validation | Description |
|---|---|---|---|---|
| `perPage` | integer | no | `min:1`, `max:100` | Page size. Default: `10`. |
| `page` | integer | no | `min:1` | Pagination page number. |
| `filter[unread]` | boolean | no | boolean | If `true`, returns unread notifications only. |

### Example Request

```http
GET /api/v1/notifications?perPage=10&page=1&filter[unread]=true
Authorization: Bearer eyJ0eXAiOiJKV1Qi...
Accept: application/json
```

---

## 3) Success Response

### Status

`200 OK`

### Response Structure

| Root Key | Type | Description |
|---|---|---|
| `data` | array | Notification items for current page. |
| `links` | object | Laravel paginator links. |
| `meta` | object | Laravel paginator metadata. |

### Notification Item Schema (`data[]`)

| Field | Type | Nullable | Description |
|---|---|---|---|
| `id` | string | no | Notification UUID. |
| `module` | string | yes | Usually `cleaning` for worker app notifications. |
| `icon` | string (URL) | yes | Notification icon URL. |
| `type` | string | yes | Legacy type (`new_order`, `extension_request`, `dispute_opened`). |
| `canonicalType` | string | yes | Canonical key (recommended for routing). |
| `canonical_type` | string | yes | Same as `canonicalType` in snake_case. |
| `category` | string | no | Notification category (`orders`, `system`, ...). |
| `priority` | string | no | Priority (`high`, `normal`). |
| `title` | string | no | Localized title text. |
| `body` | string | no | Localized body text. |
| `data` | object | no | Action payload (IDs for deep-link routing). |
| `readAt` | string (ISO-8601) | yes | Read timestamp, `null` if unread. |
| `read_at` | string (ISO-8601) | yes | Same value as `readAt` in snake_case. |
| `createdAt` | string (ISO-8601) | yes | Notification creation timestamp. |
| `created_at` | string (ISO-8601) | yes | Same value as `createdAt` in snake_case. |

---

## 4) Full Example Response (What Backend Returns)

```json
{
  "data": [
    {
      "id": "0f6bc196-3ec2-48e3-a91f-24410f57c111",
      "module": "cleaning",
      "icon": "https://api.dllni.com/images/notifications/cleaning.svg",
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
    },
    {
      "id": "2f9f3db3-82cd-4bb2-814b-bf6fd3cf0222",
      "module": "cleaning",
      "icon": "https://api.dllni.com/images/notifications/cleaning.svg",
      "type": "extension_request",
      "canonicalType": "cleaning.booking.extension_request",
      "canonical_type": "cleaning.booking.extension_request",
      "category": "orders",
      "priority": "high",
      "title": "Extension request",
      "body": "The customer requested an extension for this booking.",
      "data": {
        "bookingId": 1042,
        "timeWarningId": 77
      },
      "readAt": null,
      "read_at": null,
      "createdAt": "2026-05-17T08:34:10+00:00",
      "created_at": "2026-05-17T08:34:10+00:00"
    },
    {
      "id": "6d4d8ab7-8f50-4ed5-9875-92cd49d10333",
      "module": "cleaning",
      "icon": "https://api.dllni.com/images/notifications/cleaning.svg",
      "type": "dispute_opened",
      "canonicalType": "cleaning.booking.dispute_opened",
      "canonical_type": "cleaning.booking.dispute_opened",
      "category": "system",
      "priority": "high",
      "title": "Dispute opened",
      "body": "A dispute was opened for one of your bookings. Please review it.",
      "data": {
        "bookingId": 1042,
        "disputeId": 305
      },
      "readAt": "2026-05-17T09:01:00+00:00",
      "read_at": "2026-05-17T09:01:00+00:00",
      "createdAt": "2026-05-17T08:50:55+00:00",
      "created_at": "2026-05-17T08:50:55+00:00"
    }
  ],
  "links": {
    "first": "https://api.dllni.com/api/v1/notifications?page=1",
    "last": "https://api.dllni.com/api/v1/notifications?page=4",
    "prev": null,
    "next": "https://api.dllni.com/api/v1/notifications?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 4,
    "path": "https://api.dllni.com/api/v1/notifications",
    "per_page": 10,
    "to": 10,
    "total": 37
  }
}
```

---

## 5) Error Responses

### 401 Unauthorized

Returned when token is missing/invalid.

```json
{
  "message": "Unauthenticated."
}
```

### 422 Validation Error

Returned when query params fail validation.

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "perPage": [
      "The per page must be at least 1."
    ],
    "filter.unread": [
      "The filter.unread field must be true or false."
    ]
  }
}
```

---

## 6) Flutter Implementation Notes

1. Route using `canonicalType` (fallback to `type` only for old payload support).
2. Treat `id` as string UUID.
3. Unread state: `readAt == null`.
4. Pagination: consume `links.next` until it is `null`.
5. `data` object is extensible; ignore unknown keys safely.

---

## 7) Backend Source of Truth

- `routes/api.php`
- `app/Http/Controllers/API/UserNotificationController.php`
- `app/Http/Requests/UserNotificationRequests/UserNotificationIndexRequest.php`
- `app/Http/Resources/UserNotificationResource.php`
- `app/Notifications/Core/NotificationFeedNormalizer.php`
- `app/Notifications/Cleaning/NewOrderRequestNotification.php`
- `app/Notifications/Cleaning/ExtensionRequestNotification.php`
- `app/Notifications/Cleaning/DisputeOpenedNotification.php`
- `config/notification_types.php`
