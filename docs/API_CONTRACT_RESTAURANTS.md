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

### 3.0 Cancellation policy (shared)

| Method | Path                          | Description                                              |
| ------ | ----------------------------- | -------------------------------------------------------- |
| GET    | `/api/v1/cancellation-policy` | Get active cancellation policy for a specific module     |

**Query params:**

| Name     | Type   | Required | Description                                  |
| -------- | ------ | -------- | -------------------------------------------- |
| `module` | string | yes      | Module key, e.g. `restaurant`, `cleaning`   |

**Response (200):**

```json
{
  "data": {
    "id": 1,
    "module": "restaurant",
    "name": "سياسة إلغاء حجوزات المطاعم",
    "description": "توضّح هذه السياسة شروط ورسوم إلغاء طلبات المطاعم.",
    "rules": [
      { "minutesBefore": 60, "feePercent": 0 },
      { "minutesBefore": 30, "feePercent": 20 },
      { "minutesBefore": 10, "feePercent": 50 }
    ],
    "isActive": true,
    "isDefault": true
  }
}
```

**Error codes:**

- 422 – when `module` is missing.
- 404 – when there is no active policy for the specified module.

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

**Restaurant fields (store info):** `name`, `slug`, `description` (max 200), `address`, `city`, `district`, `locationDetails`, `latitude`, `longitude`, `phone`, `whatsappNumber`, `email`, `instagramUsername`, `facebookPageName`, `isTemporarilyClosed`.

### 3.2a Restaurant operating hours

| Method | Path                                       | Description                    |
| ------ | ------------------------------------------ | ------------------------------ |
| GET    | `/api/v1/restaurants/{id}/operating-hours` | Get operating hours config     |
| PUT    | `/api/v1/restaurants/{id}/operating-hours` | Update operating hours config  |

**PUT request body:**
| Field                 | Type    | Required | Description                                      |
| --------------------- | ------- | -------- | ------------------------------------------------ |
| `isTemporarilyClosed` | boolean | no       | Store-wide temporary closure                     |
| `dailyHours`          | array   | no       | Per-day config                                   |
| `dailyHours[].dayOfWeek` | string | yes   | `sunday`, `monday`, … `saturday`                  |
| `dailyHours[].isEnabled` | boolean | no   | Whether the day is open                           |
| `dailyHours[].timeSlots` | array  | no     | `[{ startTime, endTime }]` (e.g. "09:00 AM", "11:00 PM") |

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
| POST      | `/api/v1/orders/{id}/accept` | Accept order (status → accepted, accepted_at, estimated_preparation_minutes, assigned_staff_id, kitchen_notes) |
| POST      | `/api/v1/orders/{id}/reject` | Reject order (status → cancelled, cancellation_reason_code, cancellation_reason) |
| GET       | `/api/v1/orders/{id}/invoice` | Get invoice data (JSON) |

**Order accept request body:**
| Field                   | Type    | Required | Description                                      |
| ----------------------- | ------- | -------- | ------------------------------------------------ |
| `preparationTimeMinutes`| integer | yes      | 1–120, estimated preparation time in minutes    |
| `assignedEmployeeId`    | integer | no       | exists:users,id — staff to assign                 |
| `kitchenNotes`          | string  | no       | max 1000                                         |

**Order reject request body:**
| Field           | Type   | Required | Description                                      |
| --------------- | ------ | -------- | ------------------------------------------------ |
| `reason`        | string | yes      | `out_of_stock`, `kitchen_busy`, `closing_hours`, `other` |
| `customerMessage` | string | no     | max 150, message to customer                     |

**Index query params:** `filter[status]`, `filter[restaurantId]`, `filter[orderType]`, `filter[pickupMode]`, `filter[dateFrom]`, `filter[dateTo]`, `filter[createdToday]` (boolean), `filter[hasDispute]`, `filter[late]` (boolean — orders past estimated preparation time).

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

**Index query params:** `filter[restaurantId]`, `filter[isActive]`, `filter[startsAtFrom]`, `filter[endsAtTo]`. Use `filter[isActive]=true` for active offers, `filter[scheduled]=true` for scheduled offers.

### 3.7a Inventory items (full CRUD)

