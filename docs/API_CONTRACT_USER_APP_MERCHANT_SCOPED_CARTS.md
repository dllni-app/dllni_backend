# User App API Contract — Merchant-Scoped Restaurant and Supermarket Carts

## Purpose

This contract replaces the legacy single mixed-merchant cart flow. The user can now have multiple carts, but every cart belongs to exactly one merchant:

- one restaurant cart per restaurant: `carts.user_id + carts.restaurant_id`
- one supermarket cart per store: `sm_carts.user_id + sm_carts.store_id`
- one checkout creates one order from one cart only
- no mixed restaurant order and no mixed supermarket order

## Flutter scan notes

Current `dllni-user-app` code still calls the old single-cart endpoints in `lib/features/orders/data/source/orders_remote_data_source.dart`:

- `GET /api/v1/user/restaurants/cart`
- `PATCH /api/v1/user/restaurants/cart/items/{itemId}`
- `DELETE /api/v1/user/restaurants/cart/items/{itemId}`
- `POST /api/v1/user/restaurants/orders`
- `GET /api/v1/user/supermarket/cart`
- `PATCH /api/v1/user/supermarket/cart/items/{itemId}`
- `DELETE /api/v1/user/supermarket/cart/items/{itemId}`
- `POST /api/v1/user/supermarket/orders`

The add-item endpoint in `lib/features/rs_discover/data/source/rs_discover_remote_data_source.dart` can stay as-is for restaurants because the backend infers the merchant from `productId`.

## Global headers

```http
Authorization: Bearer {userToken}
Accept: application/json
Content-Type: application/json
```

---

# 1. Restaurant cart flow

## 1.1 Add restaurant item

The user app does not send `cartId` when adding a product. The backend reads `product.restaurant_id`, resolves or creates that restaurant cart, then creates/updates the cart item.

```http
POST /api/v1/user/restaurants/cart/items
```

Request:

```json
{
  "productId": 40,
  "quantity": 2,
  "quantityMode": "set",
  "modifierIds": [],
  "substituteProductId": null,
  "specialInstructions": ""
}
```

Rules:

- `quantityMode = set` replaces the matching cart-line quantity.
- `quantityMode = increment` adds to the matching cart-line quantity.
- Same `productId + modifierIds + substituteProductId + normalized note` inside the same restaurant cart returns one line with updated quantity.
- Same product from another restaurant cannot enter this cart; it creates/updates another cart.

Response:

```json
{
  "message": "Item added to cart.",
  "cartId": 15,
  "merchantId": 3,
  "itemId": 101,
  "quantity": 2,
  "cartProductsCount": 2,
  "operation": "created",
  "data": {
    "id": 15,
    "merchant": {
      "id": 3,
      "name": "Pizza House",
      "primaryImageUrl": "https://example.com/storage/pizza-house.jpg",
      "bannerImageUrl": "https://example.com/storage/pizza-house-banner.jpg"
    },
    "items": [
      {
        "id": 101,
        "productId": 40,
        "name": "Chicken Pizza",
        "primaryImageUrl": "https://example.com/storage/pizza.jpg",
        "images": [],
        "quantity": 2,
        "unitPrice": 25000,
        "totalPrice": 50000,
        "modifierIds": [],
        "substituteProductId": null,
        "note": null
      }
    ],
    "productsCount": 2,
    "amounts": {
      "subtotal": 50000,
      "total": 50000
    }
  }
}
```

## 1.2 List restaurant carts

```http
GET /api/v1/user/restaurants/carts
```

Response:

```json
{
  "data": [
    {
      "id": 15,
      "merchant": {
        "id": 3,
        "name": "Pizza House",
        "primaryImageUrl": "https://example.com/storage/pizza-house.jpg",
        "bannerImageUrl": "https://example.com/storage/pizza-house-banner.jpg"
      },
      "items": [
        {
          "id": 101,
          "productId": 40,
          "name": "Chicken Pizza",
          "quantity": 2,
          "unitPrice": 25000,
          "totalPrice": 50000,
          "modifierIds": [],
          "substituteProductId": null,
          "note": null
        }
      ],
      "productsCount": 2,
      "amounts": {
        "subtotal": 50000,
        "total": 50000
      }
    },
    {
      "id": 16,
      "merchant": {
        "id": 8,
        "name": "Burger Town",
        "primaryImageUrl": null,
        "bannerImageUrl": null
      },
      "items": [
        {
          "id": 102,
          "productId": 77,
          "name": "Beef Burger",
          "quantity": 1,
          "unitPrice": 18000,
          "totalPrice": 18000,
          "modifierIds": [],
          "substituteProductId": null,
          "note": null
        }
      ],
      "productsCount": 1,
      "amounts": {
        "subtotal": 18000,
        "total": 18000
      }
    }
  ]
}
```

UI rule: show a cart card per restaurant. Remove `merchantGroups` UI for restaurant carts because one cart is already one restaurant.

