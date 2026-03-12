# API Contract for Flutter – Restaurant Owner App

**Audience:** Flutter developer  
**Domain:** `dllni.mustafafares.com`  
**Scope:** Restaurant owner mobile app only (owner/seller view for a single restaurant inferred from authenticated user).  
For general restaurant module overview, see [API_CONTRACT_RESTAURANTS.md](API_CONTRACT_RESTAURANTS.md).

---

## 1. Base URL, authentication, and client behavior

- **Base URL:** `https://dllni.mustafafares.com`
- **API prefix:** All endpoints below are relative to the base URL:  
  `https://dllni.mustafafares.com/api/v1/...`
- **Auth:** Laravel Sanctum. Send token on every request:
  - Header: `Authorization: Bearer {token}`
  - Login / token issuance is out of scope of this contract (use existing auth endpoints).
- **Module requirement:** User must be a restaurant seller (`module_type=restaurant_seller`).  
  The backend infers the **current restaurant** from the authenticated user.
- **Content-Type:** `application/json` for request bodies; responses are JSON.

### 1.1 Client behavior (UI/API usage)

Same high-level rules as in [API_CONTRACT_CLEANING_WORKER.md](API_CONTRACT_CLEANING_WORKER.md):

- **GET (with ids/enums):**
  - If the API returns ids/enums (e.g. role id, status), the app shows human labels only and sends ids/enums back.
- **POST (create):**
  - Backend-known data (ids, enums, related objects) must come from prior GETs and be stored in screen state, not entered manually.
  - One dedicated input per user-supplied field.
- **PUT/PATCH (update):**
  - Prefer **optimistic UI**: update local state first, then call API, and revert on failure (where UX makes sense).
- **Error handling:**
  - Do not silently swallow errors; show a visible message/toast/snackbar on 4xx / 5xx responses.

---

## 2. Global conventions

### 2.1 Pagination

- **Query parameters:**  
  - `perPage` (integer, 1–100, default 20)  
  - `page` (integer, default 1)
- **Response (paginated list):** Laravel API Resource collection:
  - `data`: array of resource objects
  - `links`: `first`, `last`, `prev`, `next`
  - `meta`: `current_page`, `from`, `last_page`, `path`, `per_page`, `to`, `total`

### 2.2 Filtering and sorting

- **Filters:** Pass as query params: `filter[fieldName]=value`. Multiple filters are ANDed.
- **Sort:** `sort=field` (asc) or `sort=-field` (desc). Defaults are endpoint-specific (usually `-createdAt` or `-id`).

### 2.3 Request / response casing

- **JSON:** camelCase for all keys (e.g. `createdAt`, `perPage`, `restaurantRoleId`).

### 2.4 Single-resource responses

- **Success:** HTTP 200. Body: `{ "data": { ...resource object } }`.
- **Create:** HTTP 201. Body: `{ "data": { ...resource object } }`.
- **Delete:** HTTP 204 No Content, empty body.
- **Errors:** 4xx/5xx with JSON body; standard Laravel validation errors under `errors` (keyed by field).

---

## 3. Owner scoping and restaurant inference

All `/restaurant-owner/*` endpoints:

- Require an authenticated user with `module_type=restaurant_seller`.
- The **restaurant scope is server-derived**, typically:  
  `auth()->user()->restaurants()->firstOrFail()`.
- Cross-restaurant access is forbidden (no restaurant id in URLs; no way to switch restaurants from the client).

**Base path for this contract:** `/api/v1/restaurant-owner`

---

## 3. Screen → endpoint mapping (Figma widgets)

This section maps the main Figma widgets you shared to concrete API endpoints. Details for each endpoint are in later sections or in `API_CONTRACT_RESTAURANTS.md`.

### 3.1 Home screen – KPIs, quick actions, new orders, activity chart

- **Header (restaurant name + branch):**
  - `GET /api/v1/restaurant-owner/restaurant` – restaurant info (name, branch, logo) for the current authenticated owner.  
    - Backend infers the restaurant from the authenticated user; do **not** send any `restaurantId` from the client.
- **Today’s balance / “نظرة عامة اليوم” card (total sales, orders today, etc.):**
  - `GET /api/v1/restaurant-owner/dashboard/performance` – see §4.1.  
    - Use `summary.totalOrders`, `summary.totalRevenue`, `summary.averageOrderValue`, `summary.cancellationRatePercent`.
- **Today orders small stats row (e.g. completed / cancelled / disputes):**
  - Same `GET /restaurant-owner/dashboard/performance` (map counts to small cards).
