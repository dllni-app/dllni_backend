## 12. AI endpoints for supermarket products (Gemini)

These endpoints live under the main supermarket API namespace (not `/store-owner`) but are used by the supermarket owner app for faster product setup and content generation.

All three require standard Sanctum authentication and a configured Gemini API key on the backend (`config('gemini.api_key')`). If Gemini is disabled or misconfigured, the API may return a 5xx error.

### 12.1 توليد نص من صورة – Extract product data from image

- **Method:** `POST`  
- **Path:** `/api/v1/sm-products/ai/extract-from-image`

**Purpose:**  
Upload a single product image and let AI extract structured product data (name, description, maybe barcode/category) in the requested locale.

**Request (multipart/form-data):**

| Field  | Type   | Required | Description                                      |
| ------ | ------ | -------- | ------------------------------------------------ |
| image  | file   | yes      | Product image (`jpeg`, `png`, etc.), max ~8 MB. |
| locale | string | no       | One of `ar`, `en`. Default decided by backend.  |

Example:

```http
POST https://dllni.mustafafares.com/api/v1/sm-products/ai/extract-from-image
Authorization: Bearer {token}
Content-Type: multipart/form-data

image: (file)
locale: ar
```

**Response (200):**

```json
{
  "data": {
    "title": "كوكاكولا 330مل",
    "description": "مشروب غازي منعش...",
    "barcode": "1234567890123",
    "categoryName": "مشروبات"
  }
}
```

> **Note:** The exact keys in `data` may evolve; the Flutter app should treat this as dynamic product draft data and map only the fields it actually uses.

### 12.2 توليد صورة من نص – Generate product image from text

- **Method:** `POST`  
- **Path:** `/api/v1/sm-products/ai/generate-image`

**Purpose:**  
Given a product title and optional description, generate a product image (base64) that can be previewed/used in the app.

**Request (JSON):**

```json
{
  "title": "Classic Burger",
  "description": "Tasty burger description."
}
```

| Field       | Type   | Required | Description                          |
| ----------- | ------ | -------- | ------------------------------------ |
| title       | string | yes      | Short product title (max 255 chars). |
| description | string | no       | Longer description (max 2000 chars). |

**Response (200):**

```json
{
  "data": {
    "imageBase64": "iVBORw0KGgoAAAANSUhEUgAA..."
  }
}
```

The client should:

- Treat `imageBase64` as a standard image (e.g. `data:image/png;base64,...`) and preview it.
- Optionally allow the user to accept/reject the generated image before saving to the product.

### 12.3 استخراج من منيو – Extract products from menu image

- **Method:** `POST`  
- **Path:** `/api/v1/sm-products/ai/extract-from-menu`

**Purpose:**  
Upload a restaurant/supermarket menu image and let AI extract multiple products/items (name, price, category, etc.) as a list for bulk creation.

**Request (multipart/form-data):**

| Field  | Type   | Required | Description                                        |
| ------ | ------ | -------- | -------------------------------------------------- |
| image  | file   | yes      | Menu image (`jpeg`, `png`, etc.), max ~12 MB.     |
| locale | string | no       | One of `ar`, `en`. Default decided by backend.    |

Example:

```http
POST https://dllni.mustafafares.com/api/v1/sm-products/ai/extract-from-menu
Authorization: Bearer {token}
Content-Type: multipart/form-data

image: (file)
locale: en
```

**Response (200):**

```json
{
  "data": {
    "items": [
      {
        "title": "Margherita Pizza",
        "description": "Classic pizza with tomato and mozzarella.",
        "price": 8.5,
        "categoryName": "Pizza"
      }
    ]
  }
}
```

On the Flutter side, treat `data.items[]` as **draft items**:

- Show them in a review screen (user can edit, delete, or confirm).
- Only send final, user-approved products to the existing product creation endpoints.

# API Contract for Flutter – Supermarket Owner App

**Audience:** Flutter developer  
**Domain:** `dllni.mustafafares.com`  
**Scope:** Supermarket owner mobile app (store owner view for one or more managed stores).

For supermarket admin/reporting scope, see [API_CONTRACT_SUPERMARKET_ADMIN.md](API_CONTRACT_SUPERMARKET_ADMIN.md).  
For authentication details, see [API_CONTRACT_AUTH.md](API_CONTRACT_AUTH.md).