## 1.3 Get one restaurant cart

```http
GET /api/v1/user/restaurants/carts/{cartId}
```

Response body is one cart object, not an array:

```json
{
  "data": {
    "id": 15,
    "merchant": {
      "id": 3,
      "name": "Pizza House"
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

## 1.4 Update restaurant cart item

```http
PATCH /api/v1/user/restaurants/carts/{cartId}/items/{itemId}
```

Request:

```json
{
  "quantity": 5,
  "modifierIds": [7, 12],
  "substituteProductId": null,
  "note": "No onions"
}
```

Response:

```json
{
  "data": {
    "id": 15,
    "merchant": {
      "id": 3,
      "name": "Pizza House"
    },
    "items": [
      {
        "id": 101,
        "productId": 40,
        "name": "Chicken Pizza",
        "quantity": 5,
        "unitPrice": 27000,
        "totalPrice": 135000,
        "modifierIds": [7, 12],
        "substituteProductId": null,
        "note": "No onions"
      }
    ],
    "productsCount": 5,
    "amounts": {
      "subtotal": 135000,
      "total": 135000
    }
  }
}
```

## 1.5 Delete restaurant cart item

```http
DELETE /api/v1/user/restaurants/carts/{cartId}/items/{itemId}
```

If the cart becomes empty, the backend deletes the cart and returns:

```json
{
  "data": {
    "id": null,
    "merchant": null,
    "items": [],
    "productsCount": 0,
    "amounts": {
      "subtotal": 0,
      "total": 0
    }
  }
}
```

## 1.6 Restaurant checkout preview

```http
POST /api/v1/user/restaurants/carts/{cartId}/checkout/preview
```

Request:

```json
{
  "fulfillmentType": "delivery",
  "receiveMode": "immediate",
  "scheduledAt": null,
  "addressId": 5,
  "couponCode": null,
  "note": "Call me when you arrive"
}
```

Response:

```json
{
  "data": {
    "cartId": 15,
    "merchant": {
      "id": 3,
      "name": "Pizza House"
    },
    "fulfillment": {
      "type": "delivery",
      "receiveMode": "immediate",
      "scheduledAt": null
    },
    "amounts": {
      "subtotal": 50000,
      "discount": 0,
      "serviceFee": 0,
      "tax": 0,
      "total": 50000
    },
    "note": "Call me when you arrive"
  }
}
```

## 1.7 Place restaurant order from cart

```http
POST /api/v1/user/restaurants/carts/{cartId}/orders
```

Request:

```json
{
  "fulfillmentType": "delivery",
  "receiveMode": "immediate",
  "scheduledAt": null,
  "addressId": 5,
  "couponCode": null,
  "note": "Call me when you arrive"
}
```

Response:

```json
{
  "data": {
    "id": 501,
    "userId": 22,
    "restaurantId": 3,
    "orderNumber": "ORD-X9K2D8LA-4921",
    "status": "pending",
    "statusLabelAr": "قيد الانتظار",
    "orderType": "delivery",
    "pickupMode": "immediate_pickup",
    "subtotal": 50000,
    "discountAmount": 0,
    "taxAmount": 0,
    "serviceFee": 0,
    "totalAmount": 50000,
    "specialInstructions": "Call me when you arrive",
    "restaurant": {
      "id": 3,
      "name": "Pizza House",
      "slug": "pizza-house"
    },
    "orderItems": [
      {
        "id": 9001,
        "orderId": 501,
        "productId": 40,
        "quantity": 2,
        "unitPrice": 25000,
        "totalPrice": 50000,
        "specialInstructions": null,
        "product": {
          "id": 40,
          "name": "Chicken Pizza"
        }
      }
    ],
    "createdAt": "2026-06-24 18:00:00",
    "updatedAt": "2026-06-24 18:00:00"
  }
}
```

After success, remove only this cart from local UI. Other restaurant and supermarket carts remain active.

---

# 2. Supermarket cart flow

## 2.1 Add supermarket item

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

Response:

```json
{
  "data": {
    "id": 22,
    "merchant": {
      "id": 7,
      "name": "Fresh Market"
    },
    "items": [
      {
        "id": 301,
        "productId": 501,
        "name": "Milk 1L",
        "quantity": 3,
        "unitPrice": 12000,
        "totalPrice": 36000
      }
    ],
    "productsCount": 3,
    "amounts": {
      "subtotal": 36000,
      "total": 36000
    }
  }
}
```

Same supermarket product in the same store cart updates one row with the new total quantity. A product from another store creates/updates another cart.

## 2.2 List supermarket carts

```http
GET /api/v1/user/supermarket/carts
```

Response:

```json
{
  "data": [
    {
      "id": 22,
      "merchant": {
        "id": 7,
        "name": "Fresh Market"
      },
      "items": [
        {
          "id": 301,
          "productId": 501,
          "name": "Milk 1L",
          "quantity": 3,
          "unitPrice": 12000,
          "totalPrice": 36000
        }
      ],
      "productsCount": 3,
      "amounts": {
        "subtotal": 36000,
        "total": 36000
      }
    }
  ]
}
```

## 2.3 Get one supermarket cart

```http
GET /api/v1/user/supermarket/carts/{cartId}
```

## 2.4 Update supermarket cart item

```http
PATCH /api/v1/user/supermarket/carts/{cartId}/items/{itemId}
```

Request:

```json
{
  "quantity": 6
}
```

Response is the full updated cart.

## 2.5 Delete supermarket cart item

```http
DELETE /api/v1/user/supermarket/carts/{cartId}/items/{itemId}
```

## 2.6 Supermarket checkout preview

```http
POST /api/v1/user/supermarket/carts/{cartId}/checkout/preview
```

Request:

```json
{
  "fulfillmentType": "delivery",
  "receiveMode": "scheduled",
  "scheduledAt": "2026-06-24 20:30:00",
  "addressId": 5,
  "couponCode": null,
  "note": "Call before arrival"
}
```

Response:

```json
{
  "data": {
    "cartId": 22,
    "merchant": {
      "id": 7,
      "name": "Fresh Market"
    },
    "fulfillment": {
      "type": "pickup",
      "receiveMode": "scheduled",
      "scheduledAt": "2026-06-24 20:30:00"
    },
    "amounts": {
      "subtotal": 36000,
      "discount": 0,
      "serviceFee": 0,
      "tax": 0,
      "total": 36000
    },
    "note": "Call before arrival"
  }
}
```

## 2.7 Place supermarket order from cart

```http
POST /api/v1/user/supermarket/carts/{cartId}/orders
```

Request:

```json
{
  "fulfillmentType": "delivery",
  "receiveMode": "scheduled",
  "scheduledAt": "2026-06-24 20:30:00",
  "addressId": 5,
  "couponCode": null,
  "note": "Call before arrival"
}
```

Response:

```json
{
  "data": {
    "id": 801,
    "customerId": 22,
    "storeId": 7,
    "orderNumber": "SM-XY91KJ22-4832",
    "status": "pending",
    "pickupMode": "scheduled_pickup",
    "pickupScheduledFor": "2026-06-24 20:30:00",
    "subtotal": "36000.00",
    "discountAmount": "0.00",
    "serviceFee": "0.00",
    "totalAmount": "36000.00",
    "specialInstructions": "Call before arrival",
    "items": [
      {
        "id": 9101,
        "productId": 501,
        "quantity": 3,
        "unitPrice": "12000.00",
        "totalPrice": "36000.00",
        "productName": "Milk 1L"
      }
    ],
    "createdAt": "2026-06-24 18:00:00",
    "updatedAt": "2026-06-24 18:00:00"
  }
}
```

---

# 3. Flutter UI migration checklist

## Replace old endpoints

| Old endpoint | New endpoint |
|---|---|
| `GET /api/v1/user/restaurants/cart` | `GET /api/v1/user/restaurants/carts` |
| `PATCH /api/v1/user/restaurants/cart/items/{itemId}` | `PATCH /api/v1/user/restaurants/carts/{cartId}/items/{itemId}` |
| `DELETE /api/v1/user/restaurants/cart/items/{itemId}` | `DELETE /api/v1/user/restaurants/carts/{cartId}/items/{itemId}` |
| `POST /api/v1/user/restaurants/checkout/preview` | `POST /api/v1/user/restaurants/carts/{cartId}/checkout/preview` |
| `POST /api/v1/user/restaurants/orders` | `POST /api/v1/user/restaurants/carts/{cartId}/orders` |
| `GET /api/v1/user/supermarket/cart` | `GET /api/v1/user/supermarket/carts` |
| `PATCH /api/v1/user/supermarket/cart/items/{itemId}` | `PATCH /api/v1/user/supermarket/carts/{cartId}/items/{itemId}` |
| `DELETE /api/v1/user/supermarket/cart/items/{itemId}` | `DELETE /api/v1/user/supermarket/carts/{cartId}/items/{itemId}` |
| `POST /api/v1/user/supermarket/checkout/preview` | `POST /api/v1/user/supermarket/carts/{cartId}/checkout/preview` |
| `POST /api/v1/user/supermarket/orders` | `POST /api/v1/user/supermarket/carts/{cartId}/orders` |

## UI flow

1. Cart entry screen should show multiple cart cards.
2. Each card has one merchant name, item count, and subtotal.
3. Checkout button belongs to one cart card only.
4. Product add flow stays product-based. Backend chooses the correct merchant cart.
5. Quantity update and delete must include both `cartId` and `itemId`.
6. After placing an order, remove only the checked-out cart from state.
7. Orders screen remains `GET /api/v1/user/orders?section=restaurant|supermarket`.
8. Reorder adds items back into merchant-scoped carts automatically.
