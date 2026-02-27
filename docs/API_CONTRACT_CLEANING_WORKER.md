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

### 1.1 Client behavior (UI/API usage)

All GET (with id/enum), POST, and PUT usage must follow the client behavior rules in [API_CONTRACT_CLIENT_BEHAVIOR.md](API_CONTRACT_CLIENT_BEHAVIOR.md): select menu for id/enum in GET (user sees label only); backend-known data stored in page state and not shown/editable in POST; one dedicated input per user-supplied field in POST; optimistic local update for PUT, then persist on success or revert on failure.

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
| filter[forCurrentWorker]   | boolean | `true` or `1` – scope to authenticated worker: shows bookings assigned to worker **or** pending unassigned (for "new requests" list) |
| filter[status]             | string  | Filter by status (see Section 6)                          |
| filter[hasDispute]         | boolean | `true` or `1` – only bookings that have an open dispute (for "نزاعات" / Disputes tab in transaction log) |
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
- `addressLatitude`, `addressLongitude` (number, optional) – destination coordinates for map; use for route and pin
- `startedTravelAt`, `arrivedAt` (string, optional) – ISO datetime; `arrivedAt` set when worker taps "I have arrived"
- `customer`: `id`, `name`, `email`, `phone` (for call button)
- `propertyType`, `propertyDetails`, `totalPrice`, `createdAt`, `updatedAt`
- `workerEarnings` (number, optional) – present when `status` is `completed`; worker’s share after commission (for "سجل المعاملات" / transaction log and wallet)
- `hasDispute` (boolean, optional) – `true` if the booking has at least one open dispute

**Transaction log tabs (سجل المعاملات):** Use the same endpoint with filters: **الكل** = no status filter; **مكتملة** = `filter[status]=completed`; **ملغاة** = `filter[status]=cancelled`; **نزاعات** = `filter[hasDispute]=true`.

---

### 3.3 Order details

| Method | Path                          | Description                          |
| ------ | ----------------------------- | ------------------------------------ |
| GET    | `/api/v1/cleaning-bookings/{id}` | Show one cleaning booking         |

**Response (200):** Full booking object with `customer`, `worker`, `services`, `addons`, `billingPolicy`, `timeWarnings`, `disputes`. Includes `addressLatitude`, `addressLongitude` (destination for map), `startedTravelAt`, `arrivedAt`. Use `customer.phone` for contact/call.

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

### 3.7 Real-time map – worker location and arrival (Pusher)

The customer app can show a live map (worker moving toward the address) and "Worker arrived" by subscribing to a **private Pusher channel** for the booking. The worker app sends location updates and marks arrival via the endpoints below.

#### Broadcast authentication

- **Endpoint:** `POST /broadcasting/auth` (Laravel default; same host as API).
- **Headers:** `Authorization: Bearer {token}`, `Content-Type: application/json`.
- **Body (form or JSON):** `channel_name=private-cleaning-booking.{bookingId}&socket_id={socket_id}` (Pusher requires this for private channel subscribe).
- **Success:** 200 with Pusher auth payload. **Errors:** 403 if user is not the customer or the assigned worker for that booking.

Only the **customer** and the **assigned worker** (and admins, if implemented) are allowed to subscribe to `private-cleaning-booking.{bookingId}`.

#### Channel and events

| Channel (private)                | Events (broadcast by server)   | When |
|----------------------------------|--------------------------------|------|
| `private-cleaning-booking.{id}`  | `WorkerLocationUpdated`        | Worker sends location via `POST …/location` (after start-travel). |
| `private-cleaning-booking.{id}`  | `WorkerArrived`                | Worker taps "I have arrived" via `POST …/arrive`. |

- **WorkerLocationUpdated** payload: `{ "latitude", "longitude", "workerId", "updatedAt" }` (ISO datetime).
- **WorkerArrived** payload: `{ "cleaningBookingId", "arrivedAt" }` (ISO datetime).

Use **Pusher** (or compatible) client in Flutter; configure with app key, cluster, and optional auth endpoint above for private channel subscription.

#### Server configuration (backend .env)