| Method    | Path                              | Description      |
| --------- | --------------------------------- | ---------------- |
| GET       | `/api/v1/inventory-items`         | List (paginated) |
| GET       | `/api/v1/inventory-items/{id}`    | Show             |
| POST      | `/api/v1/inventory-items`         | Create           |
| PUT/PATCH | `/api/v1/inventory-items/{id}`    | Update           |
| DELETE    | `/api/v1/inventory-items/{id}`    | Delete           |

**Index query params:** `filter[restaurantId]`, `filter[search]`, `filter[lowStock]`.

**Request body (store/update):** `restaurantId`, `name`, `unit`, `quantity`, `minimumLimit`, `unitCost`, `productIds` (optional array for product linking).

### 3.7b Inventory summary and alerts

| Method | Path                                      | Description                    |
| ------ | ----------------------------------------- | ------------------------------ |
| GET    | `/api/v1/restaurant/inventory-summary`    | Summary (totalItems, lowStockCount, expiringItemsCount, totalValue) |
| GET    | `/api/v1/restaurant/inventory-alerts`     | List low-stock items           |

**Query params:** `restaurantId` (required).

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

### 3.11 Restaurant staff (full CRUD)

| Method    | Path                              | Description      |
| --------- | --------------------------------- | ---------------- |
| GET       | `/api/v1/restaurant-staff`        | List (paginated) |
| GET       | `/api/v1/restaurant-staff/{id}`   | Show             |
| POST      | `/api/v1/restaurant-staff`        | Create           |
| PUT/PATCH | `/api/v1/restaurant-staff/{id}`   | Update           |
| DELETE    | `/api/v1/restaurant-staff/{id}`   | Delete           |

**Request body:** `restaurantId`, `userId`, `restaurantRoleId`.  
**Index query params:** `filter[restaurantId]`.

### 3.11a Restaurant roles (full CRUD)

| Method    | Path                                        | Description      |
| --------- | ------------------------------------------- | ---------------- |
| GET       | `/api/v1/restaurant-roles`                   | List (paginated) |
| GET       | `/api/v1/restaurant-roles/{id}`              | Show (with permissions) |
| POST      | `/api/v1/restaurant-roles`                   | Create           |
| PUT/PATCH | `/api/v1/restaurant-roles/{id}`              | Update           |
| DELETE    | `/api/v1/restaurant-roles/{id}`              | Delete           |
| PUT       | `/api/v1/restaurant-roles/{id}/permissions`  | Update permissions for role |

**Request body (store/update):** `restaurantId`, `name`, `slug`.  
**Permissions request body:** `permissionIds` (array of permission IDs).  
**Index query params:** `filter[restaurantId]`.

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

### 3.14 Product search (non-AI)

| Method | Path                                 | Description                                                                   |
| ------ | ------------------------------------ | ----------------------------------------------------------------------------- |
| GET    | `/api/v1/restaurant/search/products` | Full-text product search across restaurants with rich filtering and pagination |

**Auth:** `Authorization: Bearer {token}` required.

**Query params:**

| Parameter               | Required | Type              | Constraints                                     | Description                                          |
| ----------------------- | -------- | ----------------- | ----------------------------------------------- | ---------------------------------------------------- |
| `filter[search]`        | **yes**  | string            | min:1, max:255, must contain non-whitespace      | Partial match on `name` and `slug`                  |
| `filter[restaurantId]`  | no       | integer           | exists:restaurants,id                           | Narrow to a specific restaurant                      |
| `filter[categoryId]`    | no       | integer           | exists:categories,id                            | Narrow to a specific category                        |
| `filter[isAvailable]`   | no       | boolean           | —                                               | Filter by availability flag                          |
| `filter[isFeatured]`    | no       | boolean           | —                                               | Filter featured products only                        |
| `filter[lowStock]`      | no       | boolean           | —                                               | Filter products below their low-stock threshold      |
| `filter[masterProductId]` | no     | integer           | exists:master_products,id                       | Filter by master product reference                   |
| `filter[minPrice]`      | no       | numeric           | min:0                                           | Products with `price >= minPrice`                    |
| `filter[maxPrice]`      | no       | numeric           | min:0, gte:filter.minPrice                      | Products with `price <= maxPrice`                    |
| `filter[hasDiscount]`   | no       | boolean           | —                                               | Products that have a `discountedPrice < price`       |
| `filter[createdAfter]`  | no       | date (ISO 8601)   | —                                               | Products created on or after this date (start of day)|
| `filter[createdBefore]` | no       | date (ISO 8601)   | —                                               | Products created on or before this date (end of day) |
| `sort`                  | no       | string            | `name`,`-name`,`price`,`-price`,`createdAt`,`-createdAt` | Default: `-createdAt`                      |
| `perPage`               | no       | integer           | min:1, max:50, default: 20                      | Items per page                                       |
| `page`                  | no       | integer           | min:1, default: 1                               | Page number                                          |

