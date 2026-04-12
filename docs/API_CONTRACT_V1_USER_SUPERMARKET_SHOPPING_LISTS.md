# API contract: user supermarket shopping lists (`/api/v1/user/supermarket/shopping-lists`)

Routes are registered in `Modules/User/routes/api.php` inside the authenticated user group (`auth:sanctum`).

**Base URL:** `{baseUrl}/api` (example: `http://Dllni.test/api`)

**Full prefix:** `/v1/user/supermarket/shopping-lists`

**Authentication:** `Authorization: Bearer {token}` (Laravel Sanctum)

**Content type:** `application/json` for create/update bodies

**Response wrapper:** JSON objects use a top-level `data` key (arrays for index, object for show/create/update).

Related: supermarket cart, checkout, and orders — [`API_CONTRACT_USER_ORDERS_AND_CART.md`](./API_CONTRACT_USER_ORDERS_AND_CART.md). Narrative version with the same rules — [`API_CONTRACT_USER_SUPERMARKET_SHOPPING_LISTS.md`](./API_CONTRACT_USER_SUPERMARKET_SHOPPING_LISTS.md).

---

## Domain rules (short)

| Rule | Detail |
| --- | --- |
| Ownership | Each list belongs to one user. Wrong id → **`404`** (`firstOrFail` on scoped query). |
| Line target | Items reference **`master_products.id`** (`masterProductId`), not `sm_products.id`. |
| Add to cart | For each line with `isIncluded: true`, the server loads the **first** matching `sm_products` row: same `storeId`, same `master_product_id`, `is_available = true`. Missing row → **`422`** on `storeId`. |
| Quantity in cart | List line `quantity` is numeric; when merging into cart: `max(1, round(quantity))` as **integer** cart quantity. |
| Empty add-to-cart | No included lines (or all excluded) → **`422`** on `items`. |
| Cart response | `POST …/add-to-cart` returns the **same** cart shape as `GET /api/v1/user/supermarket/cart`. |

---

## 1. List shopping lists (summaries)

| Property | Value |
| --- | --- |
| Method | `GET` |
| Path | `/v1/user/supermarket/shopping-lists` |
| Success | `200 OK` |

### Response `data[]` (each element)

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | `sm_smart_lists.id` |
| `name` | string | |
| `description` | string \| null | |
| `isActive` | boolean | |
| `itemsCount` | integer | From `withCount('items')` |
| `createdAt` | string \| null | `Y-m-d H:i:s` style from model |
| `updatedAt` | string \| null | Newest first |

### Errors

- `401` — unauthenticated.

---

## 2. Create shopping list

| Property | Value |
| --- | --- |
| Method | `POST` |
| Path | `/v1/user/supermarket/shopping-lists` |
| Success | `201 Created` |

### Request body (JSON)

| Field | Required | Type | Notes |
| --- | --- | --- | --- |
| `name` | Yes | string | max `255` |
| `description` | No | string \| null | |
| `isActive` | No | boolean | If omitted, server defaults to **`true`** (`prepareForValidation`) |

### Response `data`

Full **list detail** (same shape as §4), usually with `items: []`.

### Errors

- `422` — validation.
- `401` — unauthenticated.

---

## 3. Show shopping list

| Property | Value |
| --- | --- |
| Method | `GET` |
| Path | `/v1/user/supermarket/shopping-lists/{shoppingList}` |
| Success | `200 OK` |

Path `{shoppingList}`: integer list id (`whereNumber` in routes).

### Response `data`

| Field | Type |
| --- | --- |
| `id` | integer |
| `name` | string |
| `description` | string \| null |
| `isActive` | boolean |
| `items` | array of line objects (below) |
| `createdAt` | string \| null |
| `updatedAt` | string \| null |

### Line object (`items[]`)

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | `sm_smart_list_items.id` |
| `masterProductId` | integer | |
| `name` | string \| null | From related `master_products.name` at response time |
| `quantity` | number | Cast to float in API |
| `unit` | string \| null | |
| `sortOrder` | integer | Sort: `sortOrder` asc, then `id` asc |
| `isIncluded` | boolean | |
| `createdAt` | string \| null |
| `updatedAt` | string \| null |

### Errors

- `404` — not found or not owned.
- `401` — unauthenticated.

---

## 4. Update shopping list

| Property | Value |
| --- | --- |
| Method | `PATCH` |
| Path | `/v1/user/supermarket/shopping-lists/{shoppingList}` |
| Success | `200 OK` |