The backend must have broadcasting set to Pusher and the following environment variables set (values are server-specific; do not commit secrets):

| Variable | Description |
| -------- | ----------- |
| `BROADCAST_CONNECTION` | `pusher` |
| `PUSHER_APP_ID` | Pusher application ID |
| `PUSHER_APP_KEY` | Pusher application key (client needs this to connect) |
| `PUSHER_APP_SECRET` | Pusher secret (server-side only; never expose to client) |
| `PUSHER_APP_CLUSTER` | Pusher cluster (e.g. `eu`, `ap1`; client needs this to connect) |

The **Flutter app** needs only **Pusher key** and **cluster** (and the auth endpoint URL) to subscribe to private channels. Key and cluster are safe to expose; the secret is used only on the server for signing.

#### Client configuration (Flutter)

| Setting | Value |
| ------- | ----- |
| **Pusher key** | `e85e7756c1171baaa471` |
| **Pusher cluster** | `eu` |

Use these when initializing the Pusher client in the worker or customer app. Auth endpoint: `POST {baseUrl}/broadcasting/auth` with `Authorization: Bearer {token}`.

---

### 3.8 Update location (en route)

| Method | Path                                             | Description                    |
| ------ | ------------------------------------------------ | ------------------------------ |
| POST   | `/api/v1/cleaning-bookings/{id}/location`         | Send worker's current coordinates (broadcast to customer via Pusher) |

**Path params:** `id` – cleaning booking ID

**Request body:**

```json
{
  "latitude": 33.5138,
  "longitude": 36.2765
}
```

| Field     | Type   | Description                    |
| --------- | ------ | ------------------------------ |
| latitude  | number | Required; -90 to 90            |
| longitude | number | Required; -180 to 180          |

**Response (200):** `{ "data": { "ok": true } }`

**Errors:**
- `403` – User has no worker, or booking is not assigned to worker
- `422` – Worker has not started travel for this booking

**Valid when:** Status `worker_assigned` and `startedTravelAt` is set. Call after "I'm on my way" (start-travel). The app may send location periodically (e.g. every 10–30 s) while the order details map is open; each call broadcasts `WorkerLocationUpdated` to the booking's private channel so the customer sees the worker moving.

---

### 3.9 Mark arrived ("I have arrived")

| Method | Path                                       | Description                    |
| ------ | ------------------------------------------ | ------------------------------ |
| POST   | `/api/v1/cleaning-bookings/{id}/arrive`     | Worker marks arrival at order address |

**Path params:** `id` – cleaning booking ID

**Request body:** None

**Response (200):** Updated booking resource (includes `arrivedAt` set to current time; status remains `worker_assigned`). Server broadcasts `WorkerArrived` on the booking's private channel so the customer app can show "Worker has arrived" and prompt for security code / confirm start.

**Errors:**
- `403` – User has no worker, or booking is not assigned to worker
- `422` – Worker has not started travel, or booking not in `worker_assigned`

**Valid when:** Status `worker_assigned` and `startedTravelAt` is set. After this, worker shows security code and waits for customer to confirm; then worker calls **Start work** (§3.10).

---

### 3.10 Start work

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

### 3.11 Complete order

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

### 3.12 Cancel order

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

### 3.13 Extension requests (time warnings)

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

### 3.14 Client contact

Use `customer.phone` from the order details response (`GET /api/v1/cleaning-bookings/{id}`). No separate endpoint required.

---

### 3.15 Calendar (month/week view)

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

### 3.16 Working hours (CRUD-style)

Single resource for the **current worker’s working hours**. No `{id}` in the URL; worker is inferred from `Authorization: Bearer {token}`. Request and response use the same shape for easy load → edit → save.

| Method | Path                                   | Description                |
| ------ | -------------------------------------- | -------------------------- |
| GET    | `/api/v1/cleaning/worker/working-hours` | Get current working hours  |
| PUT    | `/api/v1/cleaning/worker/working-hours` | Update working hours       |

**GET – Request:** No body. No query params.

**GET – Response (200):**

