# API Contract — User Orders & Cart (Restaurant + Supermarket)

Base path: `/api/v1/user`  
Auth: **required** for all endpoints in this document (`auth:sanctum`)

---

## Common concepts

### Sections
- `restaurant`
- `supermarket`
- `all` (orders list only)

### Merchant
In responses, `merchant` always represents:
- **Restaurant section**: the restaurant
- **Supermarket section**: the store

---

## Coupons

### Check coupon availability (shared)
`POST /coupons/check`

Checks if the given `couponCode` is applicable to the **current cart** (and returns the computed discount/total).

**Body**
- `section` (required, string): `restaurants|supermarket`
- `couponCode` (required, string, max: `50`)

**200 Response**

```json
{
  "data": {
    "section": "restaurants",
    "couponCode": "SAVE10",
    "isAvailable": true,
    "reason": "ok",
    "amounts": { "subtotal": 100.0, "discount": 10.0, "total": 90.0 },
    "coupon": {
      "type": "percentage",
      "value": 10.0,
      "percent": null,
      "minOrderAmount": null,
      "maxDiscountAmount": null
    }
  }
}
```

If cart is empty, returns `422` with `cart` error.

---

## Orders

### List orders
`GET /orders`

**Query params**
- `section` (optional, string): `all|restaurant|supermarket` (default: `all`)
- `status` (optional, string)
- `search` (optional, string): searches by order number (partial match)
- `restaurantId` (optional, integer): filters **restaurant** orders by `restaurant_id` (applies when `section=restaurant` or `section=all`)
- `perPage` (optional, integer, 1..100, default: `20`)
- `page` (optional, integer, min: `1`, default: `1`)

**200 Response**

```json
{
  "data": [
    {
      "id": 123,
      "section": "restaurant",
      "orderNumber": "R-ABC-1234",
      "status": "pending",
      "statusLabel": "Pending",
      "merchant": { "id": 10, "name": "Merchant name" },
      "fulfillment": {
        "type": "delivery",
        "receiveMode": "immediate",
        "scheduledAt": null
      },
      "amounts": {
        "subtotal": 100.0,
        "discount": 0.0,
        "serviceFee": 0.0,
        "tax": 0.0,
        "total": 100.0
      },
      "items": [
        {
          "id": 1,
          "productId": 999,
          "name": "Product name",
          "quantity": 2,
          "unitPrice": 50.0,
          "totalPrice": 100.0,
          "note": null
        }
      ],
      "timeline": [
        {
          "fromStatus": null,
          "toStatus": "pending",
          "note": null,
          "changedAt": "2026-04-09 12:00:00"
        }
      ],
      "actions": {
        "canCancel": true,
        "canReorder": false,
        "canReschedule": true
      },
      "createdAt": "2026-04-09T12:00:00Z",
      "updatedAt": "2026-04-09T12:00:00Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 20,
    "total": 1
  },
  "links": {
    "first": "…",
    "last": "…",
    "prev": null,
    "next": null
  }
}
```

---

### Show order
`GET /orders/{section}/{orderId}`

**Path params**
- `section` (required): `restaurant|supermarket`
- `orderId` (required): integer

**200 Response**

```json
{
  "data": {
    "id": 123,
    "section": "restaurant",
    "orderNumber": "R-ABC-1234",
    "status": "pending",
    "statusLabel": "Pending",
    "merchant": { "id": 10, "name": "Merchant name" },
    "fulfillment": {
      "type": "delivery",
      "receiveMode": "immediate",
      "scheduledAt": null
    },
    "amounts": {
      "subtotal": 100.0,
      "discount": 0.0,
      "serviceFee": 0.0,
      "tax": 0.0,
      "total": 100.0
    },
    "items": [
      {
        "id": 1,
        "productId": 999,
        "name": "Product name",
        "quantity": 2,
        "unitPrice": 50.0,
        "totalPrice": 100.0,
        "note": null
      }
    ],
    "timeline": [
      {
        "fromStatus": null,
        "toStatus": "pending",
        "note": null,
        "changedAt": "2026-04-09 12:00:00"
      }
    ],
    "actions": {
      "canCancel": true,
      "canReorder": false,
      "canReschedule": true
    },
    "createdAt": "2026-04-09T12:00:00Z",
    "updatedAt": "2026-04-09T12:00:00Z"
  }
}
```

---

### Order tracking
`GET /orders/{section}/{orderId}/tracking`

**200 Response**