---

## 1. Base URL, authentication, and client behavior

- **Base URL:** `https://dllni.mustafafares.com`
- **API prefix:** All endpoints below are relative to the base URL:  
  `https://dllni.mustafafares.com/api/v1/...`
- **Auth:** Laravel Sanctum. Send token on every request:
  - Header: `Authorization: Bearer {token}`
  - Login / token issuance is out of scope of this contract (use existing auth endpoints).
- **Content-Type:** `application/json` for request bodies; responses are JSON.

### 1.1 Client behavior (UI/API usage)

- **GET (with ids/enums):**
  - If the API returns ids/enums (status, operation, rejectionType), show human labels in UI and send canonical ids/enums back.
- **POST (create):**
  - Backend-known values (store ids, product ids, order item ids) must come from prior GET responses and local state, not manual text entry.
- **PUT/PATCH (update):**
  - Prefer optimistic UI where it improves UX, then rollback on API failure.
- **Error handling:**
  - Do not silently swallow errors; show a visible message/toast/snackbar on 4xx / 5xx responses.

---

## 2. Global conventions

### 2.1 Pagination

- If an endpoint is paginated, use:
  - `perPage` (integer, 1–100, default endpoint-specific)
  - `page` (integer, default 1)

### 2.2 Filtering and sorting

- Filters are endpoint-specific and usually passed as query params.
- Sort is endpoint-specific and may be unavailable on some owner endpoints.

### 2.3 Request / response casing

- Use the exact casing returned by each endpoint.
- In this module, both `camelCase` and `snake_case` appear depending on endpoint family.
  - Example: `storeId` in dashboard, `store_id` in inventory reports.

### 2.4 Single-resource responses

- **Success:** usually HTTP 200 with `{ "data": { ... } }` or `{ "success": true, ... }` depending on endpoint.
- **Create:** usually HTTP 201 with created resource.
- **Delete:** typically HTTP 200/204 depending on route implementation.
- **Errors:** 4xx/5xx with JSON; validation errors under `errors` for Laravel validation failures.

---

## 3. Store scoping and ownership

All `/store-owner/*` endpoints are owner-scoped by authenticated user permissions.

- Some endpoints require explicit store identifier in query/body:
  - `storeId` (dashboard)
  - `storeId` (employees list/create)
  - `store_id` (inventory/report endpoints)
- Owner app should use only stores the authenticated owner is allowed to manage.
- Backend must reject unauthorized cross-store access with `403`.

**Base path for this contract:** `/api/v1/store-owner`

---

## 4. Screen → endpoint mapping (Flutter widgets)

This maps common supermarket owner app screens to backend endpoints.

### 4.1 Home dashboard (today KPIs + order queues)

- **Top KPI cards (today):**
  - `GET /api/v1/store-owner/dashboard?storeId={id}`
  - Use `totalOrders`, `completedOrders`, `newOrders`, `pendingOrders`, `totalSales`.
- **New orders list + quick actions:**
  - Use existing order list endpoint in app state (if available in broader module).
  - Action endpoints:
    - `POST /api/v1/store-owner/orders/{order}/accept`
    - `POST /api/v1/store-owner/orders/{order}/reject`

### 4.2 Orders management (accept / reject / return)

- **Accept order:** `POST /api/v1/store-owner/orders/{order}/accept`
- **Reject order:** `POST /api/v1/store-owner/orders/{order}/reject`
- **Return processing:** `POST /api/v1/store-owner/orders/{order}/return`

### 4.3 Store profile screens

- **Store details:** `GET /api/v1/store-owner/stores/{store}`
- **Edit store profile:** `PUT /api/v1/store-owner/stores/{store}`

### 4.4 Products screens