- **Quick actions row (e.g. “إحصائيات الأداء”, “المنتجات”, “المخزون”):**
  - No dedicated API; these are pure navigation shortcuts to:
    - Statistics screen → `GET /restaurant-owner/dashboard/performance`
    - Products list → `GET /api/v1/products` (owner-scoped; see §6.2)
    - Inventory → `GET /api/v1/inventory-items` and `GET /api/v1/restaurant/inventory-summary` (see Inventory section below).
- **“طلبات جديدة” (new orders list) with Accept / Reject buttons:**
  - `GET /api/v1/orders?filter[status]=pending` – list pending orders for the current restaurant (scoped from auth; no `restaurantId` filter is needed).
  - `POST /api/v1/orders/{id}/accept` – accept order (see `API_CONTRACT_RESTAURANTS.md` §3.5).
  - `POST /api/v1/orders/{id}/reject` – reject order with reason.
- **“قيد التحضير” / “نشاط الطلبات (ساعات)” chart:**
  - `GET /api/v1/orders?filter[createdToday]=1` – totals for today (per status) for the current restaurant (scoped from auth).
  - `GET /api/v1/restaurant/dashboard/overview` – `orderActivityByHour` used for the bar chart for the current restaurant (scoped from auth; no `restaurantId` query param).

### 3.2 Statistics KPI screen (تقارير الأداء)

Tabs for time ranges (اليوم، هذا الأسبوع، هذا الشهر، هذه السنة، فترة مخصصة) and cards:

- **Top summary cards (total orders, total sales, average order value, cancellation rate):**
  - `GET /api/v1/restaurant-owner/dashboard/performance?range={today|week|month|year|custom&from=&to=}` – see §4.1.
- **“أكثر المنتجات مبيعاً” list:**
  - Same `GET /restaurant-owner/dashboard/performance` → `topProducts[]`.
- **“تحليل العروض” / promo performance cards:**
  - `GET /api/v1/restaurant-owner/offers/summary` – see §7.2.
  - `GET /api/v1/restaurant-owner/coupons/summary` – see §7.4.
- **Delivery-specific statistics widget:** intentionally **not** covered here (as requested: *skip the delivery statistics*).

### 3.3 Orders screens (list, accept, deny, details)

- **Orders list with status tabs (e.g. الكل، قيد التحضير، جاهز، مكتمل، ملغي):**
  - `GET /api/v1/orders?filter[status]={status}&perPage=&page=` – scoped automatically to the current restaurant from auth.  
    - Map tabs to `filter[status]`: `pending`, `accepted`, `preparing`, `ready_for_pickup`, `on_the_way`, `completed`, `cancelled` (see `OrderStatus` enum).
- **Order details screen (تفاصيل الطلب):**
  - `GET /api/v1/restaurant-owner/orders/{id}` – owner-focused order view with `paymentBreakdown` and `canEditItems` (see §5.1).
- **Accept order sheet (prep time, assigned staff, notes):**
  - `POST /api/v1/orders/{id}/accept` – body includes `preparationTimeMinutes`, `assignedEmployeeId`, `kitchenNotes` (admin contract §3.5).
- **Reject order sheet (reason list, message to customer):**
  - `POST /api/v1/orders/{id}/reject` – body includes `reason`, `customerMessage`.
- **Inline edit of line items / totals in detail screen:**
  - `POST /api/v1/restaurant-owner/orders/{id}/items` – add item (§5.2).
  - `PATCH /api/v1/restaurant-owner/orders/{id}/items/{itemId}` – edit item (§5.3).
  - `DELETE /api/v1/restaurant-owner/orders/{id}/items/{itemId}` – remove item (§5.4).

### 3.4 Products screens (list, add via AI, after import)

- **Products list with search, category filters, availability toggle:**
  - `GET /api/v1/products?filter[search]=...&filter[isAvailable]=...` – base listing for the current restaurant (scoped from auth; no `restaurantId` filter).
  - `GET /api/v1/restaurant/search/products?filter[search]=...` – full-text search for the search bar (current restaurant inferred from auth; no `restaurantId` filter) (admin contract §3.14).
  - `PATCH /api/v1/restaurant-owner/products/{id}/availability` – toggle switch on each row (§6.1).
- **“إضافة منتج جديد” – AI options (image, menu, CSV, manual):**
  - Single-product from image: `POST /api/v1/products/ai/extract-from-image` – suggests `title` + `description` (admin contract §3.4a).
  - Multiple items from menu image: `POST /api/v1/products/ai/extract-from-menu` – suggests `items[]` with titles/descriptions.
  - Generate image from text: `POST /api/v1/products/ai/generate-image` – returns `imageBase64` for preview / upload.
  - After user confirms fields, actual creation is via `POST /api/v1/products`.
- **“Products – After Add Menu” screen:**
  - Same `GET /api/v1/products` listing (optionally with `filter[search]`) showing newly created products for the current restaurant (scoped from auth).

