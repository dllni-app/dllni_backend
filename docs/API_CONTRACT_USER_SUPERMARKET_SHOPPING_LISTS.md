# API Contract — User Supermarket Shopping Lists (Smart lists)

Base path: `/api/v1/user`  
Auth: **required** for all endpoints in this document (`auth:sanctum`)

Related: supermarket cart, checkout, and orders are documented in [`API_CONTRACT_USER_ORDERS_AND_CART.md`](./API_CONTRACT_USER_ORDERS_AND_CART.md).

Compact **v1 table layout** (paths, field tables, errors): [`API_CONTRACT_V1_USER_SUPERMARKET_SHOPPING_LISTS.md`](./API_CONTRACT_V1_USER_SUPERMARKET_SHOPPING_LISTS.md).

---

## Purpose

Shopping lists let the authenticated user save **named collections** of **catalog (master) products** (`masterProductId`). The list can be created without a store, then later linked to a store before add-to-cart so included lines can resolve to concrete **`sm_products`** rows in that store and append them to the supermarket cart.

In addition, a shopping list can be configured with an optional **auto-order schedule**. When active, the backend will create and submit a supermarket order automatically based on the list's included items.

---

## Domain rules

- **Ownership**: `shoppingList` must belong to the current user. Otherwise the API responds with **`404`** (model not found).
- **Line identity**: List rows reference **`master_products.id`**, not `sm_products.id`.
- **Store requirement for add-to-cart**: The list must have **`storeId`** set before calling add-to-cart.
- **Resolution on add-to-cart**: For each row with `isIncluded: true`, the server picks the **first available** `sm_products` row where `store_id = list.storeId`, `master_product_id` matches, and `is_available` is true. If no row exists for a given master product in that store, the request fails with **`422`** (see errors below).
- **Quantities**: List line `quantity` is a decimal in API payloads; when adding to cart it is converted with `max(1, round(quantity))` as an integer line quantity on the cart.
- **Cart result**: `POST …/add-to-cart` returns the **same cart payload shape** as `GET /supermarket/cart` (see orders/cart contract).
- **Schedule support (v1)**: `weekly`, `monthly`.
- **Schedule run model**: Schedule uses selected days plus one or more time periods. When due, backend runs processing and attempts to create an order.
- **Scheduled order item source**: Only rows with `isIncluded: true` are used.
- **Scheduled quantity conversion**: Decimal list quantity is converted with `max(1, round(quantity))`.
- **Scheduled product mapping**: Mapping uses list `storeId` and each row `masterProductId` -> `sm_products.master_product_id` with `is_available = true`.
- **Scheduled failure behavior**: If any included item cannot be mapped to an available store product, the whole scheduled run fails (no partial order), and the user receives an Arabic failure notification.
- **Scheduled success notification**: On successful scheduled order creation, user receives Arabic notification (database + push when available).

---

## Schedule payload

The schedule object is sent under `schedule` on create/update list endpoints.

**Fields**

- `schedule.isActive` (optional, boolean, default: `true` when schedule object is sent)
- `schedule.frequencyType` (required with `schedule`, enum: `weekly | monthly`)
- `schedule.weekDays` (required when `frequencyType = weekly`, array of integers `0..6`)
- `schedule.monthDays` (required when `frequencyType = monthly`, array of integers `1..31`)
- `schedule.periods` (required with `schedule`, array of time windows)
- `schedule.periods[].label` (optional, string)
- `schedule.periods[].fromTime` (required, `HH:mm`)
- `schedule.periods[].toTime` (required, `HH:mm`)

If `schedule` is omitted in update, existing schedule remains unchanged.

---

## List shopping lists (summaries)

`GET /supermarket/shopping-lists`

Returns all lists for the user, newest `updatedAt` first.

**200 Response**

```json
{
  "data": [
    {
      "id": 10,
      "name": "Home essentials",
      "description": "Weekly basics",
      "isActive": true,
      "itemsCount": 4,
      "createdAt": "2026-04-11 10:00:00",
      "updatedAt": "2026-04-11 11:30:00"
    }
  ]
}
```

