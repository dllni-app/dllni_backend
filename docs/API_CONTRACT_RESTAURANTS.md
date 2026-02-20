# API Contract for Flutter – Restaurant Admin Dashboard

**Audience:** Flutter developer  
**Domain:** `dllni.mustafafares.com`  
**Scope:** Restaurant admin dashboard only. For Cleaning, see [API_CONTRACT_FLUTTER_CLEANING.md](API_CONTRACT_FLUTTER_CLEANING.md).

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

## 3. Restaurant module endpoints

Base path: `/api/v1/` (all under `auth:sanctum`).

### 3.1 Dashboard overview (custom)

| Method | Path                                    | Description                                                                 |
| ------ | --------------------------------------- | --------------------------------------------------------------------------- |
| GET    | `/api/v1/restaurant/dashboard/overview` | KPIs for today orders, sales, disputes, low stock (seller-scoped by restaurant) |

**Query params:** `restaurantId` (required, exists:restaurants,id)

**Response (200):**

```json
{
  "kpis": {
    "todayTotalSales": 12450,
    "yesterdayTotalSales": 10826,
    "salesChangePercent": 15,
    "todayOrders": 188,
    "ordersByStatus": { "pending": 24, "accepted": 0, "preparing": 8, "completed": 156, "cancelled": 0, "ready_for_pickup": 0, "picked_up": 0 },
    "activeRestaurants": 1,
    "openDisputes": 2,
    "ordersPendingPickup": 4,
    "ordersReadyForPickup": 4,
    "lowStockAlertsCount": 1,
    "orderActivityByHour": [
      { "hour": 10, "count": 12 },
      { "hour": 11, "count": 18 }
    ],
    "lowStockProducts": [
      { "id": 1, "name": "Product name", "stockQuantity": 5, "lowStockThreshold": 5 }
    ]
  }
}
```

### 3.2 Restaurants (full CRUD)

| Method    | Path                       | Description      |
| --------- | -------------------------- | ---------------- |
| GET       | `/api/v1/restaurants`      | List (paginated) |
| GET       | `/api/v1/restaurants/{id}` | Show             |
| POST      | `/api/v1/restaurants`      | Create           |
| PUT/PATCH | `/api/v1/restaurants/{id}` | Update           |
| DELETE    | `/api/v1/restaurants/{id}` | Delete           |

**Index query params:** `filter[isActive]`, `filter[isFeatured]`, `filter[isSuspended]`, `filter[cuisineType]`, `filter[priceRange]`, `filter[reputationScoreMin]`, `filter[reputationScoreMax]`, `filter[search]`.  
**Show relations:** user, operatingHours, documents, cuisineTypes, reputationLogs, penalties.

### 3.3 Categories (full CRUD)

| Method    | Path                      | Description |
| --------- | ------------------------- | ----------- |
| GET       | `/api/v1/categories`      | List        |
| GET       | `/api/v1/categories/{id}` | Show        |
| POST      | `/api/v1/categories`      | Create      |
| PUT/PATCH | `/api/v1/categories/{id}` | Update      |
| DELETE    | `/api/v1/categories/{id}` | Delete      |

**Index query params:** `filter[restaurantId]`, `filter[search]`.

### 3.4 Products (full CRUD)

| Method    | Path                    | Description                                                |
| --------- | ----------------------- | ---------------------------------------------------------- |
| GET       | `/api/v1/products`      | List                                                       |
| GET       | `/api/v1/products/{id}` | Show (restaurant, category, modifierGroups, substitutions) |
| POST      | `/api/v1/products`      | Create                                                     |
| PUT/PATCH | `/api/v1/products/{id}` | Update                                                     |
| DELETE    | `/api/v1/products/{id}` | Delete                                                     |

**Index query params:** `filter[restaurantId]`, `filter[categoryId]`, `filter[isAvailable]`, `filter[lowStock]`, `filter[isFeatured]`, `filter[search]`.

### 3.5 Orders (full CRUD)

