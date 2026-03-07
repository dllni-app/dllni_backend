# Restaurant Owner API Contract (v1)

Base path: `/api/v1/restaurant-owner`  
Auth: `auth:sanctum` (user must have `module_type = restaurant_seller`)  
Scope: restaurant inferred from authenticated owner (`auth()->user()->restaurants()->firstOrFail()`).

## Dashboard

### `GET /dashboard/performance`
Query:
- `range`: `today|week|month|year|custom` (default `today`)
- `from`: date (required if `range=custom`)
- `to`: date (required if `range=custom`)

Response:
- `range`: `{ key, from, to }`
- `summary`: `{ totalOrders, totalRevenue, averageOrderValue, cancellationRatePercent }`
- `topProducts`: `[{ productId, name, quantity, revenue }]`
- `fulfillment`: `{ averagePrepTimeMinutes, averageReadyToPickupMinutes, delayedOrdersPercent, onTimePercent }`
- `offersImpact`: `{ discountedOrdersCount, conversionRatePercent, discountedRevenue, totalSavings }`

## Orders

### `GET /orders/{order}`
Response:
- `data`: `OrderResource` +:
  - `canEditItems`: bool (`true` only for `pending|accepted`)
  - `paymentBreakdown`: `{ subtotal, deliveryFee, serviceFee, discount, total }`

### `POST /orders/{order}/items`
Body:
- `productId` (required)
- `quantity` (required, int >= 1)
- `substituteProductId` (nullable)
- `specialInstructions` (nullable)

### `PATCH /orders/{order}/items/{item}`
Body (any):
- `quantity` (optional, int >= 1)
- `substituteProductId` (optional, nullable)
- `specialInstructions` (optional, nullable)

### `DELETE /orders/{order}/items/{item}`
Behavior for all item mutations:
- Allowed only when order status is `pending|accepted`
- Recalculates `subtotal`, `discount_amount`, `total_amount`
- Returns updated owner order payload

## Products

### `PATCH /products/{product}/availability`
Body:
- `mode`: `available|sold_out_today|manual_unavailable` (required)
- `note`: string (optional)

Behavior:
- `available`: `is_available=true`, clear temporary block
- `sold_out_today`: `is_available=false`, set `unavailable_until=endOfDay`
- `manual_unavailable`: `is_available=false`, no expiry

Product payload extensions:
- `isAvailableNow`
- `availabilityMode`
- `unavailableUntil`
- `availabilityNote`

## Offers and Coupons

### `GET /offers`
### `GET /coupons`
Query:
- `status`: `active|scheduled|expired|all`
- `search`
- `dateFrom`
- `dateTo`
- `sort` (supports `performance`)
- `perPage`

### `GET /offers/summary`
### `GET /coupons/summary`
Response:
- `summary`: `{ activeCount, expiredCount, totalUsageOrders, totalSavings, revenueImpact, topPerforming }`

## Employees

### `GET /employees`
Response:
- list of `{ id, restaurantId, userId, restaurantRoleId, isActive, user, role, effectivePermissions, createdAt, updatedAt }`

### `POST /employees`
Body:
- `name` (required)
- `email` (nullable)
- `phone` (nullable)
- `restaurantRoleId` (required)
- `isActive` (optional, default true)

Behavior:
- Create-or-link user by `email`/`phone`
- If user is created: `module_type=restaurant_seller`

### `PATCH /employees/{restaurant_staff}`
Body:
- `name|email|phone|restaurantRoleId|permissionIds|isActive` (all optional)

### `PATCH /employees/{restaurant_staff}/status`
Body:
- `isActive` (required boolean)

## Notifications

### `GET /notifications`
Query:
- `tab`: `all|orders|offers|system` (default `all`)
- `unreadOnly`: bool
- `page`, `perPage`

Response:
- `data`: `[{ id, source, category, title, body, meta, createdAt, isRead }]`
  - `source`: `user_notification|system_alert`
  - `id`: prefixed (`user:{uuid}` or `system:{id}`)
- `meta`: `{ page, perPage, total, lastPage, unreadTotal, tabCounts }`

### `PATCH /notifications/{notification}/read`
Notification id formats:
- `user:{uuid}`
- `system:{id}`

### `PATCH /notifications/read-all`
Body:
- `tab`: `all|orders|offers|system` (optional, default `all`)

System alert read mapping:
- `new -> acknowledged`
