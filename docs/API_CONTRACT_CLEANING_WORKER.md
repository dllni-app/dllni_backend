# API Contract for Flutter – Cleaning Worker App

**Audience:** Flutter developer  
**Domain:** `dllni.mustafafares.com`  
**Scope:** Cleaning worker mobile app only. For admin dashboard, see [API_CONTRACT_CLEANING.md](API_CONTRACT_CLEANING.md). For Restaurants, see [API_CONTRACT_RESTAURANTS.md](API_CONTRACT_RESTAURANTS.md).

---

## 1. Base URL and authentication

- **Base URL:** `https://dllni.mustafafares.com`
- **API prefix:** All endpoints below are relative to base URL: `https://dllni.mustafafares.com/api/v1/...`
- **Auth:** Laravel Sanctum. Send token on every request:
  - Header: `Authorization: Bearer {token}`
  - Login/token issuance is out of scope of this contract (use existing auth endpoints).
- **Content-Type:** `application/json` for request bodies; responses are JSON.
- **Worker requirement:** Most endpoints require the authenticated user to have an associated worker (`user.worker`). Returns 403 if user has no worker.

---

## 2. Global conventions

### 2.1 Pagination (index endpoints)

- **Query parameters:** `perPage` (integer, 1–100, default 20), `page` (integer, default 1).
- **Response (paginated list):** Laravel API Resource collection format:
  - `data`: array of resource objects
  - `links`: `first`, `last`, `prev`, `next` (URLs)
  - `meta`: `current_page`, `from`, `last_page`, `path`, `per_page`, `to`, `total`

### 2.2 Filtering and sorting (index endpoints)

- **Filters:** Pass as query params: `filter[fieldName]=value`. Multiple filters are ANDed.
- **Sort:** `sort=field` (asc) or `sort=-field` (desc). Default is typically `-created_at`.

### 2.3 Request/response casing

- **JSON:** camelCase for all keys (e.g. `createdAt`, `perPage`, `scheduledDate`).

### 2.4 Single-resource responses (show/action endpoints)

- **Success:** HTTP 200. Body: `{ "data": { ...resource object } }`.
- **Delete:** HTTP 204 No Content, empty body.
- **Errors:** 4xx/5xx with JSON body; standard Laravel validation errors under `errors` (keyed by field).

---

## 3. Worker-specific endpoints

Base path: `/api/v1/` (all under `auth:sanctum`).

### 3.1 Worker homepage (dashboard)

| Method | Path                                  | Description                                      |
| ------ | ------------------------------------- | ------------------------------------------------ |
| GET    | `/api/v1/cleaning/worker/homepage`    | Stats for the authenticated worker               |

**Query params:** None. Worker is derived from `Authorization: Bearer {token}`.

**Response (200):**

```json
{
  "date": "2026-02-18",
  "totalBookings": 42,
  "todayCount": 2,
  "completedCount": 35,
  "pendingCount": 5,
  "inProgressCount": 1,
  "cancelledCount": 1,
  "totalEarnings": 5250.00,
  "todayEarnings": 450.00,
  "earningsChangePercent": 15.0,
  "newOrdersCount": 3,
  "pendingExtensionRequestsCount": 1
}
```

| Field                       | Type   | Description                                                       |
| --------------------------- | ------ | ----------------------------------------------------------------- |
| date                        | string | Current date for the overview (YYYY-MM-DD)                        |
| totalBookings               | number | All-time bookings assigned to this worker                         |
| todayCount                  | number | Bookings scheduled for today (excluding cancelled)                 |
| completedCount              | number | Bookings with status `completed`                                  |
| pendingCount                | number | Upcoming bookings in `pending` or `worker_assigned` (not yet in progress) |
| inProgressCount             | number | Bookings with status `in_progress`                                |
| cancelledCount              | number | Bookings with status `cancelled`                                  |
| totalEarnings               | number | Sum of `totalPrice` for completed bookings                        |
| todayEarnings               | number | Sum of `totalPrice` for completed bookings today                   |
| earningsChangePercent       | number | Percentage change of today's earnings vs yesterday (+15 = 15% up, -10 = 10% down; 100 when yesterday was 0 and today > 0) |
| newOrdersCount              | number | Bookings with status `pending` (worker_id null or current worker)  |
| pendingExtensionRequestsCount | number | Extension requests (time warnings) awaiting worker response   |