---

## Create shopping list

`POST /supermarket/shopping-lists`

**Body**

- `name` (required, string, max: `255`)
- `description` (optional, string|null)
- `isActive` (optional, boolean, default: `true` if omitted)
- `schedule` (optional, object; see “Schedule payload”)

**201 Response**

Returns the **full list detail** shape (same as “Show shopping list”), including `items` (usually empty right after create).

```json
{
  "data": {
    "id": 10,
    "storeId": null,
    "name": "Home essentials",
    "description": "Weekly basics",
    "isActive": true,
    "schedule": {
      "frequencyType": "weekly",
      "weekDays": [0, 6],
      "monthDays": null,
      "periods": [
        {
          "label": "الفترة الأولى",
          "fromTime": "09:00",
          "toTime": "11:00"
        }
      ],
      "isActive": true,
      "nextRunAt": "2026-04-18 00:00:00",
      "lastRunAt": null
    },
    "items": [],
    "createdAt": "2026-04-11 10:00:00",
    "updatedAt": "2026-04-11 10:00:00"
  }
}
```

---

## Show shopping list

`GET /supermarket/shopping-lists/{shoppingList}`

**Path params**

- `shoppingList` (required, integer): list id

**200 Response**

Items are ordered by `sortOrder`, then `id`.

```json
{
  "data": {
    "id": 10,
    "storeId": null,
    "name": "Home essentials",
    "description": "Weekly basics",
    "isActive": true,
    "schedule": {
      "frequencyType": "weekly",
      "weekDays": [0, 6],
      "monthDays": null,
      "periods": [
        {
          "label": "الفترة الأولى",
          "fromTime": "09:00",
          "toTime": "11:00"
        }
      ],
      "isActive": true,
      "nextRunAt": "2026-04-18 00:00:00",
      "lastRunAt": "2026-04-11 00:00:00"
    },
    "items": [
      {
        "id": 100,
        "masterProductId": 50,
        "name": "Labneh 250g",
        "quantity": 2.0,
        "unit": "pack",
        "sortOrder": 0,
        "isIncluded": true,
        "createdAt": "2026-04-11 10:05:00",
        "updatedAt": "2026-04-11 10:05:00"
      }
    ],
    "createdAt": "2026-04-11 10:00:00",
    "updatedAt": "2026-04-11 11:30:00"
  }
}
```

**Errors**

- **`401`**: not authenticated
- **`404`**: list does not exist or not owned by user

---

## Update shopping list

`PATCH /supermarket/shopping-lists/{shoppingList}`

**Path params**

- `shoppingList` (required, integer)

**Body** (all optional; send only fields to change)

- `name` (optional; if the key is sent, required string, max: `255`)
- `description` (optional, string|null — may be sent to clear)
- `isActive` (optional, boolean)
- `storeId` (optional, integer|null, exists: `sm_stores,id`)
- `schedule` (optional, object; see “Schedule payload”)

**200 Response**

Full list detail (same shape as “Show shopping list”).

**Errors**

- **`401`**, **`404`**
- **`422`**: validation errors on body

Common schedule validation examples:

- `schedule.frequencyType = weekly` without `schedule.weekDays`
- `schedule.frequencyType = monthly` without `schedule.monthDays`
- missing `schedule.periods` or an empty periods list

---

## Delete shopping list

`DELETE /supermarket/shopping-lists/{shoppingList}`

**Path params**

- `shoppingList` (required, integer)

**204 Response**

Empty body.

**Errors**

- **`401`**, **`404`**

---

## Add line item to a list

`POST /supermarket/shopping-lists/{shoppingList}/items`

**Path params**

- `shoppingList` (required, integer)

**Body**

