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

| Method | Path | Description |
| ------ | ---- | ----------- |
| GET | `/api/v1/sm-products/search` | Product listing endpoint with semantic smart search when a search term is provided |

Behavior:
- If a search term is provided (`query`, `search`, or `filter[search]`), the backend performs semantic search through the external smart-search service and returns matched products in relevance order.
- If no search term is provided, the endpoint falls back to the regular local listing/filtering pipeline.

---

## 3. Query parameters

### 3.1 Pagination

| Param | Type | Required | Default | Notes |
| ----- | ---- | -------- | ------- | ----- |
| perPage | integer | no | 20 | Min `1`, max `100` |
| page | integer | no | 1 | Standard Laravel pagination |
| top_k | integer | no | computed | Max semantic candidates fetched before pagination |

### 3.2 Smart search input

| Param | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| query | string | no | Preferred semantic search phrase |
| search | string | no | Backward-compatible search phrase |
| filter[search] | string | no | Backward-compatible search phrase |

Priority when multiple are present: `query` then `search` then `filter[search]`.

### 3.3 Semantic-compatible filters

| Param | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| store_id | integer | no | Semantic service store filter |
| category_id | integer | no | Semantic service category filter |
| price_min | number | no | Semantic service minimum price filter |
| price_max | number | no | Semantic service maximum price filter |
| is_available | boolean | no | Semantic service availability filter |

### 3.4 Legacy/local filters (fallback and compatibility)

| Param | Type | Required | Description |
| ----- | ---- | -------- | ----------- |
| filter[storeId] | integer | no | Filter by store id (`sm_stores.id`) |
| filter[categoryId] | integer | no | Filter by category id (`sm_categories.id`) |
| filter[barcode] | string | no | Partial match on barcode |
| filter[sourceType] | string | no | Exact source type: `BarcodeScan`, `CatalogSearch`, `Manual`, `Template`, `BulkImport` |
| filter[isAvailable] | boolean | no | Exact availability match |
| filter[lowStock] | boolean | no | Products where `stock_quantity <= low_stock_threshold` |
| filter[expiringSoon] | boolean | no | Products expiring within 7 days |

### 3.5 Sorting

| Param | Type | Required | Allowed values |
| ----- | ---- | -------- | -------------- |
| sort | string | no | `name`, `-name`, `price`, `-price`, `stockQuantity`, `-stockQuantity`, `expiresAt`, `-expiresAt`, `createdAt`, `-createdAt` |

Notes:
- Under semantic search mode, result order is relevance-first from smart search.
- Under local fallback mode, default sort remains `-created_at`.

---

## 4. Example requests

### 4.1 Semantic search (recommended)

```http
GET https://dllni.mustafafares.com/api/v1/sm-products/search?query=milk
Authorization: Bearer {token}
```

### 4.2 Semantic search with filters

```http
GET https://dllni.mustafafares.com/api/v1/sm-products/search?query=chocolate&store_id=2&is_available=1&perPage=20&page=1
Authorization: Bearer {token}
```

### 4.3 Backward-compatible search param

```http
GET https://dllni.mustafafares.com/api/v1/sm-products/search?filter[search]=juice&perPage=10
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
      "score": 0.94,
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
    "last_page": 1,
    "path": "...",
    "per_page": 20,
    "to": 1,
    "total": 1
  }
}
```

Notes:
- `score` is present when semantic mode is used. It is `null` in local fallback mode.
- Actual fields include the existing product resource payload (store/category/media and pricing fields).
- Validation failures return `422` with `errors`.
- Unauthorized requests return `401`.
- Forbidden access (if enforced by middleware/policies) returns `403`.

---

## 6. Smart-search integration notes

- Smart search calls an external semantic service and maps result ids back to local products before response serialization.
- If the semantic service is temporarily unavailable, the endpoint safely falls back to the local listing pipeline.
- Pagination format remains Laravel-compatible for Flutter client stability.
