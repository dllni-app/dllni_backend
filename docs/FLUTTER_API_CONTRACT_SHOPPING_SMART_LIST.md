# Flutter API Contract - Shopping Smart List

## Scope
This contract covers **user supermarket smart shopping lists** (shopping-lists) in `Dllni_backend`.

Base path: `/api/v1/user/supermarket`

Auth: `Bearer <token>` required for all endpoints.

## Endpoints

### 1) Search master products (picker source)
- Method: `GET`
- Path: `/api/v1/user/supermarket/master-products/search`
- Query params:
  - `index` (required, string, non-empty, max 255)
  - `perPage` (optional, integer, 1..50, default backend pagination)
  - `page` (optional, integer >= 1)
- Success `200`:
```json
{
  "data": [
    {
      "id": 10,
      "name": "Tomato"
    }
  ],
  "links": {},
  "meta": {}
}
```

### 2) List smart lists (summary)
- Method: `GET`
- Path: `/api/v1/user/supermarket/shopping-lists`
- Success `200`:
```json
{
  "data": [
    {
      "id": 12,
      "name": "Weekly Vegetables",
      "description": "Main weekly list",
      "isActive": true,
      "schedule": {
        "frequencyType": "weekly",
        "weekDays": [1, 4],
        "monthDays": [],
        "periods": [
          { "label": "Morning", "fromTime": "09:00", "toTime": "10:00" }
        ],
        "isActive": true,
        "nextRunAt": "2026-05-23 09:00:00",
        "lastRunAt": null
      },
      "itemsCount": 5,
      "createdAt": "2026-05-20 12:00:00",
      "updatedAt": "2026-05-20 13:00:00"
    }
  ]
}
```

### 3) Create smart list
- Method: `POST`
- Path: `/api/v1/user/supermarket/shopping-lists`
- Body:
```json
{
  "name": "Weekly Vegetables",
  "description": "Main weekly list",
  "isActive": true,
  "schedule": {
    "isActive": true,
    "frequencyType": "weekly",
    "weekDays": [1, 4],
    "monthDays": [],
    "periods": [
      { "label": "Morning", "fromTime": "09:00", "toTime": "10:00" }
    ]
  }
}
```
- Notes:
  - `isActive` defaults to `true` if omitted.
  - `frequencyType` enum: `weekly | monthly | once`.
  - `weekDays` required when `frequencyType=weekly` (0..6).
  - `monthDays` required when `frequencyType=monthly` (1..31).
- Success `201`: returns full list detail (same shape as Show endpoint).

### 4) Show smart list (detail)
- Method: `GET`
- Path: `/api/v1/user/supermarket/shopping-lists/{shoppingList}`
- Path params:
  - `shoppingList` integer
- Success `200`:
```json
{
  "data": {
    "id": 12,
    "name": "Weekly Vegetables",
    "description": "Main weekly list",
    "isActive": true,
    "schedule": {
      "frequencyType": "weekly",
      "weekDays": [1, 4],
      "monthDays": [],
      "periods": [
        { "label": "Morning", "fromTime": "09:00", "toTime": "10:00" }
      ],
      "isActive": true,
      "nextRunAt": "2026-05-23 09:00:00",
      "lastRunAt": null
    },
    "items": [
      {
        "id": 70,
        "smartListId": 12,
        "masterProductId": 10,
        "masterProduct": {
          "id": 10,
          "name": "Tomato"
        },
        "quantity": 2,
        "unit": "kg",
        "sortOrder": 0,
        "isIncluded": true,
        "createdAt": "2026-05-20 12:10:00",
        "updatedAt": "2026-05-20 12:10:00"
      }
    ],
    "createdAt": "2026-05-20 12:00:00",
    "updatedAt": "2026-05-20 13:00:00"
  }
}
```

### 5) Update smart list
- Method: `PATCH`
- Path: `/api/v1/user/supermarket/shopping-lists/{shoppingList}`
- Body (all fields optional):
```json
{
  "name": "Weekly Essentials",
  "description": "Updated note",
  "isActive": true,
  "schedule": {
    "isActive": true,
    "frequencyType": "monthly",
    "weekDays": [],
    "monthDays": [1, 15],
    "periods": [
      { "label": "Evening", "fromTime": "18:00", "toTime": "19:00" }
    ]
  }
}
```
- Success `200`: returns full list detail.

### 6) Delete smart list
- Method: `DELETE`
- Path: `/api/v1/user/supermarket/shopping-lists/{shoppingList}`
- Success `204` no content.

### 7) Add item to smart list
- Method: `POST`
- Path: `/api/v1/user/supermarket/shopping-lists/{shoppingList}/items`
- Body:
```json
{
  "masterProductId": 10,
  "quantity": 2,
  "unit": "kg",
  "sortOrder": 0,
  "isIncluded": true
}
```
- Notes:
  - `masterProductId` required and must exist.
  - `quantity` required (`0.01 .. 9999`).
  - `sortOrder` defaults to `0`.
  - `isIncluded` defaults to `true`.
- Success `201`: returns full list detail.

### 8) Update item
- Method: `PATCH`
- Path: `/api/v1/user/supermarket/shopping-lists/{shoppingList}/items/{item}`
- Body (any provided field is updated):
```json
{
  "quantity": 3,
  "sortOrder": 1,
  "isIncluded": false
}
```
- Success `200`: returns full list detail.

### 9) Delete item
- Method: `DELETE`
- Path: `/api/v1/user/supermarket/shopping-lists/{shoppingList}/items/{item}`
- Success `204` no content.

### 10) Add smart list to cart
- Method: `POST`
- Path: `/api/v1/user/supermarket/shopping-lists/{shoppingList}/add-to-cart`
- Body: `{}`
- Success `201`:
```json
{
  "data": {
    "id": 33,
    "merchant": { "id": 2, "name": "Fresh Market" },
    "items": [
      {
        "id": 100,
        "productId": 400,
        "name": "Tomato 1kg",
        "quantity": 2,
        "unitPrice": 1.5,
        "totalPrice": 3
      }
    ],
    "merchantGroups": [
      {
        "merchant": { "id": 2, "name": "Fresh Market" },
        "items": [
          {
            "id": 100,
            "productId": 400,
            "name": "Tomato 1kg",
            "quantity": 2,
            "unitPrice": 1.5,
            "totalPrice": 3
          }
        ],
        "amounts": { "subtotal": 3, "total": 3 }
      }
    ],
    "amounts": { "subtotal": 3, "total": 3 }
  }
}
```

## Error Contract

### Standard validation error (`422`)
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "fieldName": ["Validation message"]
  }
}
```

### Business errors from smart-list add-to-cart (`422`)
- `errors.items[0] = "There are no included items to add to the cart."`
- `errors.items[0] = "No available product found for master product id {id}."`
- `errors.items[0] = "The included items do not share a common store."`
- `errors.items[0] = "No available product found in the selected store for master product id {id}."`

### Not found / ownership (`404`)
If list/item does not exist or does not belong to authenticated user, backend returns `404`.

## Flutter Integration Notes
- Keep DTO keys in **camelCase** exactly as returned (`isActive`, `itemsCount`, `sortOrder`, `masterProductId`, `nextRunAt`).
- Create/update item and list endpoints return full list detail: use response to refresh local state directly.
- For add-to-cart, treat `merchantGroups` as the primary structure; `merchant` + `items` are legacy-compatible aggregate fields.
- If schedule UI is optional, send `schedule: null` or omit `schedule`.