```json
{
  "data": {
    "defaultWorkingHours": {
      "sunday": { "available": true, "data": [{ "09:00": "23:00" }] },
      "monday": { "available": true, "data": [{ "09:00": "13:00" }, { "15:00": "23:00" }] },
      "tuesday": { "available": true, "data": [{ "09:00": "23:00" }] },
      "wednesday": { "available": true, "data": [{ "09:00": "23:00" }] },
      "thursday": { "available": true, "data": [{ "09:00": "23:00" }] },
      "friday": { "available": true, "data": [{ "09:00": "23:00" }] },
      "saturday": { "available": false, "data": [] }
    }
  }
}
```

- All seven day keys are always present (day enum: `sunday` … `saturday`).
- Each day is an object: `available` (boolean) and `data` (array).
- Day off: `available: false`, `data: []`.
- Working day: `available: true`, `data: [{ "startTime": "endTime" }, ...]` (e.g. `{ "10:00": "16:00" }`). Times in HH:MM format.

**PUT – Request body:** Same shape. Send only `defaultWorkingHours` (object keyed by day enum).

```json
{
  "defaultWorkingHours": {
    "sunday": { "available": true, "data": [{ "09:00": "23:00" }] },
    "monday": { "available": true, "data": [{ "09:00": "13:00" }, { "15:00": "23:00" }] },
    "tuesday": { "available": true, "data": [{ "09:00": "23:00" }] },
    "wednesday": { "available": true, "data": [{ "09:00": "23:00" }] },
    "thursday": { "available": true, "data": [{ "09:00": "23:00" }] },
    "friday": { "available": true, "data": [{ "09:00": "23:00" }] },
    "saturday": { "available": false, "data": [] }
  }
}
```

**PUT – Response (200):** Same as GET response (updated `data.defaultWorkingHours`).

**Example – full request (PUT):**

```http
PUT https://dllni.mustafafares.com/api/v1/cleaning/worker/working-hours
Authorization: Bearer {token}
Content-Type: application/json

{
  "defaultWorkingHours": {
    "sunday": { "available": true, "data": [{ "09:00": "23:00" }] },
    "monday": { "available": true, "data": [{ "09:00": "13:00" }, { "15:00": "23:00" }] },
    "tuesday": { "available": true, "data": [{ "09:00": "23:00" }] },
    "wednesday": { "available": true, "data": [{ "09:00": "23:00" }] },
    "thursday": { "available": true, "data": [{ "09:00": "23:00" }] },
    "friday": { "available": true, "data": [{ "09:00": "23:00" }] },
    "saturday": { "available": false, "data": [] }
  }
}
```

**Example – full response (GET or PUT 200):** Resource shape `data`:

```json
{
  "data": {
    "defaultWorkingHours": {
      "sunday": { "available": true, "data": [{ "09:00": "23:00" }] },
      "monday": { "available": true, "data": [{ "09:00": "13:00" }, { "15:00": "23:00" }] },
      "tuesday": { "available": true, "data": [{ "09:00": "23:00" }] },
      "wednesday": { "available": true, "data": [{ "09:00": "23:00" }] },
      "thursday": { "available": true, "data": [{ "09:00": "23:00" }] },
      "friday": { "available": true, "data": [{ "09:00": "23:00" }] },
      "saturday": { "available": false, "data": [] }
    }
  }
}
```

**Errors:**
- `403` – User has no associated worker.
- `422` – Validation: keys of `defaultWorkingHours` must be day enum only; each day must be `{ "available": boolean, "data": array }`; each `data` item must be a single key-value object (e.g. `{ "10:00": "16:00" }`). Times in HH:MM format.

**Alternative:** Full worker update (e.g. admin or profile edit) remains `PUT /api/v1/workers/{id}` with body that can include `defaultWorkingHours` among other worker fields. For the worker app “ساعات العمل” screen, prefer the dedicated GET/PUT above.

---

### 3.17 Worker profile (current worker)

| Method | Path                                  | Description                                      |
| ------ | ------------------------------------- | ------------------------------------------------ |
| GET    | `/api/v1/cleaning/worker/profile`    | Current worker profile with user, zones, availability |