| Method    | Path                  | Description                                                                              |
| --------- | --------------------- | ---------------------------------------------------------------------------------------- |
| GET       | `/api/v1/orders`      | List                                                                                     |
| GET       | `/api/v1/orders/{id}` | Show (user, restaurant, orderItems with product, orderStatusLogs, promoCode, assignedStaff, disputes) |
| POST      | `/api/v1/orders`      | Create                                                                                   |
| PUT/PATCH | `/api/v1/orders/{id}` | Update                                                                                   |
| DELETE    | `/api/v1/orders/{id}` | Delete                                                                                   |
| POST      | `/api/v1/orders/{id}/accept` | Accept order (status → accepted, accepted_at)                                    |
| POST      | `/api/v1/orders/{id}/reject` | Reject order (status → cancelled, cancellation_reason)                             |

**Index query params:** `filter[status]`, `filter[restaurantId]`, `filter[orderType]`, `filter[pickupMode]`, `filter[dateFrom]`, `filter[dateTo]`, `filter[createdToday]` (boolean), `filter[hasDispute]`.

### 3.6 Offers (full CRUD)

| Method    | Path                  | Description |
| --------- | --------------------- | ----------- |
| GET       | `/api/v1/offers`      | List        |
| GET       | `/api/v1/offers/{id}` | Show        |
| POST      | `/api/v1/offers`      | Create      |
| PUT/PATCH | `/api/v1/offers/{id}` | Update      |
| DELETE    | `/api/v1/offers/{id}` | Delete      |

**Index query params:** `filter[restaurantId]`, `filter[isActive]`, `filter[startsAtFrom]`, `filter[endsAtTo]`.

### 3.7 Promo codes (full CRUD)

| Method    | Path                       | Description |
| --------- | -------------------------- | ----------- |
| GET       | `/api/v1/promo-codes`      | List        |
| GET       | `/api/v1/promo-codes/{id}` | Show        |
| POST      | `/api/v1/promo-codes`      | Create      |
| PUT/PATCH | `/api/v1/promo-codes/{id}` | Update      |
| DELETE    | `/api/v1/promo-codes/{id}` | Delete      |

**Index query params:** `filter[restaurantId]`, `filter[isActive]`, `filter[startsAtFrom]`, `filter[endsAtTo]`.

### 3.8 Restaurant order disputes (full CRUD)

| Method    | Path                                     | Description            |
| --------- | ---------------------------------------- | ---------------------- |
| GET       | `/api/v1/restaurant-order-disputes`      | List                   |
| GET       | `/api/v1/restaurant-order-disputes/{id}` | Show (order, messages) |
| POST      | `/api/v1/restaurant-order-disputes`      | Create                 |
| PUT/PATCH | `/api/v1/restaurant-order-disputes/{id}` | Update                 |
| DELETE    | `/api/v1/restaurant-order-disputes/{id}` | Delete                 |

**Index query params:** `filter[status]`, `filter[restaurantId]`, `filter[dateFrom]`, `filter[dateTo]`.

### 3.9 Restaurant documents (full CRUD)

| Method    | Path                                | Description                                       |
| --------- | ----------------------------------- | ------------------------------------------------- |
| GET       | `/api/v1/restaurant-documents`      | List                                              |
| GET       | `/api/v1/restaurant-documents/{id}` | Show                                              |
| POST      | `/api/v1/restaurant-documents`      | Create                                            |
| PUT/PATCH | `/api/v1/restaurant-documents/{id}` | Update (e.g. verification_status: Approve/Reject) |
| DELETE    | `/api/v1/restaurant-documents/{id}` | Delete                                            |

**Index query params:** `filter[restaurantId]`, `filter[documentType]`, `filter[verificationStatus]`.

### 3.10 Reputation logs and penalties (read-only)

| Method | Path                                      | Description      |
| ------ | ----------------------------------------- | ---------------- |
| GET    | `/api/v1/restaurant-reputation-logs`      | List (paginated) |
| GET    | `/api/v1/restaurant-reputation-logs/{id}` | Show             |
| GET    | `/api/v1/restaurant-penalties`            | List (paginated) |
| GET    | `/api/v1/restaurant-penalties/{id}`       | Show             |

**Reputation logs params:** `filter[restaurantId]`, `filter[dateFrom]`, `filter[dateTo]`.  
**Penalties params:** `filter[restaurantId]`, `filter[type]` (PenaltyType).

### 3.11 Restaurant staff and roles (read-only)

