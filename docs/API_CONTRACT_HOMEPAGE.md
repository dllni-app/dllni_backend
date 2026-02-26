# API Contract – Homepage Flows

**Audience:** Frontend / Flutter developer  
**Domain:** `dllni.mustafafares.com`  
**Scope:** Endpoints required for app homepages:
- **Part A:** Restaurant seller homepage dashboard
- **Part B:** Cleaning worker homepage (tasks for today, calendar, total orders)

For full CRUD, Auth, or other modules, see [API_CONTRACT_RESTAURANTS.md](API_CONTRACT_RESTAURANTS.md), [API_CONTRACT_CLEANING.md](API_CONTRACT_CLEANING.md), [API_CONTRACT_AUTH.md](API_CONTRACT_AUTH.md).

---

## 1. Base URL and authentication

- **Base URL:** `https://dllni.mustafafares.com`
- **API prefix:** `https://dllni.mustafafares.com/api/v1/`
- **Auth:** Laravel Sanctum. Send token on every request:
  - Header: `Authorization: Bearer {token}`
- **Content-Type:** `application/json` for request bodies; responses are JSON.

### 1.1 Client behavior (UI/API usage)

All GET (with id/enum), POST, and PUT usage must follow the client behavior rules in [API_CONTRACT_CLIENT_BEHAVIOR.md](API_CONTRACT_CLIENT_BEHAVIOR.md): select menu for id/enum in GET (user sees label only); backend-known data stored in page state and not shown/editable in POST; one dedicated input per user-supplied field in POST; optimistic local update for PUT, then persist on success or revert on failure.

---

## 2. Part A – Restaurant seller homepage data flow

The seller homepage displays:

1. **Today's overview** – total sales, order counts, low stock alerts, order activity chart
2. **New orders** – pending orders (accept/reject)
3. **Orders in preparation** – orders being prepared
4. **Quick actions** – Reports, Add Product, Offers, New Order

All data is scoped by `restaurantId`. The seller must know which restaurant they are managing (e.g. from user profile, role, or app state).

---

## 3. Part A – Restaurant seller endpoints

### 3.1 Dashboard overview (single request for KPIs)

| Method | Path                                    | Description                                                                 |
| ------ | --------------------------------------- | --------------------------------------------------------------------------- |
| GET    | `/api/v1/restaurant/dashboard/overview` | KPIs for today: sales, order counts, low stock, order activity by hour      |

**Query params:**

| Param         | Type   | Required | Description                    |
| ------------- | ------ | -------- | ------------------------------ |
| restaurantId  | integer| Yes      | Restaurant ID (exists:restaurants,id) |

**Example request:**

```
GET https://dllni.mustafafares.com/api/v1/restaurant/dashboard/overview?restaurantId=1
```

**Response (200):**

```json
{
  "kpis": {
    "todayTotalSales": 12450,
    "yesterdayTotalSales": 10826,
    "salesChangePercent": 15,
    "todayOrders": 188,
    "ordersByStatus": {
      "pending": 24,
      "accepted": 0,
      "preparing": 8,
      "completed": 156,
      "cancelled": 0,
      "readyForPickup": 0,
      "pickedUp": 0
    },
    "activeRestaurants": 1,
    "openDisputes": 2,
    "ordersPendingPickup": 4,
    "ordersReadyForPickup": 4,
    "lowStockAlertsCount": 1,
    "orderActivityByHour": [
      { "hour": 10, "count": 12 },
      { "hour": 11, "count": 18 },
      { "hour": 12, "count": 25 }
    ],
    "lowStockProducts": [
      {
        "id": 1,
        "name": "Product name",
        "stockQuantity": 5,
        "lowStockThreshold": 5
      }
    ]
  }
}
```

| Field                 | Type   | Description                                                                 |
| --------------------- | ------ | --------------------------------------------------------------------------- |
| todayTotalSales       | number | Sum of completed orders today (currency units)                             |
| yesterdayTotalSales   | number | Sum of completed orders yesterday                                           |
| salesChangePercent    | number | Percent change vs yesterday (e.g. 15 = +15%)                               |
| todayOrders           | number | Total orders created today                                                  |
| ordersByStatus        | object | Count per status: pending, accepted, preparing, completed, cancelled, etc.  |
| lowStockAlertsCount   | number | Number of products at or below low stock threshold                         |
| orderActivityByHour   | array  | `{ hour, count }` – orders per hour (0–23) for today                       |
| lowStockProducts      | array  | Top 10 low-stock products (id, name, stockQuantity, lowStockThreshold)      |

---

### 3.2 New orders (pending)

| Method | Path             | Description                          |
| ------ | ---------------- | ------------------------------------ |
| GET    | `/api/v1/orders` | List orders (paginated)              |

**Query params:**