### 3.5 Inventory screens (list, add, link to products)

- **Inventory home (المخزون) – cards and list:**
  - Summary cards (total items, low stock count, total value):  
    - `GET /api/v1/restaurant/inventory-summary` (current restaurant inferred from auth; no `restaurantId` query param) (admin contract §3.7b).
  - Low-stock alert badge / list section:  
    - `GET /api/v1/restaurant/inventory-alerts` (scoped to current restaurant; no `restaurantId` query param).
  - Main inventory list + search:  
    - `GET /api/v1/inventory-items?filter[search]=...&filter[status]=...` – main inventory list for the current restaurant (scoped from auth) (admin contract §3.7a).
      - `filter[status]` (optional): one of `normal`, `low` to filter by stock status. When omitted, all items are returned.
- **Inventory – Add new item (basic info + link to products):**
  - `POST /api/v1/inventory-items` – body includes `name`, `unit`, `quantity`, `minimumLimit`, `unitCost`, `productIds[]` (restaurant is inferred from the authenticated user; do not send `restaurantId`).
  - `PUT/PATCH /api/v1/inventory-items/{id}` – update item and its linked `productIds[]` (restaurant scope derived from auth).
  - Product picker inside this form uses `GET /api/v1/products` (optionally with `filter[search]`); products are automatically scoped to the current restaurant.

### 3.6 “More” screens (store info, hours, offers, coupons, staff, support)

- **Store information (معلومات المتجر):**
  - `GET /api/v1/restaurant-owner/restaurant` – load all store fields (logo URLs, cover, description, address, contact numbers, social links) for the current restaurant inferred from auth (no `id` path param).
  - `PUT /api/v1/restaurant-owner/restaurant` – update basic info, address/location, and contact data for the current restaurant (admin contract §3.2).
- **Working hours (ساعات العمل):**
  - `GET /api/v1/restaurant-owner/restaurant/operating-hours` – load daily schedule for the current restaurant (admin contract §3.2a).
  - `PUT /api/v1/restaurant-owner/restaurant/operating-hours` – save weekly schedule for the current restaurant.
- **Offers management (إدارة العروض):**
  - Listing cards, filters, and stats bar:  
    - `GET /api/v1/restaurant-owner/offers` – paginated list (§7.1).  
    - `GET /api/v1/restaurant-owner/offers/summary` – top KPIs (§7.2).
  - Create / edit offer form (إضافة عرض جديد):  
    - `POST /api/v1/offers` and `PUT/PATCH /api/v1/offers/{id}` (admin contract §3.6).
- **Coupons management (الكوبونات):**
  - Listing / stats bar:  
    - `GET /api/v1/restaurant-owner/coupons` and `/coupons/summary` (§7.3–§7.4).
  - Create / edit coupon form:  
    - `POST /api/v1/promo-codes` and `PUT/PATCH /api/v1/promo-codes/{id}` (admin contract §3.7).
- **Employees & employee activity log:**
  - Employees list tile: `GET /api/v1/restaurant-owner/employees` (§8.1).
  - Create / edit employee: `POST /restaurant-owner/employees`, `PATCH /restaurant-owner/employees/{id}`, `PATCH /restaurant-owner/employees/{id}/status` (§8.2–§8.4).
  - Roles & permissions for staff UI: `GET /api/v1/restaurant-owner/permissions` – returns restaurant roles with their attached permissions (see §8.5).
  - **Employee activity log screen:** **no dedicated endpoint is specified yet** in the current backend contracts. A future endpoint such as `GET /restaurant-staff-activity` (filter by staff & date) would be needed.
- **Support / contact (الدعم الفني):**
  - Currently **no backend endpoints are defined** for support chat/tickets in `API_CONTRACT_RESTAURANTS*.md`. Flutter app may initially deep-link to an external support channel (e.g. phone/WhatsApp) until an API is added.

---

## 4. Dashboard – performance overview

### 4.1 Get dashboard performance

This powers the main owner dashboard KPIs and small charts (orders, revenue, top products, fulfillment, offers impact).

| Method | Path                                            | Description                     |
| ------ | ----------------------------------------------- | ------------------------------- |
| GET    | `/api/v1/restaurant-owner/dashboard/performance` | Performance overview by period  |

**Query params:**

| Param  | Type   | Required | Description |
| ------ | ------ | -------- | ----------- |
| range  | string | no       | One of `today`, `week`, `month`, `year`, `custom`. Default `today`. |
| from   | string | required if `range=custom` | Start date (YYYY-MM-DD). |
| to     | string | required if `range=custom` | End date (YYYY-MM-DD). |

**Response (200):**