**Query params:** None. Worker is derived from `Authorization: Bearer {token}`.

**Response (200):** Same structure as `GET /api/v1/workers/{id}` (Worker resource with `user`, `zones`, `availability` loaded). Use for "My profile" / "حسابك" in the worker app.

**Errors:**
- `403` – User has no associated worker.

---

### 3.18 Security code for service start

| Method | Path                                             | Description                    |
| ------ | ------------------------------------------------ | ------------------------------ |
| GET    | `/api/v1/cleaning-bookings/{id}/security-code`  | Get 5-digit code to show customer to confirm service start |

**Path params:** `id` – cleaning booking ID

**Response (200):**

```json
{
  "data": {
    "securityCode": "35910"
  }
}
```

The code is generated on first request and stored; subsequent calls return the same code. Worker shows this code to the customer; customer enters it in their app to confirm service start.

**Errors:**
- `403` – User has no worker, or booking is not assigned to worker
- `422` – Booking must be in status `worker_assigned` or `in_progress`

**Valid statuses:** `worker_assigned`, `in_progress` only.

---

### 3.19 Disputes (worker – سجل المعاملات / نزاعات, تفاصيل النزاع)

Used for the "نزاعات" tab in the transaction log and the "Dispute details" screen (محتوى الشكوى, الرد على الشكوى). All dispute endpoints are scoped to the authenticated worker: only disputes linked to bookings assigned to that worker are visible.

#### List disputes (worker)

| Method | Path                   | Description                                |
| ------ | ---------------------- | ------------------------------------------ |
| GET    | `/api/v1/disputes`      | List disputes for current worker (paginated) |

**Query params:**

| Param                      | Type    | Description                                                |
| -------------------------- | ------- | ---------------------------------------------------------- |
| filter[forCurrentWorker]   | boolean | `true` or `1` – scope to disputes for worker’s bookings (required for worker app) |
| filter[status]             | string  | Filter by dispute status (see Section 4: DisputeStatus)    |
| filter[bookingId]          | integer | Filter by cleaning booking ID                              |
| perPage                    | integer | 1–100, default 20                                          |
| page                       | integer | Page number, default 1                                    |
| sort                       | string  | `openedAt`, `-openedAt`, `updatedAt`, `-updatedAt`         |

**Response (200):** Standard paginated collection. Each dispute includes:
- `id`, `bookingId`, `bookingNumber`, `status`, `category`, `openedAt`, `updatedAt`
- `complaintPreview` (string, optional) – short excerpt of complaint for list
- `booking`: `id`, `bookingNumber`, `scheduledDate`, `status`

#### Show dispute (worker)

| Method | Path                    | Description                    |
| ------ | ----------------------- | ------------------------------ |
| GET    | `/api/v1/disputes/{id}`  | Show one dispute (worker-scoped) |

**Path params:** `id` – dispute ID

**Response (200):** Single dispute resource with:
- `id`, `bookingId`, `bookingNumber`, `status`, `category`, `openedAt`, `updatedAt`
- `customerComplaint` (string) – full complaint text ("محتوى الشكوى")
- `complaintMedia` (array of URLs, optional) – attachments from customer
- `booking` – summary: `id`, `bookingNumber`, `scheduledDate`, `status`, `totalPrice`
- `messages` – array of thread messages: `id`, `senderType` (`customer` | `worker` | `support`), `content`, `createdAt`; used to show worker reply and support messages
- `workerRepliedAt` (string, optional) – ISO datetime when worker first sent a reply (for "الرد على الشكوى" vs "إرسال الرد" UX)

**Errors:**
- `403` – User has no worker, or dispute is not for a booking assigned to current worker
- `404` – Dispute not found

#### Submit worker response to dispute

| Method | Path                                | Description                    |
| ------ | ----------------------------------- | ------------------------------ |
| POST   | `/api/v1/disputes/{id}/messages`     | Worker sends a reply to the dispute |

**Path params:** `id` – dispute ID

**Request body:**

```json
{
  "message": "Worker response text (required, max 2000 chars)"
}
```