If the user has no worker, all values are `0`.

---

### 3.2 Orders list (cleaning bookings)

| Method | Path                        | Description                          |
| ------ | --------------------------- | ------------------------------------ |
| GET    | `/api/v1/cleaning-bookings` | List cleaning bookings (paginated)    |

**Query params:**

| Param                      | Type    | Description                                              |
| -------------------------- | ------- | -------------------------------------------------------- |
| filter[forCurrentWorker]   | boolean | `true` or `1` – scope to authenticated worker (required for worker app) |
| filter[status]             | string  | Filter by status (see Section 6)                          |
| filter[scheduledDate]      | string  | Date (YYYY-MM-DD), e.g. today for "Tasks for today"      |
| filter[scheduledDateFrom]  | string  | Start of range (YYYY-MM-DD)                              |
| filter[scheduledDateTo]    | string  | End of range (YYYY-MM-DD)                                |
| perPage                    | integer | 1–100, default 20                                       |
| page                       | integer | Page number, default 1                                 |
| sort                       | string  | `scheduledDate`, `-scheduledDate`, `createdAt`, `-createdAt` |

**Example – Tasks for today:**

```
GET /api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[scheduledDate]=2025-02-24&perPage=20
```

**Example – Orders by status (new requests):**

```
GET /api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[status]=pending&perPage=20
```

**Response (200):** Standard paginated collection. Each booking includes:
- `id`, `bookingNumber`, `status`, `scheduledDate`, `scheduledTime`
- `locationName`, `numberOfRooms`, `estimatedHours`, `totalHours`
- `customer`: `id`, `name`, `email`, `phone` (for call button)
- `propertyType`, `propertyDetails`, `totalPrice`, `createdAt`, `updatedAt`

---

### 3.3 Order details

| Method | Path                          | Description                          |
| ------ | ----------------------------- | ------------------------------------ |
| GET    | `/api/v1/cleaning-bookings/{id}` | Show one cleaning booking         |

**Response (200):** Full booking object with `customer`, `worker`, `services`, `addons`, `billingPolicy`, `timeWarnings`, `disputes`. Use `customer.phone` for contact/call.

---

### 3.4 Accept order

| Method | Path                                       | Description                    |
| ------ | ------------------------------------------ | ------------------------------ |
| POST   | `/api/v1/cleaning-bookings/{id}/accept`    | Worker accepts an order        |

**Path params:** `id` – cleaning booking ID

**Request body:** None

**Response (200):** Updated booking resource.

**Errors:**
- `403` – User has no worker, or booking is assigned to another worker
- `422` – Booking cannot be accepted in current status (e.g. already completed)

**Valid from status:** `pending` only.

---

### 3.5 Reject order

| Method | Path                                       | Description                    |
| ------ | ------------------------------------------ | ------------------------------ |
| POST   | `/api/v1/cleaning-bookings/{id}/reject`     | Worker rejects an order        |

**Path params:** `id` – cleaning booking ID

**Request body:** Optional

```json
{
  "reason": "Optional rejection reason (max 500 chars)"
}
```

**Response (200):** Updated booking resource (status `cancelled`).

**Errors:**
- `403` – User has no worker, or booking is assigned to another worker
- `422` – Booking cannot be rejected in current status

**Valid from statuses:** `pending`, `worker_assigned`

---

### 3.6 Start travel ("I'm on my way")

| Method | Path                                             | Description                    |
| ------ | ------------------------------------------------ | ------------------------------ |
| POST   | `/api/v1/cleaning-bookings/{id}/start-travel`     | Worker marks as en route        |

**Path params:** `id` – cleaning booking ID

**Request body:** None

**Response (200):** Updated booking resource. Status remains `worker_assigned`; `startedTravelAt` is set to current time. Use this to show "I'm on my way" in the UI.

**Errors:**
- `403` – User has no worker, or booking is not assigned to worker
- `422` – Booking cannot start travel in current status

**Valid from status:** `worker_assigned` only.

---

### 3.7 Start work