```json
{
  "range": {
    "key": "today",
    "from": "2026-03-10",
    "to": "2026-03-10"
  },
  "summary": {
    "totalOrders": 120,
    "totalRevenue": 15000.5,
    "averageOrderValue": 125.0,
    "cancellationRatePercent": 4.5
  },
  "topProducts": [
    { "productId": 10, "name": "Burger Combo", "quantity": 80, "revenue": 4000.0 }
  ],
  "fulfillment": {
    "averagePrepTimeMinutes": 18,
    "averageReadyToPickupMinutes": 25,
    "delayedOrdersPercent": 10.0,
    "onTimePercent": 90.0
  },
  "offersImpact": {
    "discountedOrdersCount": 30,
    "conversionRatePercent": 15.0,
    "discountedRevenue": 3000.0,
    "totalSavings": 500.0
  }
}
```

| Field                               | Type    | Description |
| ----------------------------------- | ------- | ----------- |
| range.key                           | string  | One of `today`, `week`, `month`, `year`, `custom`. |
| range.from, range.to                | string  | Actual date range (YYYY-MM-DD). |
| summary.totalOrders                 | number  | Count of orders in the range. |
| summary.totalRevenue                | number  | Sum of `total` for orders in the range. |
| summary.averageOrderValue           | number  | `totalRevenue / totalOrders` (0 when no orders). |
| summary.cancellationRatePercent     | number  | Percentage of cancelled orders in the range. |
| topProducts                         | array   | Best-selling products for the period. |
| topProducts[].productId             | number  | Product id. |
| topProducts[].name                  | string  | Product name. |
| topProducts[].quantity              | number  | Total quantity sold. |
| topProducts[].revenue               | number  | Revenue from this product. |
| fulfillment.averagePrepTimeMinutes  | number  | Avg. minutes from order accepted to `preparing`/`ready`. |
| fulfillment.averageReadyToPickupMinutes | number | Avg. minutes until order is ready. |
| fulfillment.delayedOrdersPercent    | number  | Percent of orders exceeding SLA/prep time. |
| fulfillment.onTimePercent           | number  | Percent of orders within SLA. |
| offersImpact.discountedOrdersCount  | number  | Orders with an offer/coupon applied. |
| offersImpact.conversionRatePercent  | number  | Conversion rate for promo traffic. |
| offersImpact.discountedRevenue      | number  | Revenue from orders with offers/coupons. |
| offersImpact.totalSavings           | number  | Total discount value given to customers. |

---

## 5. Orders (owner-side edit flows)

Owner can view and (while early in the lifecycle) edit the order items.

### 5.1 Show one order (owner view)

| Method | Path                                   | Description                         |
| ------ | -------------------------------------- | ----------------------------------- |
| GET    | `/api/v1/restaurant-owner/orders/{id}` | Show order details for owner view   |

**Path params:**

- `id` – integer, order id. Must belong to current restaurant.

**Response (200):**

```json
{
  "data": {
    "id": 123,
    "orderNumber": "ORD-123",
    "status": "accepted",
    "placedAt": "2026-03-10T10:00:00.000000Z",
    "channel": "delivery",
    "customer": {
      "id": 45,
      "name": "Ahmed",
      "phone": "+963991234567"
    },
    "items": [
      {
        "id": 1,
        "productId": 10,
        "name": "Burger Combo",
        "quantity": 2,
        "unitPrice": 50.0,
        "totalPrice": 100.0,
        "substituteProductId": null,
        "specialInstructions": "No onions"
      }
    ],
    "paymentBreakdown": {
      "subtotal": 100.0,
      "deliveryFee": 20.0,
      "serviceFee": 5.0,
      "discount": 10.0,
      "total": 115.0
    },
    "canEditItems": true,
    "createdAt": "2026-03-10T10:00:00.000000Z",
    "updatedAt": "2026-03-10T10:05:00.000000Z"
  }
}
```

| Field                 | Type    | Description |
| --------------------- | ------- | ----------- |
| status                | string  | See §10.1 `OrderStatus`. |
| channel               | string  | E.g. `delivery`, `pickup`, etc. |
| items                 | array   | Line items with current prices and substitutions. |
| paymentBreakdown      | object  | Monetary summary used for the totals card. |
| canEditItems          | boolean | `true` when status allows item edits (see below). |

`canEditItems` is **true** only when status is `pending` or `accepted`. Use this to enable/disable all edit UI (add/edit/remove).

---

### 5.2 Add item to order

| Method | Path                                               | Description                 |
| ------ | -------------------------------------------------- | --------------------------- |
| POST   | `/api/v1/restaurant-owner/orders/{id}/items`       | Add a new item to an order |

**Allowed statuses:**  
Order status must be `pending` or `accepted`.  
Other statuses return `422` with validation error.

**Path params:**

- `id` – order id (integer).

**Request body:**

