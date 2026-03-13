# API Contract - Restaurant Module

**Audience:** Frontend / mobile developers  
**Base URL:** `https://dllni.mustafafares.com`  
**API prefix:** `/api/v1`  
**Auth:** Laravel Sanctum (`Authorization: Bearer {token}`) for all endpoints below.

---

## Scope

This is the main contract index for Restaurant module APIs implemented under:
- `Modules/Resturants/routes/api.php`

Detailed contracts:
- Core dashboard/admin flows: [API_CONTRACT_RESTAURANTS_DASHBOARD.md](API_CONTRACT_RESTAURANTS_DASHBOARD.md)

---

## Endpoint Summary

All paths are relative to `/api/v1`.

| Section | Endpoints | Description |
| ------- | --------- | ----------- |
| **Dashboard** | `GET /restaurant/dashboard/overview` | KPIs (sales, orders, disputes, low stock) |
| **Analytics** | `GET /restaurant/analytics/daily-stats`, `GET /restaurant/analytics/monthly-stats` | Daily and monthly stats |
| **Restaurant Owner App (new)** | `GET /restaurant-owner/dashboard/performance`, `GET /restaurant-owner/orders/{order}`, `POST/PATCH/DELETE /restaurant-owner/orders/{order}/items`, `PATCH /restaurant-owner/products/{product}/availability`, `GET /restaurant-owner/offers`, `GET /restaurant-owner/offers/summary`, `GET /restaurant-owner/coupons`, `GET /restaurant-owner/coupons/summary`, `GET/POST/PATCH /restaurant-owner/employees`, `PATCH /restaurant-owner/employees/{restaurant_staff}/status`, `GET /restaurant-owner/notifications`, `PATCH /restaurant-owner/notifications/{notification}/read`, `PATCH /restaurant-owner/notifications/read-all` | Owner-scoped management endpoints (restaurant inferred from authenticated seller) |
| **Restaurants** | CRUD `/restaurants`, `GET/PUT /restaurants/{restaurant}/operating-hours` | Restaurant CRUD and operating hours |
| **Categories** | CRUD `/categories` | Product categories |
| **Products** | CRUD `/products` | Product CRUD |
| **Products - AI (Gemini)** | `POST /products/ai/extract-from-image`, `POST /products/ai/extract-from-menu`, `POST /products/ai/generate-image` | AI extraction/generation for product creation flows |
| **Orders** | CRUD `/orders`, `POST /orders/{order}/accept`, `POST /orders/{order}/reject`, `GET /orders/{order}/invoice` | Orders and accept/reject/invoice actions |
| **Offers** | CRUD `/offers` | Offers |
| **Promo codes** | CRUD `/promo-codes` | Promo codes |
| **Inventory** | CRUD `/inventory-items`, `GET /restaurant/inventory-summary`, `GET /restaurant/inventory-alerts` | Inventory items, summary, alerts |
| **Disputes** | CRUD `/restaurant-order-disputes` | Order disputes |
| **Documents** | CRUD `/restaurant-documents` | Restaurant documents |
| **Reputation & penalties** | `GET /restaurant-reputation-logs`, `GET /restaurant-penalties` | Read-only |
| **Staff & roles** | CRUD `/restaurant-staff`, `/restaurant-roles`, `PUT /restaurant-roles/{restaurant_role}/permissions` | Staff and role management |
| **Assistant / recurring / reviews** | `GET /restaurant-assistant-queries`, `GET /restaurant-recurring-orders`, `GET /reviews` | Read-only |
| **Product search** | `GET /restaurant/search/products` | Full-text product search |

---

## Owner Endpoint Rules

For `/restaurant-owner/*` endpoints:
- user must be authenticated and `module_type=restaurant_seller`
- restaurant scope is server-derived (`auth()->user()->restaurants()->firstOrFail()`)
- cross-restaurant access is forbidden

---

## Conventions

- JSON keys are camelCase.
- Pagination uses `perPage` and `page`.
- Filtering uses `filter[field]=value` where applicable.
- Sorting uses `sort=field` or `sort=-field`.
- Validation errors follow Laravel JSON error format.

---

## Restaurant Owner API Contract (Merged)