### Request body (JSON)

All keys optional; send only fields to change.

| Field | When present | Type | Notes |
| --- | --- | --- | --- |
| `name` | If key sent | string | `sometimes` + `required` → must be non-empty string, max `255` |
| `description` | Optional | string \| null | Can be sent to clear |
| `isActive` | Optional | boolean | `sometimes` |

### Response `data`

Full list detail (same as §3).

### Errors

- `401`, `404`, `422`.

---

## 5. Delete shopping list

| Property | Value |
| --- | --- |
| Method | `DELETE` |
| Path | `/v1/user/supermarket/shopping-lists/{shoppingList}` |
| Success | `204 No Content` |

### Errors

- `401`, `404`.

---

## 6. Add line item

| Property | Value |
| --- | --- |
| Method | `POST` |
| Path | `/v1/user/supermarket/shopping-lists/{shoppingList}/items` |
| Success | `201 Created` |

### Request body (JSON)

| Field | Required | Type | Notes |
| --- | --- | --- | --- |
| `masterProductId` | Yes | integer | `exists:master_products,id` |
| `quantity` | Yes | number | `0.01` … `9999` |
| `unit` | No | string \| null | max `50` |
| `sortOrder` | No | integer | `0` … `999999`; default **`0`** if omitted |
| `isIncluded` | No | boolean | default **`true`** if omitted |

### Response `data`

Full list detail including the new line.

### Errors

- `401`, `404`, `422` (e.g. invalid `masterProductId`).

---

## 7. Update line item

| Property | Value |
| --- | --- |
| Method | `PATCH` |
| Path | `/v1/user/supermarket/shopping-lists/{shoppingList}/items/{item}` |
| Success | `200 OK` |

Path `{item}`: `sm_smart_list_items.id` that belongs to that list.

### Request body (JSON)

You may send **`{}`** (no keys): nothing updates, full list detail is returned.

If a key is **present**, validation applies:

| Field | When key present | Type | Notes |
| --- | --- | --- | --- |
| `quantity` | Required value | number | `0.01` … `9999` |
| `sortOrder` | Required value | integer | `0` … `999999` |
| `isIncluded` | Required value | boolean | |

The service only updates attributes for keys that were validated and passed through.

### Errors

- `401`, `404` (wrong list or item not under list), `422`.

---

## 8. Delete line item

| Property | Value |
| --- | --- |
| Method | `DELETE` |
| Path | `/v1/user/supermarket/shopping-lists/{shoppingList}/items/{item}` |
| Success | `204 No Content` |

### Errors

- `401`, `404`.

---

## 9. Add list to cart (bulk)

| Property | Value |
| --- | --- |
| Method | `POST` |
| Path | `/v1/user/supermarket/shopping-lists/{shoppingList}/add-to-cart` |
| Success | `201 Created` |

### Request body (JSON)

| Field | Required | Type | Notes |
| --- | --- | --- | --- |
| `storeId` | Yes | integer | `exists:sm_stores,id` |

### Response `data`

Full **supermarket cart** payload — same structure as `GET /v1/user/supermarket/cart` (see orders/cart contract). Example shape:

```json
{
  "data": {
    "id": 1,
    "merchant": { "id": 20, "name": "Store name" },
    "items": [
      {
        "id": 7,
        "productId": 555,
        "name": "Product name",
        "quantity": 3,
        "unitPrice": 10.0,
        "totalPrice": 30.0
      }
    ],
    "amounts": { "subtotal": 30.0, "total": 30.0 }
  }
}
```

### Errors (typical)

| Status | Meaning |
| --- | --- |
| `422` | `items` — no included lines to add. |
| `422` | `storeId` — at least one included master product has no available `sm_products` row in that store (message includes failing `master_product_id`). |
| `401` | Unauthenticated. |
| `404` | List not found / not owned. |

---

## Suggested client flow

1. `GET /v1/user/supermarket/shopping-lists` — saved lists.
2. `GET /v1/user/supermarket/shopping-lists/{id}` — edit screen with lines.
3. `POST` / `PATCH` / `DELETE` on list or lines.
4. User selects store → `POST …/add-to-cart` with `{ "storeId": <store> }`.
5. `GET /v1/user/supermarket/cart` then checkout per orders/cart contract.

---

## Notes for apps

- Line `name` reflects the catalog (`master_products`) at response time.
- `isIncluded` lets the user keep a line but skip it when bulk-adding to cart.