- `masterProductId` (required, integer, must exist in `master_products`)
- `quantity` (required, numeric, `0.01`..`9999`)
- `unit` (optional, string, max: `50`)
- `sortOrder` (optional, integer, `0`..`999999`, default: `0`)
- `isIncluded` (optional, boolean, default: `true`)

**201 Response**

Full list detail including the new line.

**Errors**

- **`401`**, **`404`**
- **`422`**: validation (e.g. unknown `masterProductId`)

---

## Update line item on a list

`PATCH /supermarket/shopping-lists/{shoppingList}/items/{item}`

**Path params**

- `shoppingList` (required, integer)
- `item` (required, integer): `sm_smart_list_items.id` belonging to that list

**Body** (empty `{}` is accepted and returns the unchanged list)

- `quantity` (optional; if the key is sent, required numeric, `0.01`..`9999`)
- `sortOrder` (optional; if the key is sent, required integer, `0`..`999999`)
- `isIncluded` (optional; if the key is sent, required boolean)

**200 Response**

Full list detail.

**Errors**

- **`401`**, **`404`** (wrong list, or item not under list)

---

## Delete line item from a list

`DELETE /supermarket/shopping-lists/{shoppingList}/items/{item}`

**Path params**

- `shoppingList` (required, integer)
- `item` (required, integer)

**204 Response**

Empty body.

**Errors**

- **`401`**, **`404`**

---

## Add list to cart (bulk)

`POST /supermarket/shopping-lists/{shoppingList}/add-to-cart`

Resolves every **included** list line to a store product and appends lines to the user's supermarket cart using the list's linked `storeId`. The list must have `storeId` set before adding to cart. Duplicate `productId` targets in one request are **merged by quantity** before insert.

**Path params**

- `shoppingList` (required, integer)

**Body**

Empty `{}`. The endpoint does not accept a `storeId`; it always uses the shopping list's linked store.

**201 Response**

Full **supermarket cart** payload (same as `GET /supermarket/cart` after lines are added):

```json
{
  "data": {
    "id": 5,
    "merchant": { "id": 20, "name": "Store name" },
    "items": [
      {
        "id": 99,
        "productId": 555,
        "name": "Labneh 250g",
        "quantity": 2,
        "unitPrice": 10.0,
        "totalPrice": 20.0
      }
    ],
    "amounts": { "subtotal": 20.0, "total": 20.0 }
  }
}
```

**422 — validation / business**

Typical `message` / `errors` shapes (Laravel validation):

- **`items`**: no rows had `isIncluded: true`, or every included row was skipped and nothing could be added (e.g. all excluded).
- **`storeId`**: the list has no `storeId` set, or no available `sm_products` row exists in that store for at least one included master product (message may mention the failing `master_product_id`).

**Errors**

- **`401`**, **`404`**

---

## Client flow (suggested)

1. **`GET /supermarket/shopping-lists`** — home screen: show saved lists.
2. **`GET /supermarket/shopping-lists/{id}`** — detail: lines, toggles, reorder UI.
3. **`POST`** / **`PATCH`** / **`DELETE`** on list or items — edit.
4. User picks a store → **`PATCH /supermarket/shopping-lists/{id}`** to set `storeId`.
5. **`POST …/add-to-cart`** with empty `{}` body to add to cart.
6. **`GET /supermarket/cart`** — confirm cart; then checkout per [`API_CONTRACT_USER_ORDERS_AND_CART.md`](./API_CONTRACT_USER_ORDERS_AND_CART.md).

---

## Scheduled auto-order flow (optional)

1. User sets `schedule` via **`POST`** or **`PATCH`** list endpoint.
2. Backend scheduler processes due schedules.
3. Backend creates a supermarket order from included list items when all required products are available.
4. User receives Arabic notification:
  - success: order created and sent.
  - failure: schedule run failed (for example unavailable mapped products).

---

## Notes for mobile / RTL apps

- Display names come from list item `name` (master catalog name at response time).
- `isIncluded` maps to a per-line “include when reordering” toggle without deleting the line.