```json
{
  "productId": 10,
  "quantity": 2,
  "substituteProductId": null,
  "specialInstructions": "Extra cheese"
}
```

| Field                | Type            | Required | Description |
| -------------------- | --------------- | -------- | ----------- |
| productId            | integer         | yes      | Product id belonging to this restaurant. |
| quantity             | integer         | yes      | `>= 1`. |
| substituteProductId  | integer or null | no       | Optional replacement product if original is unavailable. |
| specialInstructions  | string or null  | no       | Free-text, typically max 500 chars. |

**Response (200):** Updated order resource (same as `GET /orders/{id}`).

**Errors:**

- `403` – Order is not in current restaurant.
- `404` – Order not found.
- `422` – Invalid status or invalid fields.

---

### 5.3 Update existing item

| Method | Path                                                      | Description                    |
| ------ | --------------------------------------------------------- | ------------------------------ |
| PATCH  | `/api/v1/restaurant-owner/orders/{id}/items/{itemId}`     | Update quantity/substitution   |

**Allowed statuses:** `pending`, `accepted` only.

**Path params:**

- `id` – order id.
- `itemId` – line item id.

**Request body (partial):**

```json
{
  "quantity": 3,
  "substituteProductId": 11,
  "specialInstructions": "No mayo"
}
```

All fields are optional; only send what changed.

**Response (200):** Updated order resource.

**Errors:**

- `403` – Not in current restaurant.
- `404` – Order or item not found.
- `422` – Invalid status / validation error.

---

### 5.4 Remove item

| Method | Path                                                      | Description             |
| ------ | --------------------------------------------------------- | ----------------------- |
| DELETE | `/api/v1/restaurant-owner/orders/{id}/items/{itemId}`     | Remove an item from order |

**Allowed statuses:** `pending`, `accepted` only.

**Path params:**

- `id` – order id.
- `itemId` – line item id.

**Response (200):** Updated order resource (or `204 No Content` if backend chooses; assume 200 with updated order for Flutter).

**Recalculation behavior (server):**

- On every `POST`/`PATCH`/`DELETE`:
  - Recalculates `subtotal`, `discount`, `total`, etc.
  - Applies business rules (e.g. minimum order amount).

---

## 6. Products – availability controls

### 6.1 Update product availability

| Method | Path                                                     | Description                     |
| ------ | -------------------------------------------------------- | ------------------------------- |
| PATCH  | `/api/v1/restaurant-owner/products/{id}/availability`    | Toggle product availability     |

**Path params:**

- `id` – product id belonging to current restaurant.

**Request body:**

```json
{
  "mode": "sold_out_today",
  "note": "Sold out after 8pm"
}
```

| Field | Type   | Required | Description |
| ----- | ------ | -------- | ----------- |
| mode  | string | yes      | One of `available`, `sold_out_today`, `manual_unavailable`. |
| note  | string | no       | Optional note shown in owner UI (and possibly staff UI). |

**Behavior:**

- `mode = "available"`:
  - `is_available = true`
  - Clears any `unavailableUntil` / temporary blocks.
- `mode = "sold_out_today"`:
  - `is_available = false`
  - Sets `unavailableUntil` to end-of-day (server time).
- `mode = "manual_unavailable"`:
  - `is_available = false`
  - `unavailableUntil = null` (indefinite until toggled back).

**Product payload extensions (typical):**

```json
{
  "id": 10,
  "name": "Burger Combo",
  "price": 50.0,
  "isAvailableNow": true,
  "availabilityMode": "available",
  "unavailableUntil": null,
  "availabilityNote": "Sold out after 8pm"
}
```

Use `isAvailableNow` and `availabilityMode` to control chips/toggles in the owner app.

**Errors:**

- `403` – Product not in current restaurant.
- `404` – Product not found.
- `422` – Invalid `mode` or validation error.

---

## 7. Offers and coupons (owner view)

### 7.1 List offers

| Method | Path                                 | Description                      |
| ------ | ------------------------------------ | -------------------------------- |
| GET    | `/api/v1/restaurant-owner/offers`    | List restaurant offers (paginated) |

**Query params:**

| Param    | Type   | Description |
| -------- | ------ | ----------- |
| status   | string | `active`, `scheduled`, `expired`, `all`. Default `active`. |
| search   | string | Search by offer name or code. |
| dateFrom | string | Filter by `startsAt >= dateFrom` (YYYY-MM-DD). |
| dateTo   | string | Filter by `endsAt <= dateTo` (YYYY-MM-DD). |
| sort     | string | E.g. `-createdAt`, `performance`. |
| perPage  | int    | 1–100, default 20. |
| page     | int    | Page number. |

**Response (200):** Standard paginated list. One element in `data[]`:

```json
{
  "id": 1,
  "name": "Lunch Discount",
  "status": "active",
  "type": "percentage",
  "value": 15,
  "startsAt": "2026-03-01T00:00:00.000000Z",
  "endsAt": "2026-03-31T23:59:59.000000Z",
  "usageCount": 120,
  "totalSavings": 800.0,
  "generatedRevenue": 5000.0
}
```

---

### 7.2 Offers summary

| Method | Path                                        | Description              |
| ------ | ------------------------------------------- | ------------------------ |
| GET    | `/api/v1/restaurant-owner/offers/summary`   | High-level offers stats  |

**Response (200):**

```json
{
  "summary": {
    "activeCount": 3,
    "expiredCount": 5,
    "totalUsageOrders": 300,
    "totalSavings": 2000.0,
    "revenueImpact": 15000.0,
    "topPerforming": {
      "id": 1,
      "name": "Lunch Discount",
      "usageCount": 120,
      "generatedRevenue": 5000.0
    }
  }
}
```

---

### 7.3 List coupons

| Method | Path                                  | Description                       |
| ------ | ------------------------------------- | --------------------------------- |
| GET    | `/api/v1/restaurant-owner/coupons`    | List restaurant coupons (paginated) |

**Query params:** Same as offers: `status`, `search`, `dateFrom`, `dateTo`, `sort`, `perPage`, `page`.

**Response (200):** One element in `data[]`:

```json
{
  "id": 10,
  "code": "WELCOME10",
  "status": "active",
  "discountType": "percentage",
  "discountValue": 10,
  "maxUses": 100,
  "usedCount": 40,
  "startsAt": "2026-03-01T00:00:00.000000Z",
  "endsAt": null
}
```

---

### 7.4 Coupons summary

| Method | Path                                         | Description               |
| ------ | -------------------------------------------- | ------------------------- |
| GET    | `/api/v1/restaurant-owner/coupons/summary`   | High-level coupons stats  |

**Response (200):**

```json
{
  "summary": {
    "activeCount": 4,
    "expiredCount": 2,
    "totalUsageOrders": 200,
    "totalSavings": 1500.0,
    "revenueImpact": 7000.0,
    "topPerforming": {
      "id": 10,
      "code": "WELCOME10",
      "usedCount": 80,
      "generatedRevenue": 3000.0
    }
  }
}
```

---

## 8. Employees (restaurant staff)

These endpoints manage staff linked to the current restaurant.

### 8.1 List employees

| Method | Path                                   | Description                    |
| ------ | -------------------------------------- | ------------------------------ |
| GET    | `/api/v1/restaurant-owner/employees`   | List restaurant employees      |

**Response (200):**

```json
{
  "data": [
    {
      "id": 5,
      "restaurantId": 1,
      "userId": 20,
      "restaurantRoleId": 2,
      "isActive": true,
      "user": {
        "id": 20,
        "name": "Omar",
        "email": "omar@example.com",
        "phone": "+963998765432"
      },
      "role": {
        "id": 2,
        "name": "Manager"
      },
      "effectivePermissions": [
        "orders.view",
        "orders.update",
        "products.manage",
        "offers.manage"
      ],
      "createdAt": "2026-03-01T00:00:00.000000Z",
      "updatedAt": "2026-03-10T00:00:00.000000Z"
    }
  ],
  "links": { },
  "meta": { }
}
```

---

### 8.2 Create employee

| Method | Path                                   | Description                  |
| ------ | -------------------------------------- | ---------------------------- |
| POST   | `/api/v1/restaurant-owner/employees`   | Create a new restaurant staff |

**Request body:**

```json
{
  "name": "Omar",
  "email": "omar@example.com",
  "phone": "+963998765432",
  "restaurantRoleId": 2,
  "isActive": true
}
```

| Field             | Type    | Required | Description |
| ----------------- | ------- | -------- | ----------- |
| name              | string  | yes      | Staff display name. |
| email             | string  | no       | Email (for login / notifications). |
| phone             | string  | no       | Phone number. |
| restaurantRoleId  | integer | yes      | Restaurant role id. |
| isActive          | boolean | no       | Defaults to `true`. |

**Behavior:**

- If user with given email/phone exists, link them as staff.
- Otherwise, create a new user with `module_type=restaurant_seller` and link.
- Staff is always attached to the current restaurant.

**Response (201):** `{ "data": { ...employee resource... } }`

**Errors:**

- `422` – Validation (e.g. invalid email, missing role).

---

### 8.3 Update employee

| Method | Path                                           | Description               |
| ------ | ---------------------------------------------- | ------------------------- |
| PATCH  | `/api/v1/restaurant-owner/employees/{id}`      | Update staff details      |

**Path params:**

- `id` – restaurant staff id.

**Request body (partial):**