- **List products:** `GET /api/v1/store-owner/products`
- **Create product:** `POST /api/v1/store-owner/products`
- **Search master products by prefix (name/barcode):** `GET /api/v1/store-owner/master-products/search?index={text}`
- **Create products from master products (bulk):** `POST /api/v1/store-owner/products/from-master`
- **View one product:** `GET /api/v1/store-owner/products/{id}`
- **Update product:** `PUT /api/v1/store-owner/products/{id}`
- **Delete product:** `DELETE /api/v1/store-owner/products/{id}`
- **Stock quick update:** `PUT /api/v1/store-owner/products/{product}/stock`
- **Expiration update:** `PUT /api/v1/store-owner/products/{product}/expiration`
- **AI add product options (image/menu/text):**
  - Single-product from image: `POST /api/v1/sm-products/ai/extract-from-image` – suggests draft `title` / `description` / optional metadata (see §12.1).
  - Multiple items from menu image: `POST /api/v1/sm-products/ai/extract-from-menu` – returns `items[]` drafts for bulk creation (see §12.3).
  - Generate image from text: `POST /api/v1/sm-products/ai/generate-image` – returns `imageBase64` for preview / upload (see §12.2).

### 4.5 Inventory screens

- **Low stock alerts:** `GET /api/v1/store-owner/products/low-stock?store_id={id}`
- **Inventory audit:** `POST /api/v1/store-owner/inventory/audit`
- **Lost opportunities report:** `GET /api/v1/store-owner/reports/lost-opportunities?store_id={id}`

### 4.6 Employees and permissions screens

- **List employees for one store:** `GET /api/v1/store-owner/employees?storeId={id}`
- **Add new employee:** `POST /api/v1/store-owner/employees`
- **Update employee profile and permissions:** `PATCH /api/v1/store-owner/employees/{staff}`
- **Toggle employee active status:** `PATCH /api/v1/store-owner/employees/{staff}/status`
- **Load permission catalog for checkbox screen:** `GET /api/v1/store-owner/permissions`

The employee permission picker should use the permission ids returned by the permissions endpoint.
Send those ids back in `permissionIds[]` during create/update.

---

## 5. Dashboard – owner overview (today)

### 5.1 Get dashboard overview

| Method | Path                            | Description                      |
| ------ | ------------------------------- | -------------------------------- |
| GET    | `/api/v1/store-owner/dashboard` | Today metrics for one store      |

**Query params:**

| Param   | Type    | Required | Description                    |
| ------- | ------- | -------- | ------------------------------ |
| storeId | integer | yes      | Store id (`sm_stores.id`).     |

**Response (200):**

```json
{
  "message": "Dashboard data retrieved successfully.",
  "data": {
    "totalOrders": 20,
    "completedOrders": 7,
    "newOrders": 5,
    "pendingOrders": 8,
    "totalSales": 4523.75
  }
}
```

| Field           | Type   | Description                                              |
| --------------- | ------ | -------------------------------------------------------- |
| totalOrders     | number | Total orders created today.                              |
| completedOrders | number | Orders completed today.                                  |
| newOrders       | number | Orders with status `pending` created today.              |
| pendingOrders   | number | Non-completed and non-cancelled orders created today.    |
| totalSales      | number | Sum of completed orders total amount for today.          |

---

## 6. Orders (owner actions)

### 6.1 Accept order

| Method | Path                                      | Description      |
| ------ | ----------------------------------------- | ---------------- |
| POST   | `/api/v1/store-owner/orders/{order}/accept` | Accept an order |

**Path params:**

- `order` – order id.

**Response (200):**

- Returns updated order resource with status set to `accepted`.
- Includes related `customer`, `store`, `items`, and monetary fields.

### 6.2 Reject order

| Method | Path                                      | Description      |
| ------ | ----------------------------------------- | ---------------- |
| POST   | `/api/v1/store-owner/orders/{order}/reject` | Reject an order |

**Request body:**

```json
{
  "reason": "Out of stock for requested items",
  "rejectionType": "out_of_stock"
}
```

| Field         | Type   | Required | Description                                |
| ------------- | ------ | -------- | ------------------------------------------ |
| reason        | string | yes      | Human-readable rejection reason.           |
| rejectionType | string | yes      | One of `out_of_stock`, `fake_order`, `other`. |

**Response (200):**

- Returns updated order resource with status set to `cancelled` and cancellation fields.

### 6.3 Process order return

| Method | Path                                      | Description                    |
| ------ | ----------------------------------------- | ------------------------------ |
| POST   | `/api/v1/store-owner/orders/{order}/return` | Process returned items and restock |

**Request body:**