**Response (201):** Created message resource, or (200) with updated dispute including new message in `messages` array.

**Errors:**
- `403` – User has no worker, or dispute is not for a booking assigned to current worker
- `404` – Dispute not found
- `422` – Validation (e.g. empty message) or dispute already closed/resolved

**UI mapping:** "إرسال الرد" (Send Response) / "الرد على الشكوى" (Respond to Complaint) → POST to this endpoint with the worker’s text.

---

## 4. FCM push notifications (worker)

The backend uses **devkandil/notifire** (Laravel FCM) to send push notifications to the worker app. Only **necessary** notifications are sent (new order, extension request, dispute). The worker app must register the device FCM token and handle notification payloads for deep linking.

### 4.1 FCM token registration

| Method | Path                | Description                          |
| ------ | ------------------- | ------------------------------------ |
| POST   | `/fcm/token`        | Register or update the worker's FCM device token |

**Base URL:** Same as API: `https://dllni.mustafafares.com` (no `/api/v1` prefix for this route).

**Headers:** `Authorization: Bearer {token}`, `Content-Type: application/json`.

**Request body:**

```json
{
  "fcm_token": "device-fcm-token-from-firebase"
}
```

**Response (200):** `{ "success": true, "message": "FCM token updated successfully" }`

**Client:** Call this after login and whenever the FCM token is refreshed (e.g. `onTokenRefresh`). The backend stores the token on the authenticated user; workers are notified via their associated user.

### 4.2 When the backend sends FCM