```json
{
  "data": {
    "eta": { "minutes": 25, "text": "25 min" },
    "map": { "enabled": false, "lat": null, "lng": null },
    "timeline": [],
    "merchant": { "id": 10, "name": "Merchant name" },
    "actions": {
      "canCancel": true,
      "canReorder": false,
      "canReschedule": true
    }
  }
}
```

---

### Cancel order
`POST /orders/{section}/{orderId}/cancel`

**Body**
- `reason` (optional, string, max 500)

**200 Response**
- Returns the same payload as “Show order” (after cancellation if applicable):

```json
{
  "data": {
    "id": 123,
    "section": "restaurant",
    "orderNumber": "R-ABC-1234",
    "status": "cancelled",
    "statusLabel": "Cancelled",
    "merchant": { "id": 10, "name": "Merchant name" },
    "fulfillment": {
      "type": "delivery",
      "receiveMode": "immediate",
      "scheduledAt": null
    },
    "amounts": {
      "subtotal": 100.0,
      "discount": 0.0,
      "serviceFee": 0.0,
      "tax": 0.0,
      "total": 100.0
    },
    "items": [
      {
        "id": 1,
        "productId": 999,
        "name": "Product name",
        "quantity": 2,
        "unitPrice": 50.0,
        "totalPrice": 100.0,
        "note": null
      }
    ],
    "timeline": [
      {
        "fromStatus": "pending",
        "toStatus": "cancelled",
        "note": "Changed by customer.",
        "changedAt": "2026-04-09 12:05:00"
      }
    ],
    "actions": {
      "canCancel": false,
      "canReorder": true,
      "canReschedule": false
    },
    "createdAt": "2026-04-09T12:00:00Z",
    "updatedAt": "2026-04-09T12:05:00Z"
  }
}
```

---

### Reorder (add order items back to cart)
`POST /orders/{section}/{orderId}/reorder`

**201 Response**

```json
{
  "data": { "itemsAdded": 3 }
}
```

---

### Schedule order (set scheduled pickup)
`PATCH /orders/{section}/{orderId}/schedule`

**Body**
- `scheduledAt` (required, date, must be after now)

**200 Response**
- Returns the same payload as “Show order” (after update):

```json
{
  "data": {
    "id": 123,
    "section": "restaurant",
    "orderNumber": "R-ABC-1234",
    "status": "pending",
    "statusLabel": "Pending",
    "merchant": { "id": 10, "name": "Merchant name" },
    "fulfillment": {
      "type": "delivery",
      "receiveMode": "scheduled_pickup",
      "scheduledAt": "2026-04-09 18:00:00"
    },
    "amounts": {
      "subtotal": 100.0,
      "discount": 0.0,
      "serviceFee": 0.0,
      "tax": 0.0,
      "total": 100.0
    },
    "items": [
      {
        "id": 1,
        "productId": 999,
        "name": "Product name",
        "quantity": 2,
        "unitPrice": 50.0,
        "totalPrice": 100.0,
        "note": null
      }
    ],
    "timeline": [],
    "actions": {
      "canCancel": true,
      "canReorder": false,
      "canReschedule": true
    },
    "createdAt": "2026-04-09T12:00:00Z",
    "updatedAt": "2026-04-09T12:10:00Z"
  }
}
```

---

### Available slots (schedule helper)
`GET /orders/slots`

**Query params**
- `section` (required, string): `restaurant|supermarket`
- `merchantId` (required, integer, min 1)
- `fulfillmentType` (optional, string|null)
- `date` (required, `Y-m-d`)

**200 Response**

```json
{
  "data": {
    "slots": [
      {
        "id": "uuid",
        "section": "restaurant",
        "merchantId": 10,
        "fulfillmentType": "pickup",
        "startAt": "2026-04-09 09:00:00",
        "endAt": "2026-04-09 10:00:00",
        "available": true
      }
    ]
  }
}
```

---

## Restaurant cart

### Show cart
`GET /restaurants/cart`

**200 Response**

```json
{
  "data": {
    "id": 1,
    "merchant": { "id": 10, "name": "Restaurant name" },
    "items": [
      {
        "id": 5,
        "productId": 999,
        "name": "Product name",
        "quantity": 2,
        "unitPrice": 52.0,
        "totalPrice": 104.0,
        "modifierIds": [1, 2],
        "substituteProductId": null,
        "note": "No onions"
      }
    ],
    "amounts": { "subtotal": 104.0, "total": 104.0 }
  }
}
```

If cart is empty:

