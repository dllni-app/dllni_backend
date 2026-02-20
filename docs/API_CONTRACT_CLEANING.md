# API Contract for Flutter – Cleaning Admin Dashboard

**Audience:** Flutter developer  
**Domain:** `dllni.mustafafares.com`  
**Scope:** Cleaning admin dashboard only. For Restaurants, see [API_CONTRACT_FLUTTER_RESTAURANTS.md](API_CONTRACT_FLUTTER_RESTAURANTS.md).

---

## 1. Base URL and authentication

- **Base URL:** `https://dllni.mustafafares.com`
- **API prefix:** All endpoints below are relative to base URL: `https://dllni.mustafafares.com/api/v1/...`
- **Auth:** Laravel Sanctum. Send token on every request:
  - Header: `Authorization: Bearer {token}`
  - Login/token issuance is out of scope of this contract (use existing auth endpoints).
- **Content-Type:** `application/json` for request bodies; responses are JSON.

---

## 2. Global conventions

### 2.1 Pagination (index endpoints)

- **Query parameters:** `perPage` (integer, 1–100, default 20), `page` (integer, default 1).
- **Response (paginated list):** Laravel API Resource collection format:
  - `data`: array of resource objects
  - `links`: `first`, `last`, `prev`, `next` (URLs)
  - `meta`: `current_page`, `from`, `last_page`, `path`, `per_page`, `to`, `total`

### 2.2 Filtering and sorting (index endpoints)

- **Filters:** Pass as query params: `filter[fieldName]=value`. Multiple filters are ANDed. Exact values unless noted.
- **Sort:** `sort=field` (asc) or `sort=-field` (desc). Default is typically `-created_at`.
- **Search:** When available, `filter[search]=...` (partial match; backend-defined fields).

### 2.3 Request/response casing

- **JSON:** camelCase for all keys (e.g. `createdAt`, `perPage`, `scheduledDateFrom`).

### 2.4 Single-resource responses (show/store/update)

- **Success:** HTTP 200 (show/update) or 201 (store). Body: `{ "data": { ...resource object } }`.
- **Delete:** HTTP 204 No Content, empty body.
- **Errors:** 4xx/5xx with JSON body; standard Laravel validation errors under `errors` (keyed by field).

---

## 3. Cleaning module endpoints

Base path: `/api/v1/` (all under `auth:sanctum`).

### 3.1 Dashboard overview (custom)

| Method | Path                                  | Description                   |
| ------ | ------------------------------------- | ----------------------------- |
| GET    | `/api/v1/cleaning/dashboard/overview` | KPIs and recent system alerts |

**Response (200):**

```json
{
  "kpis": {
    "todayCleaningBookings": 12,
    "todayEventBookings": 3,
    "openDisputes": 2,
    "pendingWorkerAssignments": 5,
    "activeSosCount": 0
  },
  "alerts": [
    { "id": 1, "alertType": "...", "severity": "...", "status": "...", "booking": {...}, ... }
  ]
}
```

### 3.2 Worker homepage (custom)

| Method | Path                                  | Description                                      |
| ------ | ------------------------------------- | ------------------------------------------------ |
| GET    | `/api/v1/cleaning/worker/homepage`    | Total order stats for the authenticated worker   |

**Response (200):**

```json
{
  "totalBookings": 42,
  "todayCount": 2,
  "completedCount": 35,
  "pendingCount": 5,
  "inProgressCount": 1,
  "cancelledCount": 1,
  "totalEarnings": 5250.00
}
```

**Note:** Requires authenticated user with an associated worker. Returns zeros if user has no worker.

### 3.3 Cleaning bookings (full CRUD)

| Method    | Path                             | Description                  |
| --------- | -------------------------------- | ---------------------------- |
| GET       | `/api/v1/cleaning-bookings`      | List (paginated, filterable) |
| GET       | `/api/v1/cleaning-bookings/{id}` | Show one                     |
| POST      | `/api/v1/cleaning-bookings`      | Create                       |
| PUT/PATCH | `/api/v1/cleaning-bookings/{id}` | Update                       |
| DELETE    | `/api/v1/cleaning-bookings/{id}` | Delete                       |

**Index query params:** `perPage`, `page`, `sort`, and:

- `filter[status]` – CleaningBookingStatus (use snake_case values from Section 6)
- `filter[scheduledDateFrom]`, `filter[scheduledDateTo]` – date (YYYY-MM-DD)
- `filter[scheduledDate]` – exact date (e.g. for "Tasks for Today")
- `filter[customerId]`, `filter[workerId]` – integer IDs
- `filter[forCurrentWorker]` – boolean; when true, filters by authenticated worker (worker app)
- `filter[hasDispute]` – boolean/scope

**Worker homepage usage:**
- **Tasks for Today:** `filter[forCurrentWorker]=true&filter[scheduledDate]=YYYY-MM-DD`
- **Calendar (month):** `filter[forCurrentWorker]=true&filter[scheduledDateFrom]=YYYY-MM-01&filter[scheduledDateTo]=YYYY-MM-DD`