```json
{
  "name": "Omar Updated",
  "email": "new-email@example.com",
  "phone": null,
  "restaurantRoleId": 3,
  "permissionIds": [1, 2, 3],
  "isActive": false
}
```

All fields are optional; send only changed values.

**Response (200):** Updated employee resource.

**Errors:**

- `403` – Staff not in current restaurant.
- `404` – Staff not found.
- `422` – Validation error.

---

### 8.4 Toggle employee status (quick action)

| Method | Path                                                   | Description                |
| ------ | ------------------------------------------------------ | -------------------------- |
| PATCH  | `/api/v1/restaurant-owner/employees/{id}/status`       | Activate/deactivate staff  |

**Request body:**

```json
{
  "isActive": false
}
```

| Field    | Type    | Required |
| -------- | ------- | -------- |
| isActive | boolean | yes      |

**Response (200):** Updated employee resource (or `204` depending on backend; assume 200 with resource).

**Errors:** Same as §8.3.

---

### 8.5 Get restaurant roles and permissions

This powers role/permission pickers in the staff management UI.

| Method | Path                                      | Description                          |
| ------ | ----------------------------------------- | ------------------------------------ |
| GET    | `/api/v1/restaurant-owner/permissions`    | List roles with their permissions    |

**Response (200):**

```json
{
  "data": {
    "roles": [
      {
        "id": 2,
        "name": "Manager",
        "slug": "manager",
        "permissionIds": [1, 2, 3],
        "permissions": [
          { "id": 1, "name": "orders.view" },
          { "id": 2, "name": "orders.update" },
          { "id": 3, "name": "products.manage" }
        ]
      }
    ]
  }
}
```

Use `roles[].permissionIds` for sending updates in `permissionIds` when creating/updating employees or roles.

---

## 9. Notifications (owner)

These power the in-app notification center for the restaurant owner app. They combine **user notifications** (order events, offers) and **system alerts** (maintenance, announcements).

### 9.1 List notifications

| Method | Path                                          | Description                                    |
| ------ | --------------------------------------------- | ---------------------------------------------- |
| GET    | `/api/v1/restaurant-owner/notifications`      | List owner notifications (paginated, filtered) |

**Query params:**

| Param      | Type   | Description |
| ---------- | ------ | ----------- |
| tab        | string | `all`, `orders`, `offers`, `system`. Default `all`. |
| unreadOnly | bool   | `true` – only unread notifications. |
| page       | int    | Page number. |
| perPage    | int    | 1–100, default 20. |

**Response (200):**

```json
{
  "data": [
    {
      "id": "user:9d4e8f2a-1b3c-4d5e-6f7a-8b9c0d1e2f3a",
      "source": "user_notification",
      "category": "orders",
      "title": "New order received",
      "body": "Order ORD-123 is waiting for your confirmation.",
      "meta": {
        "type": "order_created",
        "orderId": 123
      },
      "createdAt": "2026-03-10T10:00:00.000000Z",
      "isRead": false
    },
    {
      "id": "system:15",
      "source": "system_alert",
      "category": "system",
      "title": "System maintenance",
      "body": "Maintenance scheduled tonight at 2am.",
      "meta": {},
      "createdAt": "2026-03-09T22:00:00.000000Z",
      "isRead": true
    }
  ],
  "meta": {
    "page": 1,
    "perPage": 20,
    "total": 2,
    "lastPage": 1,
    "unreadTotal": 1,
    "tabCounts": {
      "all": 2,
      "orders": 1,
      "offers": 0,
      "system": 1
    }
  },
  "links": {}
}
```

| Field              | Type    | Description |
| ------------------ | ------- | ----------- |
| id                 | string  | Notification id with prefix: `user:{uuid}` or `system:{id}`. |
| source             | string  | `user_notification` or `system_alert`. |
| category           | string  | See §10.3 `OwnerNotificationCategory`. |
| title, body        | string  | Localized title/body. |
| meta               | object  | Payload for deep-linking (e.g. `orderId`, `offerId`). |
| createdAt          | string  | ISO datetime. |
| isRead             | boolean | True when user has read/acknowledged it. |
| meta.unreadTotal   | number  | Total unread notifications (for badge). |
| meta.tabCounts     | object  | Count per tab. |

Use `meta.type` and related ids for navigation:

- `order_created` → open order detail (using `meta.orderId`).
- `order_cancelled` → open order detail or list, etc.
- `offer_performance` → open offer detail.
- `system_announcement` → open system messages tab.

---

### 9.2 Mark one notification as read

| Method | Path                                                        | Description              |
| ------ | ----------------------------------------------------------- | ------------------------ |
| PATCH  | `/api/v1/restaurant-owner/notifications/{notificationId}/read` | Mark a notification read |

**Path params:**