| Trigger | When | Purpose |
| ------- | ---- | ------- |
| **New order request** | A cleaning booking enters `pending` and is eligible for the worker (e.g. in worker's zone / pool). | Worker must accept or reject within the time window (e.g. ~5 min). |
| **Extension request** | Customer requests more time (time warning created) for a booking assigned to this worker. | Worker should accept or reject the extension. |
| **Dispute opened** | A dispute is opened on a booking assigned to this worker. | Worker should reply (إرسال الرد). |

No FCM is sent for: booking cancelled by customer, booking completed, support reply in dispute (optional in a later phase), or generic marketing.

### 4.3 FCM payload convention (Flutter handling)

Backend sends notifications with **title**, **body**, and **data**. Use `data` for deep linking; do not rely on title/body for routing.

**Common `data` shape (camelCase):**

| Key           | Type    | Description |
| ------------- | ------- | ----------- |
| `type`        | string  | Notification type: `new_order`, `extension_request`, `dispute_opened` |
| `bookingId`   | integer | Cleaning booking ID (present for `new_order`, `extension_request`; optional for `dispute_opened`) |
| `timeWarningId` | integer | Time warning ID (present for `extension_request`) |
| `disputeId`   | integer | Dispute ID (present for `dispute_opened`) |

**Flutter:** On message received (foreground/background), read `data.type` and open the appropriate screen:

- `new_order` → Order detail or "New requests" list (booking id in `data.bookingId`).
- `extension_request` → Extension request detail or order detail (booking id, `data.timeWarningId`).
- `dispute_opened` → Dispute detail (data.disputeId) or "نزاعات" tab.

If `bookingId` or `disputeId` is missing, open the list screen (orders or disputes).

### 4.4 Backend implementation note (Laravel)

- Notifications are sent to the **User** model (worker's user). Ensure the user has `fcm_token` stored (via `POST /fcm/token`).
- Use Laravel notifications with channel `fcm` and `toFcm()` returning an `FcmMessage` (notifire). Include `data` with `type`, `bookingId`, `timeWarningId`, and/or `disputeId` as above.
- Trigger: **New order** when booking is created/eligible for worker; **Extension request** when time warning is created for the worker's booking; **Dispute opened** when dispute is created and linked to the worker's booking.
- **Queue:** All notification sends run via the queue (notification classes implement `ShouldQueue`). Observers dispatch jobs (`NotifyEligibleWorkersNewOrderJob`, `NotifyWorkerExtensionRequestJob`, `NotifyWorkerDisputeOpenedJob`) so heavy work (fetching eligible workers, FCM + database) does not block the request. Run `php artisan queue:work` (or a queue worker) for notifications to be sent.

### 4.5 User notifications (global list)

The authenticated user can list and manage their in-app notifications (same as FCM payload; stored in the database for the notification centre).

| Method | Path                                | Description                          |
| ------ | ----------------------------------- | ------------------------------------ |
| GET    | `/api/v1/notifications`             | List current user's notifications (paginated) |
| PATCH  | `/api/v1/notifications/{id}/read`   | Mark one notification as read        |

**GET – Query params:**

| Param             | Type    | Description                                  |
| ----------------- | ------- | -------------------------------------------- |
| perPage           | integer | 1–100, default 20                            |
| page              | integer | Page number, default 1                       |
| filter[unread]    | boolean | `true` or `1` – only unread notifications    |

**GET – Response (200):** Paginated collection. Each item has the **user notification format** below.

**PATCH – Path params:** `id` – notification UUID.

**PATCH – Response:** 204 No Content. Errors: 404 if notification not found or not owned by the user.

#### User notification format (each item in `data[]`)

| Field     | Type    | Description |
| --------- | ------- | ----------- |
| id        | string  | UUID of the notification (use for mark-as-read and deep link id) |
| type      | string  | `new_order`, `extension_request`, or `dispute_opened` |
| title     | string  | Localized title (e.g. "طلب جديد") |
| body      | string  | Localized body text |
| data      | object  | Payload for deep linking; only present keys are set (camelCase): `bookingId` (integer, optional), `timeWarningId` (integer, optional), `disputeId` (integer, optional) |
| readAt    | string  | ISO 8601 datetime when marked read, or `null` if unread |
| createdAt | string  | ISO 8601 datetime when the notification was created |

**Example – one item in `data[]`:**

```json
{
  "id": "9d4e8f2a-1b3c-4d5e-6f7a-8b9c0d1e2f3a",
  "type": "new_order",
  "title": "طلب جديد",
  "body": "طلب تنظيف جديد: CB-042. قم بقبوله أو رفضه خلال الوقت المحدد.",
  "data": {
    "bookingId": 42
  },
  "readAt": null,
  "createdAt": "2026-02-27T10:00:00.000000Z"
}
```

**Flutter:** Use the list for the in-app notification centre; use `type` and `data` for navigation when the user taps a notification (same as FCM §4.3).

---

## 5. Enums reference

Use these **string values** when filtering or displaying status/type labels. All values are snake_case.

### DayOfWeek (worker availability / defaultWorkingHours keys)

Use as **keys** of `defaultWorkingHours` when saving working hours (§3.16). Order below is Sunday–Saturday.

| Value        | Key for day   |
| ------------ | ------------- |
| `sunday`     | Sunday        |
| `monday`     | Monday        |
| `tuesday`    | Tuesday       |
| `wednesday`  | Wednesday     |
| `thursday`   | Thursday      |
| `friday`     | Friday        |
| `saturday`   | Saturday      |

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

### DisputeStatus (for filter and display in dispute list/detail)

| Value           | Description                    |
| --------------- | ------------------------------ |
| `open`          | Dispute open; worker may reply |
| `under_review`  | Support is reviewing           |
| `resolved`      | Resolved                       |
| `closed`        | Closed                         |

### DisputeCategory (complaint reason – display as label only)

| Value              | Description (localize in app)     |
| ------------------ | ---------------------------------- |
| `poor_quality`     | Service quality                    |
| `property_damage`  | Property damage                    |
| `unprofessional`   | Worker behavior                    |
| `billing_issue`    | Payment / billing                  |
| `other`            | Other                              |

---

## 6. Example requests and responses

### 5.0 Request and resource examples (quick reference)

| Endpoint / resource        | Request example | Resource (response `data`) example |
| ------------------------- | ----------------- | ----------------------------------- |
| **Working hours – GET**   | `GET /api/v1/cleaning/worker/working-hours` + `Authorization: Bearer {token}` | §6.0.1 below |
| **Working hours – PUT**   | `PUT /api/v1/cleaning/worker/working-hours` + body §6.0.1 | Same as GET response |
| **Cleaning booking (list item)** | `GET /api/v1/cleaning-bookings?filter[forCurrentWorker]=1&perPage=20` | §6.0.2 below |
| **Cleaning booking (detail)**   | `GET /api/v1/cleaning-bookings/{id}` | §6.0.3 below |
| **Worker profile**        | `GET /api/v1/cleaning/worker/profile` | §6.0.4 below |
| **Reject order**          | `POST /api/v1/cleaning-bookings/{id}/reject` + optional `{ "reason": "..." }` | Updated booking (status `cancelled`) |
| **Update location**       | `POST /api/v1/cleaning-bookings/{id}/location` + `{ "latitude": 33.51, "longitude": 36.27 }` | `{ "data": { "ok": true } }` |
| **Dispute – send message**| `POST /api/v1/disputes/{id}/messages` + `{ "message": "..." }` | 201 message resource or 200 dispute with `messages` |

#### 5.0.1 Working hours resource (GET response / PUT request body and response)

**Request (PUT):**

```json
{
  "defaultWorkingHours": {
    "sunday": { "available": true, "data": [{ "09:00": "23:00" }] },
    "monday": { "available": true, "data": [{ "09:00": "13:00" }, { "15:00": "23:00" }] },
    "tuesday": { "available": true, "data": [{ "09:00": "23:00" }] },
    "wednesday": { "available": true, "data": [{ "09:00": "23:00" }] },
    "thursday": { "available": true, "data": [{ "09:00": "23:00" }] },
    "friday": { "available": true, "data": [{ "09:00": "23:00" }] },
    "saturday": { "available": false, "data": [] }
  }
}
```

**Resource (response `data`):**

```json
{
  "data": {
    "defaultWorkingHours": {
      "sunday": { "available": true, "data": [{ "09:00": "23:00" }] },
      "monday": { "available": true, "data": [{ "09:00": "13:00" }, { "15:00": "23:00" }] },
      "tuesday": { "available": true, "data": [{ "09:00": "23:00" }] },
      "wednesday": { "available": true, "data": [{ "09:00": "23:00" }] },
      "thursday": { "available": true, "data": [{ "09:00": "23:00" }] },
      "friday": { "available": true, "data": [{ "09:00": "23:00" }] },
      "saturday": { "available": false, "data": [] }
    }
  }
}
```

#### 5.0.2 Cleaning booking – list item (one element of `data[]`)

```json
{
  "id": 42,
  "bookingNumber": "CB-042",
  "status": "worker_assigned",
  "scheduledDate": "2026-03-15",
  "scheduledTime": "10:00",
  "locationName": "Apartment - 3 rooms",
  "numberOfRooms": 3,
  "estimatedHours": 3,
  "totalHours": 3,
  "addressLatitude": 33.5138,
  "addressLongitude": 36.2765,
  "startedTravelAt": "2026-03-15T09:30:00.000000Z",
  "arrivedAt": null,
  "customer": { "id": 10, "name": "Ahmed", "email": "ahmed@example.com", "phone": "+963991234567" },
  "propertyType": "apartment",
  "propertyDetails": {},
  "totalPrice": 150.00,
  "workerEarnings": null,
  "hasDispute": false,
  "createdAt": "2026-03-10T12:00:00.000000Z",
  "updatedAt": "2026-03-10T12:00:00.000000Z"
}
```

#### 5.0.3 Cleaning booking – detail (single booking with relations)

```json
{
  "data": {
    "id": 42,
    "bookingNumber": "CB-042",
    "status": "worker_assigned",
    "scheduledDate": "2026-03-15",
    "scheduledTime": "10:00",
    "locationName": "Apartment - 3 rooms",
    "addressLatitude": 33.5138,
    "addressLongitude": 36.2765,
    "startedTravelAt": "2026-03-15T09:30:00.000000Z",
    "arrivedAt": null,
    "customer": { "id": 10, "name": "Ahmed", "email": "ahmed@example.com", "phone": "+963991234567" },
    "worker": { "id": 5, "firstName": "Omar", "user": { "id": 8, "name": "Omar", "email": "omar@example.com", "phone": "+963998765432" } },
    "services": [{ "id": 1, "name": "Standard cleaning", "quantity": 1 }],
    "addons": [],
    "billingPolicy": { "id": 1, "name": "Default" },
    "timeWarnings": [],
    "disputes": [],
    "totalPrice": 150.00,
    "createdAt": "2026-03-10T12:00:00.000000Z",
    "updatedAt": "2026-03-10T12:00:00.000000Z"
  }
}
```

#### 5.0.4 Worker profile resource (GET profile response `data`)

```json
{
  "data": {
    "id": 5,
    "userId": 8,
    "firstName": "Omar",
    "bio": "Experienced cleaner.",
    "averageRating": 4.8,
    "totalCompletedJobs": 120,
    "trustScore": 85,
    "acceptanceRate": 0.95,
    "cancellationRate": 0.02,
    "openDisputesCount": 0,
    "isActive": true,
    "isSuspended": false,
    "suspendedUntil": null,
    "homeAddress": "Damascus",
    "homeLatitude": 33.5138,
    "homeLongitude": 36.2765,
    "defaultWorkingHours": {
      "sunday": { "available": true, "data": [{ "09:00": "17:00" }] },
      "monday": { "available": true, "data": [{ "09:00": "17:00" }] },
      "tuesday": { "available": true, "data": [{ "09:00": "17:00" }] },
      "wednesday": { "available": true, "data": [{ "09:00": "17:00" }] },
      "thursday": { "available": true, "data": [{ "09:00": "17:00" }] },
      "friday": { "available": false, "data": [] },
      "saturday": { "available": false, "data": [] }
    },
    "user": { "id": 8, "name": "Omar", "email": "omar@example.com", "phone": "+963998765432" },
    "zones": [{ "id": 1, "zoneId": 10, "name": "Central" }],
    "availability": [],
    "createdAt": "2025-01-01T00:00:00.000000Z",
    "updatedAt": "2026-03-01T00:00:00.000000Z"
  }
}
```

#### 5.0.5 Validation error (422) – example

**Response (422):**

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "defaultWorkingHours": ["Day must be one of: sunday, monday, tuesday, wednesday, thursday, friday, saturday."]
  }
}
```

---

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

### Example 5: Transaction log – Disputes tab

**Request:**

```
GET https://dllni.mustafafares.com/api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[hasDispute]=true&perPage=20&sort=-scheduledDate
Authorization: Bearer {token}
```

**Response (200):** Paginated list of bookings that have an open dispute. Each item includes `hasDispute: true`, `workerEarnings` (if completed), and `disputes` or link to dispute detail.

---

### Example 6: Dispute detail and send response

**Request (show dispute):**

```
GET https://dllni.mustafafares.com/api/v1/disputes/7
Authorization: Bearer {token}
```

**Response (200):**

```json
{
  "data": {
    "id": 7,
    "bookingId": 34,
    "bookingNumber": "ORD-34",
    "status": "open",
    "category": "poor_quality",
    "openedAt": "2026-05-04T10:00:00.000000Z",
    "customerComplaint": "Random text showing the content of the complaint...",
    "complaintMedia": [],
    "workerRepliedAt": null,
    "messages": [],
    "booking": { "id": 34, "bookingNumber": "ORD-34", "scheduledDate": "2026-05-04", "status": "completed" }
  }
}
```

**Request (worker sends reply):**

```
POST https://dllni.mustafafares.com/api/v1/disputes/7/messages
Authorization: Bearer {token}
Content-Type: application/json

{
  "message": "هنا نص الرد على الشكوى"
}
```

**Response (201):** New message resource; or 200 with updated dispute including the new message in `messages`.

---

## 7. Error responses

- **401 Unauthorized:** Missing or invalid token. Redirect to login.
- **403 Forbidden:** User has no worker, or resource is not assigned to worker.
- **404 Not Found:** Resource not found.
- **422 Unprocessable Entity:** Validation errors or invalid status transition. Body includes `errors` object.