```json
{
  "items": [
    {
      "order_item_id": 1,
      "quantity": 2
    }
  ],
  "reason": "Customer reported defective product"
}
```

**Response (200):**

```json
{
  "success": true,
  "message": "Order return processed successfully.",
  "data": {
    "order_id": 1,
    "order_number": "ORD-001",
    "returned_items": [
      {
        "product_id": 5,
        "product_name": "Fresh Milk",
        "returned_quantity": 2,
        "new_stock": 52
      }
    ],
    "reason": "Customer reported defective product"
  }
}
```

### 6.4 Automatic stock deduction on accept

No separate endpoint is required.

- When order is accepted via `POST /store-owner/orders/{order}/accept`:
  - Stock is deducted for all order items.
  - Inventory logs are created.
  - `StockUpdated` event is fired.
  - Operation is transactional.

If stock is insufficient, API returns failure (business error message).

---

## 7. Store profile management

### 7.1 Get store details

| Method | Path                                 | Description               |
| ------ | ------------------------------------ | ------------------------- |
| GET    | `/api/v1/store-owner/stores/{store}` | Retrieve store details    |

**Response (200):**

```json
{
  "data": {
    "id": 1,
    "ownerUserId": 5,
    "name": "My Store",
    "slug": "my-store",
    "description": "Fresh groceries",
    "address": "123 Main Street, City, Country",
    "city": "Amman",
    "neighborhood": "Abdoun",
    "phone": "+1234567890",
    "email": "store@example.com",
    "cover": "https://cdn.example.com/stores/cover.jpg",
    "logo": "https://cdn.example.com/stores/logo.jpg",
    "isActive": true,
    "isFeatured": false,
    "createdAt": "2025-02-01T09:00:00.000000Z",
    "updatedAt": "2025-02-20T10:30:00.000000Z"
  }
}
```

### 7.2 Update store

| Method | Path                                 | Description         |
| ------ | ------------------------------------ | ------------------- |
| PUT    | `/api/v1/store-owner/stores/{store}` | Update store data   |

**Request body example:**

```json
{
  "name": "Updated Store Name",
  "description": "Updated description",
  "city": "Amman",
  "neighborhood": "Abdoun",
  "cover": "https://cdn.example.com/stores/cover.jpg",
  "logo": "https://cdn.example.com/stores/logo.jpg",
  "phone": "+1234567890"
}
```

**Response (200):** Updated store resource.

---

## 8. Products, inventory, and employee management

### 8.1 Product CRUD

| Method | Path                                | Description     |
| ------ | ----------------------------------- | --------------- |
| GET    | `/api/v1/store-owner/products`      | List products   |
| POST   | `/api/v1/store-owner/products`      | Create product  |
| GET    | `/api/v1/store-owner/products/{id}` | Get product     |
| PUT    | `/api/v1/store-owner/products/{id}` | Update product  |
| DELETE | `/api/v1/store-owner/products/{id}` | Delete product  |

Payloads/responses follow the supermarket product API conventions in the module.

### 8.1.1 Master product prefix search

| Method | Path                                        | Description                                           |
| ------ | ------------------------------------------- | ----------------------------------------------------- |
| GET    | `/api/v1/store-owner/master-products/search` | Autocomplete master products by prefix on name/barcode |

**Query params:**

| Param   | Type    | Required | Description |
| ------- | ------- | -------- | ----------- |
| index   | string  | yes      | Prefix text typed by owner (e.g. `s`, `se`, `123`). |
| perPage | integer | no       | Items per page (default 20, max 50). |
| page    | integer | no       | Page number (default 1). |

**Response (200):**

```json
{
  "current_page": 1,
  "data": [
    {
      "id": 11,
      "masterProductId": 11,
      "name": "Sesame Oil",
      "barcode": "1234567890123"
    }
  ],
  "per_page": 20,
  "total": 1
}
```

Search behavior:
- Matches active master products only.
- Prefix check is applied to both `name` and `barcode`.
- Typing `s` returns names/barcodes starting with `s`; typing `se` narrows to values starting with `se`.

### 8.1.2 Create product from master product

| Method | Path                                      | Description                                        |
| ------ | ----------------------------------------- | -------------------------------------------------- |
| POST   | `/api/v1/store-owner/products/from-master` | Bulk create store products linked to `master_product_id` |