| Param                    | Type    | Description                                      |
| ------------------------ | ------- | ------------------------------------------------ |
| filter[restaurantId]     | integer | Required. Scope to seller's restaurant           |
| filter[status]           | string  | `pending` for new orders                         |
| filter[createdToday]     | boolean | `true` to limit to orders created today          |
| perPage                  | integer | 1–100, default 20                                |
| page                     | integer | Page number, default 1                           |

**Example request:**

```
GET https://dllni.mustafafares.com/api/v1/orders?filter[restaurantId]=1&filter[status]=pending&filter[createdToday]=true&perPage=10
```

**Response (200):** Standard paginated collection. Each order includes `user`, `restaurant`, `orderItems` (with `product.id`, `product.name`), `totalAmount`, `status`, `createdAt`, etc.

---

### 3.3 Orders in preparation

| Method | Path             | Description                          |
| ------ | ---------------- | ------------------------------------ |
| GET    | `/api/v1/orders` | List orders (paginated)              |

**Query params:**

| Param                    | Type    | Description                                      |
| ------------------------ | ------- | ------------------------------------------------ |
| filter[restaurantId]     | integer | Required. Scope to seller's restaurant           |
| filter[status]           | string  | `preparing` for orders in preparation             |
| perPage                  | integer | 1–100, default 20                                |

**Example request:**

```
GET https://dllni.mustafafares.com/api/v1/orders?filter[restaurantId]=1&filter[status]=preparing
```

---

### 3.4 Low stock products (full list)

| Method | Path               | Description                          |
| ------ | ------------------ | ------------------------------------ |
| GET    | `/api/v1/products`| List products (paginated)           |

**Query params:**

| Param                    | Type    | Description                                      |
| ------------------------ | ------- | ------------------------------------------------ |
| filter[restaurantId]     | integer | Required. Scope to seller's restaurant           |
| filter[lowStock]         | boolean | `true` for products at or below threshold         |
| perPage                  | integer | 1–100, default 20                                |

**Example request:**

```
GET https://dllni.mustafafares.com/api/v1/products?filter[restaurantId]=1&filter[lowStock]=true
```

---

### 3.5 Accept order

| Method | Path                         | Description                    |
| ------ | ---------------------------- | ------------------------------ |
| POST   | `/api/v1/orders/{id}/accept` | Accept a pending order         |

**Path params:** `id` – order ID

**Request body:** None

**Response (200):**

```json
{
  "data": {
    "id": 123,
    "status": "accepted",
    "acceptedAt": "2025-02-21T14:30:00.000000Z",
    "user": { "id": 1, "name": "...", "email": "..." },
    "orderItems": [
      {
        "id": 1,
        "quantity": 2,
        "product": { "id": 5, "name": "Product name" }
      }
    ],
    ...
  },
  "message": "Order accepted successfully."
}
```

---

### 3.6 Reject order

| Method | Path                         | Description                    |
| ------ | ---------------------------- | ------------------------------ |
| POST   | `/api/v1/orders/{id}/reject` | Reject a pending order         |

**Path params:** `id` – order ID

**Request body:** None

**Response (200):**

```json
{
  "data": {
    "id": 123,
    "status": "cancelled",
    "cancelledAt": "2025-02-21T14:30:00.000000Z",
    "cancellationReason": "Rejected by seller",
    ...
  },
  "message": "Order rejected successfully."
}
```

---

### 3.7 Quick actions (reference)

| Action        | Endpoint                          | Method |
| ------------- | --------------------------------- | ------ |
| Reports       | `/api/v1/restaurant/analytics/daily-stats`   | GET    |
| Reports       | `/api/v1/restaurant/analytics/monthly-stats`| GET    |
| Add Product   | `/api/v1/products`                | POST   |
| Offers        | `/api/v1/offers`                  | POST   |
| New Order     | `/api/v1/orders`                  | POST   |

See [API_CONTRACT_RESTAURANTS.md](API_CONTRACT_RESTAURANTS.md) for request/response schemas.

---

## 4. Order status values

Use these when filtering or displaying status:

| Value           | Description        |
| --------------- | ------------------ |
| `pending`       | New, awaiting accept/reject |
| `accepted`       | Accepted by seller |
| `preparing`     | In preparation     |
| `ready_for_pickup` | Ready for pickup |
| `picked_up`     | Picked up          |
| `completed`     | Completed          |
| `cancelled`     | Cancelled/rejected |

---

## 5. Error responses

- **401 Unauthorized:** Missing or invalid token. Redirect to login.
- **422 Unprocessable Entity:** Validation errors (e.g. invalid `restaurantId`). Body includes `errors` object.
- **404 Not Found:** Order or resource not found.
- **403 Forbidden:** User not allowed to access the resource.

---

## 6. Part B – Cleaning worker homepage

**Scope:** Authenticated user must have an associated **worker** (e.g. `user.worker`). All data is scoped to that worker.

### 6.1 Data flow

The cleaning worker homepage displays:

1. **Tasks for today** – list of cleaning bookings for the current day (with location, client, duration, status, rooms, call action).
2. **Calendar** – bookings per day for a given month (date range).
3. **Total order section** – aggregated stats: total bookings, today count, completed, pending, in progress, cancelled, total earnings.

### 6.2 Worker homepage stats (single request)

| Method | Path                                  | Description                                      |
| ------ | ------------------------------------- | ------------------------------------------------ |
| GET    | `/api/v1/cleaning/worker/homepage`    | Total order stats for the authenticated worker   |

**Query params:** None. Worker is derived from `Authorization: Bearer {token}` (user must have `worker`).

**Example request:**

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

| Field                         | Type   | Description                                                       |
| ----------------------------- | ------ | ----------------------------------------------------------------- |
| date                          | string | Current date for the overview (YYYY-MM-DD)                        |
| totalBookings                 | number | All-time bookings assigned to this worker                         |
| todayCount                    | number | Bookings scheduled for today (excluding cancelled)                 |
| completedCount                | number | Bookings with status `completed`                                  |
| pendingCount                  | number | Upcoming bookings in pending/confirmed/assigned states             |
| inProgressCount               | number | Bookings with status `in_progress`                                |
| cancelledCount                | number | Bookings with status `cancelled`                                  |
| totalEarnings                 | number | Sum of `totalPrice` for completed bookings                        |
| todayEarnings                 | number | Sum of `totalPrice` for completed bookings today                   |
| earningsChangePercent        | number | Percentage change of today's earnings vs yesterday                 |
| newOrdersCount               | number | Bookings with status `pending` (worker_id null or current worker)  |
| pendingExtensionRequestsCount | number | Extension requests awaiting worker response                       |

If the user has no worker, all values are `0`.

---

### 6.3 Tasks for today

| Method | Path                        | Description                          |
| ------ | --------------------------- | ------------------------------------ |
| GET    | `/api/v1/cleaning-bookings` | List cleaning bookings (paginated)   |

**Query params:**

| Param                      | Type    | Description                                              |
| -------------------------- | ------- | -------------------------------------------------------- |
| filter[forCurrentWorker]   | boolean | `true` or `1` – scope to authenticated worker (required for worker app) |
| filter[scheduledDate]      | string  | Date (YYYY-MM-DD), e.g. today for “Tasks for today”      |
| perPage                    | integer | 1–100, default 20                                       |
| page                       | integer | Page number, default 1                                   |

**Example request:**

```
GET https://dllni.mustafafares.com/api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[scheduledDate]=2025-02-21&perPage=20
```

**Response (200):** Standard paginated collection. Each booking includes:

- `id`, `bookingNumber`, `status`, `scheduledDate`, `scheduledTime`
- `locationName`, `numberOfRooms`, `estimatedHours`, `totalHours`
- `customer`: `id`, `name`, `email`, `phone` (for call button)
- `propertyType`, `propertyDetails`, `totalPrice`, `createdAt`, `updatedAt`

---

### 6.4 Calendar (month view)

| Method | Path                        | Description                          |
| ------ | --------------------------- | ------------------------------------ |
| GET    | `/api/v1/cleaning-bookings` | List cleaning bookings (paginated)   |

**Query params:**

| Param                      | Type    | Description                                              |
| -------------------------- | ------- | -------------------------------------------------------- |
| filter[forCurrentWorker]   | boolean | `true` or `1` – scope to authenticated worker            |
| filter[scheduledDateFrom] | string  | Start of range (YYYY-MM-DD), e.g. first day of month    |
| filter[scheduledDateTo]   | string  | End of range (YYYY-MM-DD), e.g. last day of month       |
| perPage                    | integer | 1–100 (use higher value for full month, e.g. 100)       |
| sort                       | string  | Optional: `scheduledDate`, `-scheduledDate`             |

**Example request (April 2025):**

```
GET https://dllni.mustafafares.com/api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[scheduledDateFrom]=2025-04-01&filter[scheduledDateTo]=2025-04-30&perPage=100
```

**Response (200):** Same structure as Tasks for today. Frontend groups by `scheduledDate` for calendar cells; use `status` for color-coding (e.g. pending, confirmed, completed, cancelled).

---

### 6.5 Cleaning booking status values (worker context)

| Value               | Description           |
| ------------------- | --------------------- |
| `pending`           | Pending               |
| `confirmed`         | Confirmed             |
| `worker_assigned`   | Worker assigned       |
| `worker_on_the_way` | Worker on the way     |
| `worker_arrived`    | Worker arrived        |
| `in_progress`       | In progress           |
| `completed`          | Completed             |
| `cancelled`         | Cancelled             |

---

### 6.6 Part B error responses

- **401 Unauthorized:** Missing or invalid token.
- **422 Unprocessable Entity:** Validation errors on filter params (e.g. invalid date).
- **404 Not Found:** Resource not found.
- **403 Forbidden:** User not allowed to access the resource.