**Behavior:**
- `filter[search]` is the only required parameter. The endpoint returns `422` if it is missing or blank.
- Search matches `products.name` and `products.slug` using a case-insensitive `LIKE` with `!` as the escape character.
- All filters are ANDed together.
- Each product in the response includes its related `restaurant` and `category` objects.

**Validation errors (422):**

| Field             | Triggered when                                                          |
| ----------------- | ----------------------------------------------------------------------- |
| `filter.search`   | missing, empty/whitespace-only, or exceeds 255 characters              |
| `filter.maxPrice` | present but less than `filter.minPrice`                                 |
| `sort`            | value is not one of the allowed sort options                            |
| `perPage`         | not an integer, or outside 1–50                                         |

**Response (200):** Paginated collection — `data`, `links`, `meta` (see §2.1 for structure).

Each `data[]` item:

```json
{
  "id": 42,
  "name": "Margherita Pizza",
  "slug": "margherita-pizza",
  "description": "Classic tomato and mozzarella.",
  "price": 12.50,
  "discountedPrice": 10.00,
  "isAvailable": true,
  "isFeatured": false,
  "restaurant": {
    "id": 3,
    "name": "Bella Italia",
    "slug": "bella-italia"
  },
  "category": {
    "id": 7,
    "name": "Pizzas",
    "slug": "pizzas"
  },
  "createdAt": "2025-06-01 10:00:00",
  "updatedAt": "2025-06-01 10:00:00"
}
```

> `discountedPrice` is `null` when no active discount exists.  
> `restaurant` and `category` are always present (eagerly loaded).

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
| **OrderRejectionReason**        | `out_of_stock`, `kitchen_busy`, `closing_hours`, `other`                                 |

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

### Example 3: Product search

**Request:**

```
GET https://dllni.mustafafares.com/api/v1/restaurant/search/products
    ?filter[search]=pizza
    &filter[restaurantId]=3
    &filter[minPrice]=5
    &filter[maxPrice]=20
    &filter[hasDiscount]=true
    &sort=-price
    &perPage=10
    &page=1
```

**Headers:** `Authorization: Bearer {token}`, `Accept: application/json`

**Response (200):**

```json
{
  "data": [
    {
      "id": 42,
      "name": "Margherita Pizza",
      "slug": "margherita-pizza",
      "description": "Classic tomato and mozzarella.",
      "price": 12.50,
      "discountedPrice": 10.00,
      "isAvailable": true,
      "isFeatured": false,
      "restaurant": {
        "id": 3,
        "name": "Bella Italia",
        "slug": "bella-italia"
      },
      "category": {
        "id": 7,
        "name": "Pizzas",
        "slug": "pizzas"
      },
      "createdAt": "2025-06-01 10:00:00",
      "updatedAt": "2025-06-01 10:00:00"
    }
  ],
  "links": {
    "first": "https://dllni.mustafafares.com/api/v1/restaurant/search/products?page=1",
    "last": "https://dllni.mustafafares.com/api/v1/restaurant/search/products?page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "path": "https://dllni.mustafafares.com/api/v1/restaurant/search/products",
    "per_page": 10,
    "to": 1,
    "total": 1
  }
}
```

**Missing `filter[search]` → 422:**

```json
{
  "message": "The filter.search field is required.",
  "errors": {
    "filter.search": ["The filter.search field is required."]
  }
}
```

---

### Example 4: Validation error (422)

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