**Request body example:**

```json
{
  "storeId": 1,
  "products": [
    {
      "categoryId": 4,
      "masterProductId": 11,
      "title": "Sesame Oil Premium",
      "price": 3.75,
      "stockQuantity": 25,
      "lowStockThreshold": 5,
      "discountedPrice": 3.25,
      "description": "Optional override description",
      "expiresAt": "2026-12-31",
      "isAvailable": true
    },
    {
      "categoryId": 4,
      "masterProductId": 12,
      "title": "Olive Oil 1L",
      "price": 6.5,
      "stockQuantity": 14
    }
  ]
}
```

| Field            | Type    | Required | Description |
| ---------------- | ------- | -------- | ----------- |
| storeId          | integer | yes      | Owner-managed store id. |
| products         | array   | yes      | One or more products to create in one request. |
| products[].categoryId       | integer | yes      | Product category id for this store. |
| products[].masterProductId  | integer | yes      | Existing active master product id. |
| products[].title            | string  | yes      | Product title used as created product name (max 255). |
| products[].price            | number  | yes      | Product price. |
| products[].stockQuantity    | integer | yes      | Starting quantity in stock. |
| products[].lowStockThreshold| integer | no       | Low stock alert threshold (default 0). |
| products[].discountedPrice  | number  | no       | Optional discounted price (must be <= `products[].price`). |
| products[].description      | string  | no       | Optional override description. |
| products[].expiresAt        | string  | no       | Optional expiration date/datetime. |
| products[].isAvailable      | boolean | no       | Optional availability flag (default true). |

**Response (201):**

Returns a collection of created supermarket product resources under `data[]`, each including:
- `masterProductId`
- `name` (copied from `products[].title`)
- `barcode` (copied from master product)
- `price`
- `stockQuantity`

### 8.2 Low stock alerts

| Method | Path                                     | Description                     |
| ------ | ---------------------------------------- | ------------------------------- |
| GET    | `/api/v1/store-owner/products/low-stock` | Products below stock threshold  |

**Query params:**

| Param    | Type    | Required | Description |
| -------- | ------- | -------- | ----------- |
| store_id | integer | yes      | Store id.   |

**Response (200):**

```json
{
  "success": true,
  "data": {
    "products": [
      {
        "product_id": 1,
        "product_name": "Fresh Milk",
        "current_stock": 5,
        "threshold": 10,
        "category": "Dairy",
        "barcode": "1234567890123"
      }
    ],
    "total": 1
  }
}
```

### 8.3 Manual stock update

| Method | Path                                        | Description                        |
| ------ | ------------------------------------------- | ---------------------------------- |
| PUT    | `/api/v1/store-owner/products/{product}/stock` | Set/increment/decrement stock   |

**Request body:**

```json
{
  "quantity": 50,
  "operation": "SET"
}
```

| Field     | Type   | Required | Description                              |
| --------- | ------ | -------- | ---------------------------------------- |
| quantity  | number | yes      | Quantity value used by selected operation. |
| operation | string | yes      | `SET`, `INCREMENT`, or `DECREMENT`.      |

### 8.4 Inventory audit

| Method | Path                                  | Description                         |
| ------ | ------------------------------------- | ----------------------------------- |
| POST   | `/api/v1/store-owner/inventory/audit` | Reconcile system stock vs physical  |

**Request body:**

```json
{
  "store_id": 1,
  "products": [
    {
      "product_id": 1,
      "actual_stock": 45
    }
  ]
}
```

### 8.5 Update product expiration

| Method | Path                                             | Description                          |
| ------ | ------------------------------------------------ | ------------------------------------ |
| PUT    | `/api/v1/store-owner/products/{product}/expiration` | Set expiration and receive discount suggestion |

**Request body:**

```json
{
  "expires_at": "2026-03-10T00:00:00+00:00"
}
```

If product expires soon (within 7 days), response includes `suggested_discount` object.

### 8.6 Lost opportunities report

| Method | Path                                           | Description                                   |
| ------ | ---------------------------------------------- | --------------------------------------------- |
| GET    | `/api/v1/store-owner/reports/lost-opportunities` | Out-of-stock demand / missed sales insights |