```json
{
  "data": {
    "id": null,
    "merchant": null,
    "items": [],
    "amounts": { "subtotal": 0.0, "total": 0.0 }
  }
}
```

---

### Add cart item
`POST /restaurants/cart/items`

**Body**
- `productId` (required, integer)
- `quantity` (required, integer, 1..50)
- `modifierIds` (optional, array<int>, max 30)
- `substituteProductId` (optional, integer|null)
- `note` (optional, string|null, max 1000)

**201 Response**
- Returns the full cart payload (same as “Show cart”).

---

### Update cart item
`PATCH /restaurants/cart/items/{itemId}`

**Body**
- `quantity` (required, integer, 1..50)
- `modifierIds` (optional, array<int>, max 30)
- `substituteProductId` (optional, integer|null)
- `note` (optional, string|null, max 1000)

**200 Response**
- Returns the full cart payload (same as “Show cart”).

---

### Delete cart item
`DELETE /restaurants/cart/items/{itemId}`

**200 Response**
- Returns the full cart payload (same as “Show cart”).

---

### Restaurant cart products count
`GET /restaurants/cart/products-count`

**200 Response**

```json
{
  "productsCount": 3
}
```

---

### Restaurant checkout preview
`POST /restaurants/checkout/preview`

**Body**
- `fulfillmentType` (required): `delivery|pickup|dine_in`
- `receiveMode` (required): `immediate|scheduled`
- `scheduledAt` (optional, date, after now)
- `addressId` (optional, integer|null)
- `couponCode` (optional, string|null)
- `note` (optional, string|null)

**200 Response**

```json
{
  "data": {
    "fulfillment": { "type": "pickup", "receiveMode": "immediate", "scheduledAt": null },
    "amounts": { "subtotal": 100.0, "discount": 0.0, "serviceFee": 0.0, "tax": 0.0, "total": 100.0 },
    "items": [
      {
        "id": 5,
        "productId": 999,
        "name": "Product name",
        "quantity": 2,
        "unitPrice": 52.0,
        "totalPrice": 104.0,
        "note": "No onions"
      }
    ],
    "note": "Leave at the door"
  }
}
```

---

### Place restaurant order (from cart)
`POST /restaurants/orders`

**Body**
- `fulfillmentType` (required): `delivery|pickup|dine_in`
- `receiveMode` (required): `immediate|scheduled`
- `scheduledAt` (optional, date, after now)
- `addressId` (optional, integer|null)
- `couponCode` (optional, string|null)
- `note` (optional, string|null)

**201 Response**
- Returns the “Show order” payload.

---

## Supermarket cart

### Show cart
`GET /supermarket/cart`

**200 Response**

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

If cart is empty:

```json
{
  "data": {
    "id": null,
    "merchant": null,
    "items": [],
    "amounts": { "subtotal": 0.0, "total": 0.0 }
  }
}
```

---

### Add cart item
`POST /supermarket/cart/items`

**Body**
- `productId` (required, integer)
- `quantity` (required, integer, 1..200)

**201 Response**
- Returns the full cart payload (same as “Show cart”).

---

### Update cart item
`PATCH /supermarket/cart/items/{itemId}`

**Body**
- `quantity` (required, integer, 1..200)

**200 Response**
- Returns the full cart payload (same as “Show cart”).

---

### Delete cart item
`DELETE /supermarket/cart/items/{itemId}`

**200 Response**
- Returns the full cart payload (same as “Show cart”).

---

### Supermarket checkout preview
`POST /supermarket/checkout/preview`

**Body**
- `fulfillmentType` (required): `pickup`
- `receiveMode` (required): `immediate|scheduled`
- `scheduledAt` (optional, date, after now)
- `addressId` (optional, integer|null)
- `couponCode` (optional, string|null)
- `note` (optional, string|null)

**200 Response**

```json
{
  "data": {
    "fulfillment": { "type": "pickup", "receiveMode": "immediate", "scheduledAt": null },
    "amounts": { "subtotal": 100.0, "discount": 0.0, "serviceFee": 0.0, "tax": 0.0, "total": 100.0 },
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
    "note": "Leave at the door"
  }
}
```

---

### Place supermarket order (from cart)
`POST /supermarket/orders`

**Body**
- `fulfillmentType` (required): `pickup`
- `receiveMode` (required): `immediate|scheduled`
- `scheduledAt` (optional, date, after now)
- `addressId` (optional, integer|null)
- `couponCode` (optional, string|null)
- `note` (optional, string|null)

**201 Response**
- Returns the “Show order” payload with `section=supermarket`.