Base path: `/api/v1/restaurant-owner`

### Dashboard

#### `GET /dashboard/performance`
Query:
- `range`: `today|week|month|year|custom` (default `today`)
- `from`: date (required if `range=custom`)
- `to`: date (required if `range=custom`)

Response:
- `range`: `{ key, from, to }`
- `summary`: `{ totalOrders, newOrdersCount, confirmedOrdersCount, completedOrdersCount, totalRevenue, averageOrderValue, cancellationRatePercent }`
- `topProducts`: `[{ productId, name, quantity, revenue }]`
- `fulfillment`: `{ averagePrepTimeMinutes, averageReadyToPickupMinutes, delayedOrdersPercent, onTimePercent }`
- `offersImpact`: `{ discountedOrdersCount, conversionRatePercent, discountedRevenue, totalSavings }`

### Orders

#### `GET /orders/{order}`
Response:
- `data`: `OrderResource` plus:
  - `canEditItems`: bool (`true` only for `pending|accepted`)
  - `paymentBreakdown`: `{ subtotal, deliveryFee, serviceFee, discount, total }`

#### `POST /orders/{order}/items`
Body:
- `productId` (required)
- `quantity` (required, int >= 1)
- `substituteProductId` (nullable)
- `specialInstructions` (nullable)

#### `PATCH /orders/{order}/items/{item}`
Body (any):
- `quantity` (optional, int >= 1)
- `substituteProductId` (optional, nullable)
- `specialInstructions` (optional, nullable)

#### `DELETE /orders/{order}/items/{item}`
Behavior for all item mutations:
- Allowed only when order status is `pending|accepted`
- Recalculates `subtotal`, `discount_amount`, `total_amount`
- Returns updated owner order payload

### Products

#### `PATCH /products/{product}/availability`
Body:
- `mode`: `available|sold_out_today|manual_unavailable` (required)
- `note`: string (optional)

Behavior:
- `available`: sets `is_available=true` and clears temporary block
- `sold_out_today`: sets `is_available=false` and `unavailable_until=endOfDay`
- `manual_unavailable`: sets `is_available=false` without expiry

Product payload extensions:
- `isAvailableNow`
- `availabilityMode`
- `unavailableUntil`
- `availabilityNote`

### Offers and Coupons

#### `GET /offers`
#### `GET /coupons`
Query:
- `status`: `active|scheduled|expired|all`
- `search`
- `dateFrom`
- `dateTo`
- `sort` (supports `performance`)
- `perPage`

#### `GET /offers/summary`
#### `GET /coupons/summary`
Response:
- `summary`: `{ activeCount, expiredCount, totalUsageOrders, totalSavings, revenueImpact, topPerforming }`

### Employees

#### `GET /employees`
Response:
- list of `{ id, restaurantId, userId, isActive, user, permissionIds, effectivePermissions, createdAt, updatedAt }`

#### `POST /employees`
Body:
- `name` (required)
- `email` (nullable)
- `phone` (nullable)
- `permissionIds` (optional array of permission ids)
- `isActive` (optional, default true)

Behavior:
- Create-or-link user by `email`/`phone`
- If user is created: `module_type=restaurant_seller`
- Sync selected permissions directly to the linked user

#### `PATCH /employees/{restaurant_staff}`
Body:
- `name|email|phone|permissionIds|isActive` (all optional)

#### `PATCH /employees/{restaurant_staff}/status`
Body:
- `isActive` (required boolean)

### Notifications

#### `GET /notifications`
Query:
- `tab`: `all|orders|offers|system` (default `all`)
- `unreadOnly`: bool
- `page`, `perPage`

Response:
- `data`: `[{ id, source, category, title, body, meta, createdAt, isRead }]`
  - `source`: `user_notification|system_alert`
  - `id`: prefixed (`user:{uuid}` or `system:{id}`)
- `meta`: `{ page, perPage, total, lastPage, unreadTotal, tabCounts }`

#### `PATCH /notifications/{notification}/read`
Notification id formats:
- `user:{uuid}`
- `system:{id}`

#### `PATCH /notifications/read-all`
Body:
- `tab`: `all|orders|offers|system` (optional, default `all`)

System alert read mapping:
- `new -> acknowledged`