| Method | Path                            | Description                                          |
| ------ | ------------------------------- | ---------------------------------------------------- |
| GET    | `/api/v1/restaurant-staff`      | List (use `filter[restaurantId]` for per-restaurant) |
| GET    | `/api/v1/restaurant-staff/{id}` | Show                                                 |
| GET    | `/api/v1/restaurant-roles`      | List (use `filter[restaurantId]` if supported)       |
| GET    | `/api/v1/restaurant-roles/{id}` | Show                                                 |

### 3.12 Analytics (custom)

| Method | Path                                         | Description                                           |
| ------ | -------------------------------------------- | ----------------------------------------------------- |
| GET    | `/api/v1/restaurant/analytics/daily-stats`   | Daily stats (query: restaurantId, dateFrom, dateTo)   |
| GET    | `/api/v1/restaurant/analytics/monthly-stats` | Monthly stats (query: restaurantId, dateFrom, dateTo) |

### 3.13 Assistant queries, recurring orders, reviews (read-only)

| Method | Path                                        | Description                                                                      |
| ------ | ------------------------------------------- | -------------------------------------------------------------------------------- |
| GET    | `/api/v1/restaurant-assistant-queries`      | List (filter: restaurantId, userId, inputMode, hasRecipeMatch, dateFrom, dateTo) |
| GET    | `/api/v1/restaurant-assistant-queries/{id}` | Show                                                                             |
| GET    | `/api/v1/restaurant-recurring-orders`       | List (filter: status, restaurantId, dateFrom, dateTo)                            |
| GET    | `/api/v1/restaurant-recurring-orders/{id}`  | Show (user, restaurant, items)                                                  |
| GET    | `/api/v1/reviews`                           | List (filter: restaurantId, ratingMin, ratingMax, dateFrom, dateTo)              |
| GET    | `/api/v1/reviews/{id}`                      | Show                                                                             |

---

## 4. Enums reference (Restaurant)

Use these **string values** when sending filters or displaying status/type labels. All values are snake_case as returned and accepted by the API.

| Enum                            | Values                                                                                     |
| ------------------------------- | ------------------------------------------------------------------------------------------ |
| **OrderStatus**                 | `pending`, `accepted`, `preparing`, `ready_for_pickup`, `picked_up`, `completed`, `cancelled` |
| **OrderType**                   | `pickup`, `dine_in`                                                                         |
| **RestaurantPickupMode**        | `immediate_pickup`, `scheduled_pickup`                                                     |
| **RestaurantDisputeStatus**     | `open`, `under_review`, `resolved`, `closed`                                                |
| **RestaurantDocumentType**      | `identity`, `commercial_registration`, `health_certificate`, `other`                        |
| **PriceRange**                  | `low`, `medium`, `high`, `premium`                                                         |
| **PenaltyType**                 | `warning`, `fine`, `suspension`                                                             |
| **DiscountType**                | `percentage`, `fixed_amount`                                                               |
| **RecurringOrderStatus**       | `active`, `paused`, `cancelled`                                                             |
| **RestaurantAssistantInputMode** | `text`, `voice`                                                                          |

---

## 5. Example requests and responses

### Example 1: List restaurants (paginated)

**Request:**

```
GET https://dllni.mustafafares.com/api/v1/restaurants?perPage=20&sort=-createdAt&filter[isActive]=1
```

**Headers:** `Authorization: Bearer {token}`, `Accept: application/json`

### Example 2: Paginated response (restaurants list)

**Response (200):**

```json
{
  "data": [
    {
      "id": 1,
      "name": "Restaurant A",
      "slug": "restaurant-a",
      "isActive": true,
      "priceRange": "medium",
      "reputationScore": 85,
      "createdAt": "2025-02-01T10:00:00.000000Z",
      "updatedAt": "2025-02-01T10:00:00.000000Z"
    }
  ],
  "links": {
    "first": "https://dllni.mustafafares.com/api/v1/restaurants?page=1",
    "last": "https://dllni.mustafafares.com/api/v1/restaurants?page=3",
    "prev": null,
    "next": "https://dllni.mustafafares.com/api/v1/restaurants?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 3,
    "path": "https://dllni.mustafafares.com/api/v1/restaurants",
    "per_page": 20,
    "to": 20,
    "total": 52
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