**Show relations:** customer, worker, services, addons, billingPolicy, timeWarnings, disputes.

**Resource fields for worker cards:** `locationName`, `numberOfRooms`, `customer.phone`, `estimatedHours`, `totalHours`, `status`.

### 3.4 Event bookings (full CRUD)

| Method    | Path                          | Description                  |
| --------- | ----------------------------- | ---------------------------- |
| GET       | `/api/v1/event-bookings`      | List (paginated, filterable) |
| GET       | `/api/v1/event-bookings/{id}` | Show one                     |
| POST      | `/api/v1/event-bookings`      | Create                       |
| PUT/PATCH | `/api/v1/event-bookings/{id}` | Update                       |
| DELETE    | `/api/v1/event-bookings/{id}` | Delete                       |

**Index query params:** `filter[status]` (EventBookingStatus), `filter[eventType]` (EventType), `filter[scheduledDateFrom]`, `filter[scheduledDateTo]`.

### 3.5 Cleaning time warnings (read-only)

| Method | Path                                  | Description      |
| ------ | ------------------------------------- | ---------------- |
| GET    | `/api/v1/cleaning-time-warnings`      | List (paginated) |
| GET    | `/api/v1/cleaning-time-warnings/{id}` | Show one         |

**Index query params:** `filter[bookingId]`, `filter[bookingType]`, `filter[sentAtFrom]`, `filter[sentAtTo]` (if supported).

### 3.6 Cleaning services and pricing (full CRUD)

| Method    | Path                             | Description            |
| --------- | -------------------------------- | ---------------------- |
| GET       | `/api/v1/cleaning-services`      | List cleaning services |
| GET       | `/api/v1/cleaning-services/{id}` | Show one               |
| POST      | `/api/v1/cleaning-services`      | Create                 |
| PUT/PATCH | `/api/v1/cleaning-services/{id}` | Update                 |
| DELETE    | `/api/v1/cleaning-services/{id}` | Delete                 |

| Method    | Path                                                   | Description                |
| --------- | ------------------------------------------------------ | -------------------------- |
| GET       | `/api/v1/cleaning-services/{cleaning_service}/pricing` | List pricing for service   |
| GET       | `/api/v1/pricing/{id}`                                 | Show one pricing (shallow) |
| POST      | `/api/v1/cleaning-services/{cleaning_service}/pricing` | Create pricing             |
| PUT/PATCH | `/api/v1/pricing/{id}`                                 | Update pricing             |
| DELETE    | `/api/v1/pricing/{id}`                                 | Delete pricing             |

### 3.7 Cleaning billing policies (full CRUD)

| Method    | Path                                     | Description |
| --------- | ---------------------------------------- | ----------- |
| GET       | `/api/v1/cleaning-billing-policies`      | List        |
| GET       | `/api/v1/cleaning-billing-policies/{id}` | Show        |
| POST      | `/api/v1/cleaning-billing-policies`      | Create      |
| PUT/PATCH | `/api/v1/cleaning-billing-policies/{id}` | Update      |
| DELETE    | `/api/v1/cleaning-billing-policies/{id}` | Delete      |

### 3.8 Geographic coverage (custom)

| Method | Path                                             | Description                                                    |
| ------ | ------------------------------------------------ | -------------------------------------------------------------- |
| GET    | `/api/v1/cleaning/analytics/geographic-coverage` | Aggregated demand vs coverage by zone (for heatmap/simulation) |

**Response:** Structure TBD by backend (zone-based metrics).

---

## 4. Shared (app) endpoints – used by Cleaning dashboard

Base path: `/api/v1/` (all under `auth:sanctum`).

### 4.1 Workers (full CRUD)

| Method    | Path                   | Description                                               |
| --------- | ---------------------- | --------------------------------------------------------- |
| GET       | `/api/v1/workers`      | List (paginated)                                          |
| GET       | `/api/v1/workers/{id}` | Show (with user, zones, availability, trustLogs, reviews) |
| POST      | `/api/v1/workers`      | Create                                                    |
| PUT/PATCH | `/api/v1/workers/{id}` | Update                                                    |
| DELETE    | `/api/v1/workers/{id}` | Delete                                                    |

**Index query params:** `filter[trustScoreMin]`, `filter[trustScoreMax]`, `filter[isActive]`, `filter[isSuspended]`, `filter[search]`.

### 4.2 Disputes (full CRUD)

| Method    | Path                    | Description                    |
| --------- | ----------------------- | ------------------------------ |
| GET       | `/api/v1/disputes`      | List (paginated)               |
| GET       | `/api/v1/disputes/{id}` | Show (booking morph, messages) |
| POST      | `/api/v1/disputes`      | Create                         |
| PUT/PATCH | `/api/v1/disputes/{id}` | Update                         |
| DELETE    | `/api/v1/disputes/{id}` | Delete                         |

**Index query params:** `filter[status]`, `filter[category]`, `filter[bookingType]` (e.g. cleaning_booking, event_booking).

### 4.3 System alerts (index, show, update)