**Query params:**

| Param      | Type    | Required | Description                  |
| ---------- | ------- | -------- | ---------------------------- |
| store_id   | integer | yes      | Store id.                    |
| start_date | date    | no       | Start date (`Y-m-d`).        |
| end_date   | date    | no       | End date (`Y-m-d`).          |

### 8.7 Permission catalog for employee management

| Method | Path                               | Description                                 |
| ------ | ---------------------------------- | ------------------------------------------- |
| GET    | `/api/v1/store-owner/permissions`  | List assignable employee permissions        |

**Response (200):**

```json
{
  "data": {
    "permissions": [
      {
        "id": 1,
        "name": "products.view",
        "slug": null,
        "group": null
      },
      {
        "id": 2,
        "name": "orders.view",
        "slug": null,
        "group": null
      }
    ]
  }
}
```

The current catalog is intended for employee-facing store operations and includes permissions from these families:

- `products.*`
- `orders.*`
- `inventory.*`
- `staff.*`
- `stores.*`
- `offers.*`
- `coupons.*`
- `reports.view`

### 8.8 Employee management

Employees are store-linked users managed by the store owner. Each employee item returns:

- `id`: staff record id
- `storeId`: owning store id
- `userId`: linked user id
- `isActive`: whether the employee account is active for that store
- `user`: employee basic profile (`id`, `name`, `email`, `phone`)
- `permissionIds`: numeric permission ids currently assigned to that employee
- `effectivePermissions`: permission names currently assigned to that employee

### 8.8.1 List employees

| Method | Path                            | Description                 |
| ------ | ------------------------------- | --------------------------- |
| GET    | `/api/v1/store-owner/employees` | List employees for a store  |

**Query params:**

| Param   | Type    | Required | Description                |
| ------- | ------- | -------- | -------------------------- |
| storeId | integer | yes      | Store id (`sm_stores.id`). |

**Response (200):**

```json
{
  "data": {
    "employees": [
      {
        "id": 7,
        "storeId": 1,
        "userId": 22,
        "isActive": true,
        "user": {
          "id": 22,
          "name": "Store Employee",
          "email": "store.employee@example.com",
          "phone": "+963955000111"
        },
        "permissionIds": [1, 5],
        "effectivePermissions": ["products.view", "orders.view"],
        "createdAt": "2026-03-15 12:00:00",
        "updatedAt": "2026-03-15 12:00:00"
      }
    ]
  }
}
```

### 8.8.2 Create or link employee

| Method | Path                            | Description                            |
| ------ | ------------------------------- | -------------------------------------- |
| POST   | `/api/v1/store-owner/employees` | Create a new employee or link a user   |

**Request body example:**

```json
{
  "storeId": 1,
  "name": "Store Employee",
  "email": "store.employee@example.com",
  "phone": "+963955000111",
  "permissionIds": [1, 5, 9],
  "isActive": true
}
```

| Field         | Type          | Required | Description |
| ------------- | ------------- | -------- | ----------- |
| storeId       | integer       | yes      | Store id owned by the authenticated owner. |
| name          | string        | yes      | Employee display name. |
| email         | string/null   | no       | Employee email. |
| phone         | string/null   | no       | Employee phone. |
| permissionIds | integer[]     | no       | Permission ids selected from `/store-owner/permissions`. |
| isActive      | boolean       | no       | Defaults to `true` when omitted. |

At least one of `email` or `phone` is recommended so the owner can identify/link the employee account later.

**Response (201):**

```json
{
  "data": {
    "id": 7,
    "storeId": 1,
    "userId": 22,
    "isActive": true,
    "user": {
      "id": 22,
      "name": "Store Employee",
      "email": "store.employee@example.com",
      "phone": "+963955000111"
    },
    "permissionIds": [1, 5, 9],
    "effectivePermissions": ["products.view", "orders.view", "offers.view"],
    "createdAt": "2026-03-15 12:00:00",
    "updatedAt": "2026-03-15 12:00:00"
  },
  "message": "Employee created successfully."
}
```

### 8.8.3 Update employee profile and permissions

| Method | Path                                    | Description                           |
| ------ | --------------------------------------- | ------------------------------------- |
| PATCH  | `/api/v1/store-owner/employees/{staff}` | Update employee profile/permissions   |

