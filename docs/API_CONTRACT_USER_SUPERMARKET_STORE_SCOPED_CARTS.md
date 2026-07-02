# User Supermarket Store-Scoped Carts API Contract

## Rule

A supermarket cart belongs to exactly one supermarket store.

- One user can have multiple supermarket carts.
- Each cart has one `storeId` / `merchantId`.
- Adding a product from the same supermarket updates that supermarket cart.
- Adding a product from another supermarket creates or updates another cart.
- A cart response must not include legacy multi-merchant fields such as `merchantGroups`, `isMultiMerchant`, or `checkout`.

## Endpoints

### List carts

```http
GET /api/v1/user/supermarket/carts
```

Returns only a flat `data` list of the authenticated user's supermarket cart payloads. Each returned cart includes its own `store` data and `items` data.

```json
{
  "data": [
    {
      "id": 22,
      "storeId": 7,
      "merchantId": 7,
      "merchant": {
        "id": 7,
        "name": "Fresh Market"
      },
      "store": {
        "id": 7,
        "name": "Fresh Market"
      },
      "items": [],
      "productsCount": 0,
      "amounts": {
        "subtotal": 0,
        "total": 0
      }
    }
  ]
}
```

### Show cart by ID

```http
GET /api/v1/user/supermarket/carts/{cartId}
```

Returns one cart owned by the authenticated user.

### Delete cart

```http
DELETE /api/v1/user/supermarket/carts/{cartId}
```

Deletes the cart and all its items.

Response:

```json
{
  "data": {
    "id": null,
    "storeId": 7,
    "merchantId": 7,
    "merchant": {
      "id": 7,
      "name": "Fresh Market"
    },
    "store": {
      "id": 7,
      "name": "Fresh Market"
    },
    "items": [],
    "productsCount": 0,
    "amounts": {
      "subtotal": 0,
      "total": 0
    }
  }
}
```

### Add item to cart

```http
POST /api/v1/user/supermarket/cart/items
```

Request:

```json
{
  "productId": 501,
  "quantity": 3
}
```

The backend resolves `storeId` from `productId` and creates or updates the cart for that store.

### Update cart item

```http
PATCH /api/v1/user/supermarket/carts/{cartId}/items/{itemId}
```

Request:

```json
{
  "quantity": 5
}
```

Returns the full updated cart.

### Remove item from cart

```http
DELETE /api/v1/user/supermarket/carts/{cartId}/items/{itemId}
```

If this removes the last item, the backend deletes the cart and returns an empty cart payload.

## Supermarket details

```http
GET /api/v1/user/supermarket/stores/{storeId}
```

When the user is authenticated, the response includes cart data for this supermarket both at top level and inside `store.cart`:

```json
{
  "store": {
    "id": 7,
    "name": "Fresh Market",
    "cart": {
      "id": 22,
      "storeId": 7,
      "items": [],
      "productsCount": 0,
      "amounts": {
        "subtotal": 0,
        "total": 0
      }
    }
  },
  "cart": {
    "id": 22,
    "storeId": 7,
    "items": [],
    "productsCount": 0,
    "amounts": {
      "subtotal": 0,
      "total": 0
    }
  }
}
```