| Method    | Path                         | Description                             |
| --------- | ---------------------------- | --------------------------------------- |
| GET       | `/api/v1/system-alerts`      | List (paginated)                        |
| GET       | `/api/v1/system-alerts/{id}` | Show                                    |
| PUT/PATCH | `/api/v1/system-alerts/{id}` | Update (e.g. resolve/dismiss: `status`) |

**Index query params:** `filter[status]`, `filter[alertType]`, `filter[severity]`.

### 4.4 SOS alerts (read-only)

| Method | Path                      | Description      |
| ------ | ------------------------- | ---------------- |
| GET    | `/api/v1/sos-alerts`      | List (paginated) |
| GET    | `/api/v1/sos-alerts/{id}` | Show             |

**Index query params:** `filter[status]`, `filter[emergencyType]`.

### 4.5 Service add-ons (full CRUD)

| Method    | Path                          | Description |
| --------- | ----------------------------- | ----------- |
| GET       | `/api/v1/service-addons`      | List        |
| GET       | `/api/v1/service-addons/{id}` | Show        |
| POST      | `/api/v1/service-addons`      | Create      |
| PUT/PATCH | `/api/v1/service-addons/{id}` | Update      |
| DELETE    | `/api/v1/service-addons/{id}` | Delete      |

### 4.6 Travel cost configs (full CRUD)

| Method    | Path                               | Description |
| --------- | ---------------------------------- | ----------- |
| GET       | `/api/v1/travel-cost-configs`      | List        |
| GET       | `/api/v1/travel-cost-configs/{id}` | Show        |
| POST      | `/api/v1/travel-cost-configs`      | Create      |
| PUT/PATCH | `/api/v1/travel-cost-configs/{id}` | Update      |
| DELETE    | `/api/v1/travel-cost-configs/{id}` | Delete      |

---

## 5. Enums reference (Cleaning + shared)

Use these **string values** when sending filters or displaying status/type labels. All values are snake_case as returned and accepted by the API.

### Cleaning module

| Enum                      | Values                                                                                                                                 |
| ------------------------- | -------------------------------------------------------------------------------------------------------------------------------------- |
| **CleaningBookingStatus** | `pending`, `confirmed`, `worker_assigned`, `worker_on_the_way`, `worker_arrived`, `in_progress`, `completed`, `cancelled`              |
| **EventBookingStatus**    | `pending`, `confirmed`, `team_assigned`, `in_progress`, `completed`, `cancelled`                                                       |
| **EventType**             | `family_dinner`, `birthday`, `large_gathering`, `funeral`, `other`                                                                      |
| **CleaningTimeWarningResponse** | `extend_time`, `commit_current_time`, `finish_early`                                                                          |

### Shared (app) – Cleaning context

| Enum                  | Values                                                                                                                                    |
| --------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- |
| **DisputeStatus**     | `open`, `under_review`, `resolved`, `closed`                                                                                              |
| **DisputeCategory**   | `poor_quality`, `property_damage`, `unprofessional`, `billing_issue`, `other`                                                            |
| **SystemAlertStatus** | `new`, `acknowledged`, `resolved`                                                                                                         |
| **AlertType**         | `delayed_rating`, `frozen_gps`, `sos_triggered`, `time_expired`, `overdue_completion`, `anomaly_detected`                                  |
| **AlertSeverity**     | `low`, `medium`, `high`, `critical`                                                                                                      |
| **SOSStatus**         | `triggered`, `acknowledged`, `resolved`                                                                                                    |
| **EmergencyType**     | `safety_threat`, `medical_emergency`, `severe_conflict`                                                                                     |

---

## 6. Example requests and responses

### Example 1: List cleaning bookings with filters

**Request:**

```
GET https://dllni.mustafafares.com/api/v1/cleaning-bookings?perPage=10&page=1&filter[status]=confirmed&filter[scheduledDateFrom]=2025-02-01&filter[scheduledDateTo]=2025-02-28
```

**Headers:** `Authorization: Bearer {token}`, `Accept: application/json`

### Example 2: Paginated response (cleaning bookings)

**Response (200):**

```json
{
  "data": [
    {
      "id": 1,
      "status": "confirmed",
      "scheduledDate": "2025-02-15",
      "customer": { "id": 10, "name": "Customer A" },
      "worker": { "id": 5, "name": "Worker X" },
      "totalPrice": 150.00,
      "createdAt": "2025-02-01T10:00:00.000000Z",
      "updatedAt": "2025-02-01T10:00:00.000000Z"
    }
  ],
  "links": {
    "first": "https://dllni.mustafafares.com/api/v1/cleaning-bookings?page=1",
    "last": "https://dllni.mustafafares.com/api/v1/cleaning-bookings?page=5",
    "prev": null,
    "next": "https://dllni.mustafafares.com/api/v1/cleaning-bookings?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 5,
    "path": "https://dllni.mustafafares.com/api/v1/cleaning-bookings",
    "per_page": 10,
    "to": 10,
    "total": 48
  }
}
```

### Example 3: Validation error (422)

**Response (422 Unprocessable Entity):**

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["The email field is required."],
    "status": ["The selected status is invalid."]
  }
}
```