**Request body example:**

```json
{
  "name": "Updated Employee",
  "email": "updated.employee@example.com",
  "phone": "+963955000222",
  "permissionIds": [2, 10],
  "isActive": true
}
```

All fields are optional. If `permissionIds` is present, the backend replaces the employee's direct permission set with the submitted ids.

**Response (200):**

Returns the same employee object shape as create/list plus:

```json
{
  "message": "Employee updated successfully."
}
```

### 8.8.4 Toggle employee status

| Method | Path                                           | Description                    |
| ------ | ---------------------------------------------- | ------------------------------ |
| PATCH  | `/api/v1/store-owner/employees/{staff}/status` | Activate/deactivate employee   |

**Request body:**

```json
{
  "isActive": false
}
```

**Response (200):**

Returns the same employee object shape plus:

```json
{
  "message": "Employee status updated successfully."
}
```

---

## 9. Enums and common values

### 9.1 OrderStatus (supermarket owner)

| Value              | Description                |
| ------------------ | -------------------------- |
| `pending`          | New, awaiting acceptance.  |
| `accepted`         | Accepted by store.         |
| `preparing`        | Store is preparing order.  |
| `ready_for_pickup` | Ready for customer pickup. |
| `completed`        | Completed / picked up.     |
| `cancelled`        | Cancelled order.           |

### 9.2 RejectionType

| Value          | Description                      |
| -------------- | -------------------------------- |
| `out_of_stock` | Requested products unavailable.  |
| `fake_order`   | Suspected invalid/fake order.    |
| `other`        | Other reason supplied in `reason`. |

### 9.3 StockOperation

| Value       | Description                                  |
| ----------- | -------------------------------------------- |
| `SET`       | Replace stock with exact quantity.           |
| `INCREMENT` | Add quantity to current stock.               |
| `DECREMENT` | Subtract quantity from current stock.        |

---

## 10. Example requests

### 10.1 Get owner dashboard (today)

```http
GET https://dllni.mustafafares.com/api/v1/store-owner/dashboard?storeId=1
Authorization: Bearer {token}
```

### 10.2 Reject order

```http
POST https://dllni.mustafafares.com/api/v1/store-owner/orders/123/reject
Authorization: Bearer {token}
Content-Type: application/json

{
  "reason": "Out of stock for requested items",
  "rejectionType": "out_of_stock"
}
```

### 10.3 Update stock

```http
PUT https://dllni.mustafafares.com/api/v1/store-owner/products/1/stock
Authorization: Bearer {token}
Content-Type: application/json

{
  "quantity": 50,
  "operation": "SET"
}
```

### 10.4 Process return

```http
POST https://dllni.mustafafares.com/api/v1/store-owner/orders/123/return
Authorization: Bearer {token}
Content-Type: application/json

{
  "items": [
    {
      "order_item_id": 1,
      "quantity": 2
    }
  ],
  "reason": "Customer reported defective product"
}
```

### 10.5 Create employee

```http
POST https://dllni.mustafafares.com/api/v1/store-owner/employees
Authorization: Bearer {token}
Content-Type: application/json

{
  "storeId": 1,
  "name": "Store Employee",
  "email": "store.employee@example.com",
  "phone": "+963955000111",
  "permissionIds": [1, 5],
  "isActive": true
}
```

### 10.6 Update employee status

```http
PATCH https://dllni.mustafafares.com/api/v1/store-owner/employees/7/status
Authorization: Bearer {token}
Content-Type: application/json

{
  "isActive": false
}
```

---

## 11. Error responses

- **401 Unauthorized:** Missing or invalid token.

```json
{
  "message": "Unauthenticated."
}
```

- **403 Forbidden:** User not allowed to access store/resource.

```json
{
  "message": "This action is unauthorized."
}
```

- **404 Not Found:** Resource does not exist or not visible to owner.

```json
{
  "message": "Not found."
}
```

- **422 Unprocessable Entity:** Validation/state errors.

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "storeId": [
      "The store id field is required."
    ]
  }
}
```

- **400 Bad Request:** Business rule failures in some inventory/order operations (e.g., negative stock outcome).