| Method | Path                                             | Description                    |
| ------ | ------------------------------------------------ | ------------------------------ |
| POST   | `/api/v1/cleaning-bookings/{id}/start-work`      | Worker starts the job on site  |

**Path params:** `id` – cleaning booking ID

**Request body:** None

**Response (200):** Updated booking resource (status `in_progress`, `workStartedAt` set).

**Errors:**
- `403` – User has no worker, or booking is not assigned to worker
- `422` – Booking must be assigned to start work

**Valid from status:** `worker_assigned` only.

---

### 3.8 Complete order

| Method | Path                                       | Description                    |
| ------ | ------------------------------------------ | ------------------------------ |
| POST   | `/api/v1/cleaning-bookings/{id}/complete`   | Worker marks work as finished  |

**Path params:** `id` – cleaning booking ID

**Request body:** None

**Response (200):** Updated booking resource (status `completed`, `workFinishedAt` set).

**Errors:**
- `403` – User has no worker, or booking is not assigned to worker
- `422` – Booking must be in progress to complete

**Valid from status:** `in_progress` only.

---

### 3.9 Cancel order

| Method | Path                                       | Description                    |
| ------ | ------------------------------------------ | ------------------------------ |
| POST   | `/api/v1/cleaning-bookings/{id}/cancel`    | Worker cancels an order        |

**Path params:** `id` – cleaning booking ID

**Request body:** Optional

```json
{
  "reason": "Optional cancellation reason (max 500 chars)"
}
```

**Response (200):** Updated booking resource (status `cancelled`).

**Errors:**
- `403` – User has no worker, or booking is not assigned to worker
- `422` – Booking cannot be cancelled in current status

**Valid from statuses:** `worker_assigned`, `in_progress`

**Note:** Cancellation may affect trust points, cancellation rate, and acceptance rate. The app may display a warning before calling this endpoint.

---

### 3.10 Extension requests (time warnings)

#### List pending extension requests

| Method | Path                        | Description                          |
| ------ | --------------------------- | ------------------------------------ |
| GET    | `/api/v1/cleaning-time-warnings` | List time warnings (paginated)  |

**Query params:**

| Param                      | Type    | Description                                              |
| -------------------------- | ------- | -------------------------------------------------------- |
| filter[forCurrentWorker]   | boolean | `true` – only warnings for bookings assigned to worker    |
| filter[pending]            | boolean | `true` – only warnings where worker has not responded    |
| filter[bookingId]          | integer | Filter by booking ID                                     |
| filter[bookingType]        | string  | `cleaning_booking` or `event_booking`                    |
| perPage                    | integer | 1–100, default 20                                       |

**Example – Pending extension requests:**

```
GET /api/v1/cleaning-time-warnings?filter[forCurrentWorker]=1&filter[pending]=1
```

#### Accept extension

| Method | Path                                             | Description                    |
| ------ | ------------------------------------------------ | ------------------------------ |
| POST   | `/api/v1/cleaning-time-warnings/{id}/accept`     | Worker accepts time extension  |

**Path params:** `id` – cleaning time warning ID

**Request body:** Optional

```json
{
  "additionalMinutes": 30
}
```

**Response (200):** Updated time warning resource.

**Errors:**
- `403` – User has no worker, or warning is not for worker's booking
- `422` – Extension request has already been responded to

#### Reject extension

| Method | Path                                             | Description                    |
| ------ | ------------------------------------------------ | ------------------------------ |
| POST   | `/api/v1/cleaning-time-warnings/{id}/reject`     | Worker rejects time extension  |

**Path params:** `id` – cleaning time warning ID

**Request body:** Optional

```json
{
  "message": "Optional apology message (max 500 chars)"
}
```

**Response (200):** Updated time warning resource.

**Errors:**
- `403` – User has no worker, or warning is not for worker's booking
- `422` – Extension request has already been responded to

---

### 3.11 Client contact

Use `customer.phone` from the order details response (`GET /api/v1/cleaning-bookings/{id}`). No separate endpoint required.

---

### 3.12 Calendar (month/week view)

| Method | Path                        | Description                          |
| ------ | --------------------------- | ------------------------------------ |
| GET    | `/api/v1/cleaning-bookings` | List cleaning bookings (paginated)   |

**Query params:**

