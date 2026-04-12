# API contract: cleaning booking disputes (`/api/v1/disputes`)

These routes are registered in `routes/api.php` (not under `/api/v1/user/...`). The mobile **user** app can open a dispute against a **cleaning** order by sending the cleaning booking primary key as `bookingId` and `bookingType: "cleaning_booking"`.

**Base URL:** `{baseUrl}/api` (example: `http://Dllni.test/api`)

**Authentication:** `Authorization: Bearer {token}` (Laravel Sanctum)

**Content type:** `application/json` for create/update

---

## 1. Create dispute

| Property | Value |
| --- | --- |
| Method | `POST` |
| Path | `/v1/disputes` |
| Success | `201 Created` |

### Request body (JSON)

| Field | Required | Type | Notes |
| --- | --- | --- | --- |
| `bookingId` | Yes | integer | Must be the `id` of the user’s `CleaningBooking` (same as `GET /api/v1/user/cleaning/orders/{order}`). |
| `bookingType` | Yes | string | Use `cleaning_booking` for cleaning. (`event_booking` is accepted by validation for other flows.) |
| `category` | No | string | One of: `poor_quality`, `property_damage`, `unprofessional`, `billing_issue`, `other`. Maps to complaint “nature” checkboxes in the app. |
| `ticketNumber` | No | string | Max 255; must be **unique** in `disputes.ticket_number` if sent. Usually omitted so the backend can assign. |
| `status` | No | string | `open`, `under_review`, `resolved`, `closed`. Typically omit on create (defaults via model / DB). |
| `resolution` | No | string | `full_refund`, `partial_refund`, `worker_penalty`, `dismissed`. Normally **omit** on create (support/admin sets later). |

### Example

```json
{
  "bookingId": 42,
  "bookingType": "cleaning_booking",
  "category": "poor_quality"
}
```

### Response body

Laravel wraps API resources as `{ "data": { ... } }` unless your project uses a custom wrapper—confirm with one live call.

`DisputeResource` fields:

| Field | Type |
| --- | --- |
| `id` | integer |
| `bookingId` | integer |
| `bookingType` | string |
| `ticketNumber` | string \| null |
| `category` | string (enum value) |
| `status` | string |
| `resolution` | string \| null |
| `booking` | object \| omitted | Present when loaded (create returns it). |
| `messages` | array \| omitted | Present when loaded (create returns it, often empty). |
| `createdAt` | string (datetime) |
| `updatedAt` | string (datetime) |

**Note:** The `disputes` table supports a `description` column, but **`DisputeRequest` / `DisputeData` do not validate or persist `description` yet**, and `DisputeResource` does not expose it. Long text and photo uploads shown in product UI require a follow-up API change (multipart + validation + media collection).

### Errors

- `422` – validation (`errors` object per field).
- `401` – missing/invalid token.

---

## 2. List disputes

| Property | Value |
| --- | --- |
| Method | `GET` |
| Path | `/v1/disputes` |
| Success | `200 OK` |

### Query parameters (`DisputeFilterRequest`)

| Query key | Type | Notes |
| --- | --- | --- |
| `perPage` | integer | Optional; 1–100; default **10**. |
| `page` | integer | Laravel pagination. |
| `filter[bookingId]` | integer | Restrict to one booking. |
| `filter[bookingType]` | string | e.g. `cleaning_booking`. |
| `filter[status]` | string | `open`, `under_review`, `resolved`, `closed`. |
| `filter[category]` | string | Same values as create. |
| `filter[forCurrentWorker]` | boolean | **Worker app:** when true, only disputes for bookings assigned to the authenticated worker. Not for typical customer listing. |
| `sort` | string | `createdAt`, `-createdAt`, `status`, `-status`. |

### Response

Paginated `DisputeResource` collection: `data`, `links`, `meta`.

---

## 3. Show one dispute

| Property | Value |
| --- | --- |
| Method | `GET` |
| Path | `/v1/disputes/{id}` |
| Success | `200 OK` |

Path `{id}` is the dispute primary key (save it after create as `disputeId`).

---

## 4. Update dispute

| Property | Value |
| --- | --- |
| Method | `PUT` or `PATCH` |
| Path | `/v1/disputes/{id}` |
| Success | `200 OK` |

Same body rules as create (`DisputeRequest`). For `PATCH`, send only fields you want to change. **Customer apps** often do not need this; **admin/support** workflows use it more.

---

## 5. Delete dispute

| Property | Value |
| --- | --- |
| Method | `DELETE` |
| Path | `/v1/disputes/{id}` |
| Success | `204 No Content` |

---

## 6. Post message on dispute (worker-only)

| Property | Value |
| --- | --- |
| Method | `POST` |
| Path | `/v1/disputes/{id}/messages` |
| Body | `{ "message": "text" }` |

**Not for the standard customer app:** `DisputeController::storeMessage` requires an authenticated user with a related **worker** assigned to that cleaning booking. Customers get `403` here.

---

## Relation to user cleaning orders

- List/show user orders: `GET /api/v1/user/cleaning/orders`, `GET /api/v1/user/cleaning/orders/{order}`.
- Order payload can include `disputes` when the relation is loaded—useful to display existing tickets.
- **Opening a new dispute** uses **`POST /api/v1/disputes`** with `bookingId` + `bookingType: "cleaning_booking"`, not a nested URL under `/user/cleaning/`.

---

## Postman

See folder **Cleaning disputes (app: global API)** in `postman/Dllni-User-Module.postman_collection.json`.