- `notificationId` – the same id you received (e.g. `user:9d4e8f2a-...` or `system:15`).

**Request body:** Empty.

**Response:**

- `204 No Content` on success.

**Errors:**

- `404` – Notification not found or not owned by the user.

---

### 9.3 Mark multiple notifications as read

| Method | Path                                              | Description                 |
| ------ | ------------------------------------------------- | --------------------------- |
| PATCH  | `/api/v1/restaurant-owner/notifications/read-all` | Mark notifications as read  |

**Request body:**

```json
{
  "tab": "orders"
}
```

| Field | Type   | Required | Description |
| ----- | ------ | -------- | ----------- |
| tab   | string | no       | `all`, `orders`, `offers`, `system`. Default `all`. |

**Response:**

- `204 No Content`.

**Behavior:**

- Marks all notifications in the selected tab for this user as read/acknowledged.

---

## 10. Enums and common values

### 10.1 OrderStatus (owner)

Used in order payloads and filters for owner app.

| Value        | Description (Flutter UI) |
| ------------ | ------------------------ |
| `pending`    | New order waiting for confirmation. Owner can accept/reject and edit items. |
| `accepted`   | Confirmed by restaurant; in preparation. Items still editable. |
| `preparing`  | In the kitchen / being prepared. Items no longer editable. |
| `ready`      | Ready for pickup or handover to courier. |
| `on_the_way` | With courier (for delivery). |
| `completed`  | Delivered / finished. Read-only. |
| `cancelled`  | Cancelled (customer, restaurant, or system). Read-only. |

---

### 10.2 ProductAvailabilityMode

Values for `mode` in `/products/{id}/availability` and in product payloads.

| Value               | Description |
| ------------------- | ----------- |
| `available`         | Product is available normally. |
| `sold_out_today`    | Product is sold out until the end of the current day. |
| `manual_unavailable`| Product is unavailable indefinitely until manually changed. |

---

### 10.3 OwnerNotificationCategory

`category` field in owner notifications.

| Value    | Description |
| -------- | ----------- |
| `orders` | Order-related events (new, cancelled, delayed). |
| `offers` | Offers/coupons performance notifications. |
| `system` | System messages and maintenance alerts. |

---

### 10.4 OwnerNotificationMeta.type (examples)

`meta.type` helps the app decide where to navigate.

| Value               | Description |
| ------------------- | ----------- |
| `order_created`     | New order. Use `meta.orderId` to open order details. |
| `order_cancelled`   | Order cancelled. Use `meta.orderId`. |
| `offer_performance` | Offer performance threshold or summary. Use `meta.offerId`. |
| `system_announcement` | General system announcement (no specific id required). |

---

## 11. Example requests and responses

### 11.1 Get owner dashboard (today)

**Request:**

```http
GET https://dllni.mustafafares.com/api/v1/restaurant-owner/dashboard/performance
Authorization: Bearer {token}
```

**Response:** See §4.1.

---

### 11.2 Edit order item (quantity + note)

**Request:**

```http
PATCH https://dllni.mustafafares.com/api/v1/restaurant-owner/orders/123/items/1
Authorization: Bearer {token}
Content-Type: application/json

{
  "quantity": 3,
  "specialInstructions": "No onions, extra cheese"
}
```

**Response (200):** Updated order resource with recalculated payment breakdown.

---

### 11.3 Mark notification as read

**Request:**

```http
PATCH https://dllni.mustafafares.com/api/v1/restaurant-owner/notifications/user:9d4e8f2a-1b3c-4d5e-6f7a-8b9c0d1e2f3a/read
Authorization: Bearer {token}
```

**Response:** `204 No Content`.

---

### 11.4 Set product as sold out for today

**Request:**

```http
PATCH https://dllni.mustafafares.com/api/v1/restaurant-owner/products/10/availability
Authorization: Bearer {token}
Content-Type: application/json

{
  "mode": "sold_out_today",
  "note": "Sold out after 8pm"
}
```

**Response (200):**

```json
{
  "data": {
    "id": 10,
    "name": "Burger Combo",
    "price": 50.0,
    "isAvailableNow": false,
    "availabilityMode": "sold_out_today",
    "unavailableUntil": "2026-03-10T23:59:59.000000Z",
    "availabilityNote": "Sold out after 8pm"
  }
}
```

---

## 12. Error responses

- **401 Unauthorized:** Missing or invalid token. App should redirect to login.
- **403 Forbidden:** User is not a restaurant seller or resource does not belong to current restaurant.
- **404 Not Found:** Resource does not exist or is not visible in this restaurant scope.
- **422 Unprocessable Entity:** Validation errors or invalid state transition (e.g. editing items on a `completed` order). Body contains `message` and `errors` object as described in §2.4.