| Param                      | Type    | Description                                              |
| -------------------------- | ------- | -------------------------------------------------------- |
| filter[forCurrentWorker]   | boolean | `true` – scope to authenticated worker                   |
| filter[scheduledDateFrom]  | string  | Start of range (YYYY-MM-DD), e.g. first day of month    |
| filter[scheduledDateTo]    | string  | End of range (YYYY-MM-DD), e.g. last day of month       |
| perPage                    | integer | Use higher value for full month (e.g. 100)               |
| sort                       | string  | `scheduledDate`, `-scheduledDate`                        |

**Example – April 2025:**

```
GET /api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[scheduledDateFrom]=2025-04-01&filter[scheduledDateTo]=2025-04-30&perPage=100
```

**Response (200):** Same structure as orders list. Frontend groups by `scheduledDate` for calendar cells; use `status` for color-coding.

---

### 3.13 Worker availability

Use `PUT /api/v1/workers/{id}` to update worker (including availability). The worker can update their own record if authorized. See shared app endpoints in [API_CONTRACT_CLEANING.md](API_CONTRACT_CLEANING.md) Section 4.1.

---

## 4. Enums reference

Use these **string values** when filtering or displaying status/type labels. All values are snake_case.

### CleaningBookingStatus (one status per section)

| Value             | When to use (Flutter) |
| ----------------- | --------------------- |
| `pending`         | New request; no worker yet. Show "طلب جديد"; worker can **Accept** or **Reject**. |
| `worker_assigned` | Worker has accepted; not yet started work. Show "في الاستعداد"; worker can **Start travel**, **Start work**, or **Cancel**. Use `startedTravelAt` to show "في الطريق" if set. |
| `in_progress`     | Worker started work on site. Show "قيد التنفيذ"; worker can **Complete** or **Cancel**. Show time-extension banner if pending. |
| `completed`       | Job finished. Show "مكتمل"; read-only. |
| `cancelled`       | Cancelled or rejected. Show "ملغي"; read-only. |

### CleaningTimeWarningResponse

| Value                 | Description                    |
| --------------------- | ----------------------------- |
| `extend_time`         | Worker accepts extension      |
| `commit_current_time` | Worker commits to current time|
| `finish_early`        | Worker finishes early         |

---

## 5. Example requests and responses

### Example 1: Worker homepage

**Request:**

```
GET https://dllni.mustafafares.com/api/v1/cleaning/worker/homepage
Authorization: Bearer {token}
```

**Response (200):**

```json
{
  "date": "2026-02-18",
  "totalBookings": 42,
  "todayCount": 2,
  "completedCount": 35,
  "pendingCount": 5,
  "inProgressCount": 1,
  "cancelledCount": 1,
  "totalEarnings": 5250.00,
  "todayEarnings": 450.00,
  "earningsChangePercent": 15.0,
  "newOrdersCount": 3,
  "pendingExtensionRequestsCount": 1
}
```

---

### Example 2: Accept order

**Request:**

```
POST https://dllni.mustafafares.com/api/v1/cleaning-bookings/123/accept
Authorization: Bearer {token}
Content-Type: application/json
```

**Response (200):**

```json
{
  "data": {
    "id": 123,
    "status": "worker_assigned",
    "bookingNumber": "CB-001",
    "scheduledDate": "2025-02-24",
    "customer": { "id": 10, "name": "Ahmed", "phone": "+963..." },
    "totalPrice": 2000,
    ...
  }
}
```

---

### Example 3: Reject order with reason

**Request:**

```
POST https://dllni.mustafafares.com/api/v1/cleaning-bookings/124/reject
Authorization: Bearer {token}
Content-Type: application/json

{
  "reason": "Schedule conflict"
}
```

**Response (200):** Updated booking with `status: "cancelled"`, `cancellationReason: "Schedule conflict"`.

---

### Example 4: Validation error (422)

**Response (422 Unprocessable Entity):**

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "status": ["Booking must be in progress to complete."]
  }
}
```

---

## 6. Error responses

- **401 Unauthorized:** Missing or invalid token. Redirect to login.
- **403 Forbidden:** User has no worker, or resource is not assigned to worker.
- **404 Not Found:** Resource not found.
- **422 Unprocessable Entity:** Validation errors or invalid status transition. Body includes `errors` object.
