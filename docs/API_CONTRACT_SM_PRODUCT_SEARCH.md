# API Contract for Flutter - Supermarket Product Search

**Audience:** Flutter developer  
**Domain:** `dllni.mustafafares.com`  
**Scope:** Dedicated search endpoint for supermarket products.

This contract documents the endpoint:
- `GET /api/v1/sm-products/search`

---

## 1. Base URL and authentication

- **Base URL:** `https://dllni.mustafafares.com`
- **Full endpoint URL:** `https://dllni.mustafafares.com/api/v1/sm-products/search`
- **Auth:** Laravel Sanctum
  - Header: `Authorization: Bearer {token}`
- **Response format:** JSON

---

## 2. Endpoint summary

| Method | Path                          | Description                             |
| ------ | ----------------------------- | --------------------------------------- |
| GET    | `/api/v1/sm-products/search` | Search/filter/sort supermarket products |

This endpoint uses the same product listing pipeline as `GET /api/v1/sm-products`, but with a dedicated, explicit search path.

---

## 3. Query parameters

### 3.1 Pagination

| Param   | Type    | Required | Default | Notes                         |
| ------- | ------- | -------- | ------- | ----------------------------- |
| perPage | integer | no       | 20      | Min `1`, max `100`            |
| page    | integer | no       | 1       | Standard Laravel pagination   |

### 3.2 Filters

Use `filter[...]` keys:

| Param                    | Type            | Required | Description |
| ------------------------ | --------------- | -------- | ----------- |
| filter[storeId]          | integer         | no       | Filter by store id (`sm_stores.id`). |
| filter[categoryId]       | integer         | no       | Filter by category id (`sm_categories.id`). |
| filter[barcode]          | string          | no       | Partial match on barcode. |
| filter[sourceType]       | string          | no       | Exact source type. Allowed: `BarcodeScan`, `CatalogSearch`, `Manual`, `Template`, `BulkImport`. |
| filter[isAvailable]      | boolean         | no       | Exact availability match (`true` / `false`). |
| filter[lowStock]         | boolean         | no       | If `true`, returns products where `stock_quantity <= low_stock_threshold`. |
| filter[expiringSoon]     | boolean         | no       | If `true`, returns products expiring within 7 days. |
| filter[search]           | string          | no       | Text search over `name`, `barcode`, `description`. |

### 3.3 Sorting

| Param | Type   | Required | Allowed values |
| ---- | ------ | -------- | -------------- |
| sort | string | no       | `name`, `-name`, `price`, `-price`, `stockQuantity`, `-stockQuantity`, `expiresAt`, `-expiresAt`, `createdAt`, `-createdAt` |

- Default sort (when `sort` is not provided): `-created_at` (newest first).

---

## 4. Example requests

### 4.1 Simple text search

```http
GET https://dllni.mustafafares.com/api/v1/sm-products/search?filter[search]=milk
Authorization: Bearer {token}
```

### 4.2 Search + availability + pagination

```http
GET https://dllni.mustafafares.com/api/v1/sm-products/search?filter[search]=chocolate&filter[isAvailable]=1&perPage=20&page=1
Authorization: Bearer {token}
```

### 4.3 Search + low stock + sort by lowest stock first

```http
GET https://dllni.mustafafares.com/api/v1/sm-products/search?filter[search]=juice&filter[lowStock]=true&sort=stockQuantity
Authorization: Bearer {token}
```

---

## 5. Response shape

**Success (200):** paginated product collection.

```json
{
  "data": [
    {
      "id": 101,
      "name": "Fresh Milk 1L",
      "barcode": "123456789",
      "price": "12.50",
      "stockQuantity": 8,
      "isAvailable": true
    }
  ],
  "links": {
    "first": "...",
    "last": "...",
    "prev": null,
    "next": "..."
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 3,
    "path": "...",
    "per_page": 20,
    "to": 20,
    "total": 52
  }
}
```

Notes:
- Actual product fields may include related store/category/media data based on backend resource mapping.
- Laravel validation failures return `422` with `errors`.
- Unauthorized requests return `401`.
- Forbidden store access (if enforced by policies/middleware) returns `403`.
