# Flutter API Contract: Restaurant Group Ordering (User App)

## Document Scope
This file is a Flutter-focused API contract for restaurant group ordering under the User module.
It is aligned to current backend implementation and controller messages.

## Base Configuration
- Base URL: `https://dllni.mustafafares.com/api/v1/user`
- Auth: `Authorization: Bearer <sanctum_token>`
- Content-Type: `application/json`

## Implementation Truth (Important)
1. Create API expects `durationMinutes` (not `endsAt`).
2. Allowed `durationMinutes`: `15, 30, 45, 60, 90, 120`.
3. Join API requires `shareToken` length exactly 32.
4. Most write actions return full `publicPayload` in `data`.
5. Group-order detail access is organizer-or-participant only.

---

## Canonical Payload (`publicPayload`)

```json
{
  "groupOrder": {
    "id": 901,
    "name": "Lunch Team Order",
    "status": "active",
    "restaurantId": 42,
    "restaurantName": "Green Bowl",
    "shareToken": "xk4j5n2b8v1q9z0p3s6d7f1h2j4k6m8n",
    "shareUrl": "https://dllni.mustafafares.com/group-order/xk4j5n2b8v1q9z0p3s6d7f1h2j4k6m8n",
    "deliveryFeeStrategy": "organizer_pays",
    "endsAt": "2026-05-13T17:00:00Z",
    "secondsRemaining": 2500,
    "creatorUserId": 17,
    "isCreator": false,
    "placedOrderId": null,
    "placedAt": null,
    "createdAt": "2026-05-13T16:00:00Z"
  },
  "participants": [
    {
      "participantId": 334,
      "userId": 17,
      "name": "Mustafa",
      "status": "joined",
      "hasResponded": false,
      "submittedAt": null,
      "subtotal": 26,
      "itemsCount": 2,
      "items": [
        {
          "id": 10045,
          "productId": 300,
          "name": "Chicken Salad",
          "quantity": 1,
          "unitPrice": 14,
          "totalPrice": 14,
          "modifierIds": [9011],
          "note": "No onions"
        },
        {
          "id": 10046,
          "productId": 301,
          "name": "Lemon Juice",
          "quantity": 2,
          "unitPrice": 6,
          "totalPrice": 12,
          "modifierIds": [],
          "note": null
        }
      ]
    },
    {
      "participantId": 335,
      "userId": 22,
      "name": "Sara",
      "status": "submitted",
      "hasResponded": true,
      "submittedAt": "2026-05-13T16:34:11Z",
      "subtotal": 18,
      "itemsCount": 1,
      "items": [
        {
          "id": 10047,
          "productId": 302,
          "name": "Turkey Wrap",
          "quantity": 1,
          "unitPrice": 18,
          "totalPrice": 18,
          "modifierIds": [9015, 9017],
          "note": "Sauce on side"
        }
      ]
    }
  ],
  "counts": {
    "participants": 2,
    "responded": 1,
    "pending": 1,
    "items": 3
  },
  "amounts": {
    "subtotal": 44,
    "deliveryFee": 0,
    "total": 44
  }
}
```

---

## Endpoint Contracts

## 1) Create Group Order
**POST** `/restaurants/group-orders`

### Request
```json
{
  "restaurantId": 42,
  "name": "Lunch Team Order",
  "durationMinutes": 60
}
```

### Success Response (201)
```json
{
  "message": "Group order created.",
  "data": {
    "groupOrder": {
      "id": 901,
      "name": "Lunch Team Order",
      "status": "active",
      "restaurantId": 42,
      "restaurantName": "Green Bowl",
      "shareToken": "xk4j5n2b8v1q9z0p3s6d7f1h2j4k6m8n",
      "shareUrl": "https://dllni.mustafafares.com/group-order/xk4j5n2b8v1q9z0p3s6d7f1h2j4k6m8n",
      "deliveryFeeStrategy": "organizer_pays",
      "endsAt": "2026-05-13T17:00:00Z",
      "secondsRemaining": 3600,
      "creatorUserId": 17,
      "isCreator": true,
      "placedOrderId": null,
      "placedAt": null,
      "createdAt": "2026-05-13T16:00:00Z"
    },
    "participants": [
      {
        "participantId": 334,
        "userId": 17,
        "name": "Mustafa",
        "status": "joined",
        "hasResponded": false,
        "submittedAt": null,
        "subtotal": 0,
        "itemsCount": 0,
        "items": []
      }
    ],
    "counts": {
      "participants": 1,
      "responded": 0,
      "pending": 1,
      "items": 0
    },
    "amounts": {
      "subtotal": 0,
      "deliveryFee": 0,
      "total": 0
    }
  }
}
```

---

## 2) Join Group Order
**POST** `/restaurants/group-orders/join`

### Request
```json
{
  "shareToken": "xk4j5n2b8v1q9z0p3s6d7f1h2j4k6m8n"
}
```

### Success Response (200)
```json
{
  "message": "Joined group order.",
  "data": {
    "groupOrder": {
      "id": 901,
      "name": "Lunch Team Order",
      "status": "active",
      "restaurantId": 42,
      "restaurantName": "Green Bowl",
      "shareToken": "xk4j5n2b8v1q9z0p3s6d7f1h2j4k6m8n",
      "shareUrl": "https://dllni.mustafafares.com/group-order/xk4j5n2b8v1q9z0p3s6d7f1h2j4k6m8n",
      "deliveryFeeStrategy": "organizer_pays",
      "endsAt": "2026-05-13T17:00:00Z",
      "secondsRemaining": 3400,
      "creatorUserId": 17,
      "isCreator": false,
      "placedOrderId": null,
      "placedAt": null,
      "createdAt": "2026-05-13T16:00:00Z"
    },
    "participants": [
      {
        "participantId": 334,
        "userId": 17,
        "name": "Mustafa",
        "status": "joined",
        "hasResponded": false,
        "submittedAt": null,
        "subtotal": 0,
        "itemsCount": 0,
        "items": []
      },
      {
        "participantId": 335,
        "userId": 22,
        "name": "Sara",
        "status": "joined",
        "hasResponded": false,
        "submittedAt": null,
        "subtotal": 0,
        "itemsCount": 0,
        "items": []
      }
    ],
    "counts": {
      "participants": 2,
      "responded": 0,
      "pending": 2,
      "items": 0
    },
    "amounts": {
      "subtotal": 0,
      "deliveryFee": 0,
      "total": 0
    }
  }
}
```

---

## 3) List Active Group Orders
**GET** `/restaurants/group-orders/active`

### Request
No body.

### Success Response (200)
```json
{
  "data": [
    {
      "groupOrder": {
        "id": 901,
        "name": "Lunch Team Order",
        "status": "active",
        "restaurantId": 42,
        "restaurantName": "Green Bowl",
        "shareToken": "xk4j5n2b8v1q9z0p3s6d7f1h2j4k6m8n",
        "shareUrl": "https://dllni.mustafafares.com/group-order/xk4j5n2b8v1q9z0p3s6d7f1h2j4k6m8n",
        "deliveryFeeStrategy": "organizer_pays",
        "endsAt": "2026-05-13T17:00:00Z",
        "secondsRemaining": 3200,
        "creatorUserId": 17,
        "isCreator": false,
        "placedOrderId": null,
        "placedAt": null,
        "createdAt": "2026-05-13T16:00:00Z"
      },
      "participants": [
        {
          "participantId": 334,
          "userId": 17,
          "name": "Mustafa",
          "status": "joined",
          "hasResponded": false,
          "submittedAt": null,
          "subtotal": 26,
          "itemsCount": 2,
          "items": [
            {
              "id": 10045,
              "productId": 300,
              "name": "Chicken Salad",
              "quantity": 1,
              "unitPrice": 14,
              "totalPrice": 14,
              "modifierIds": [9011],
              "note": "No onions"
            },
            {
              "id": 10046,
              "productId": 301,
              "name": "Lemon Juice",
              "quantity": 2,
              "unitPrice": 6,
              "totalPrice": 12,
              "modifierIds": [],
              "note": null
            }
          ]
        },
        {
          "participantId": 335,
          "userId": 22,
          "name": "Sara",
          "status": "submitted",
          "hasResponded": true,
          "submittedAt": "2026-05-13T16:34:11Z",
          "subtotal": 18,
          "itemsCount": 1,
          "items": [
            {
              "id": 10047,
              "productId": 302,
              "name": "Turkey Wrap",
              "quantity": 1,
              "unitPrice": 18,
              "totalPrice": 18,
              "modifierIds": [9015, 9017],
              "note": "Sauce on side"
            }
          ]
        }
      ],
      "counts": {
        "participants": 2,
        "responded": 1,
        "pending": 1,
        "items": 3
      },
      "amounts": {
        "subtotal": 44,
        "deliveryFee": 0,
        "total": 44
      }
    }
  ]
}
```

---

## 4) Show Group Order Details (Source of Truth)
**GET** `/restaurants/group-orders/{groupOrderId}`

### Request
No body.

### Success Response (200)
```json
{
  "data": {
    "groupOrder": {
      "id": 901,
      "name": "Lunch Team Order",
      "status": "active",
      "restaurantId": 42,
      "restaurantName": "Green Bowl",
      "shareToken": "xk4j5n2b8v1q9z0p3s6d7f1h2j4k6m8n",
      "shareUrl": "https://dllni.mustafafares.com/group-order/xk4j5n2b8v1q9z0p3s6d7f1h2j4k6m8n",
      "deliveryFeeStrategy": "organizer_pays",
      "endsAt": "2026-05-13T17:00:00Z",
      "secondsRemaining": 2500,
      "creatorUserId": 17,
      "isCreator": false,
      "placedOrderId": null,
      "placedAt": null,
      "createdAt": "2026-05-13T16:00:00Z"
    },
    "participants": [
      {
        "participantId": 334,
        "userId": 17,
        "name": "Mustafa",
        "status": "joined",
        "hasResponded": false,
        "submittedAt": null,
        "subtotal": 26,
        "itemsCount": 2,
        "items": [
          {
            "id": 10045,
            "productId": 300,
            "name": "Chicken Salad",
            "quantity": 1,
            "unitPrice": 14,
            "totalPrice": 14,
            "modifierIds": [9011],
            "note": "No onions"
          },
          {
            "id": 10046,
            "productId": 301,
            "name": "Lemon Juice",
            "quantity": 2,
            "unitPrice": 6,
            "totalPrice": 12,
            "modifierIds": [],
            "note": null
          }
        ]
      },
      {
        "participantId": 335,
        "userId": 22,
        "name": "Sara",
        "status": "submitted",
        "hasResponded": true,
        "submittedAt": "2026-05-13T16:34:11Z",
        "subtotal": 18,
        "itemsCount": 1,
        "items": [
          {
            "id": 10047,
            "productId": 302,
            "name": "Turkey Wrap",
            "quantity": 1,
            "unitPrice": 18,
            "totalPrice": 18,
            "modifierIds": [9015, 9017],
            "note": "Sauce on side"
          }
        ]
      }
    ],
    "counts": {
      "participants": 2,
      "responded": 1,
      "pending": 1,
      "items": 3
    },
    "amounts": {
      "subtotal": 44,
      "deliveryFee": 0,
      "total": 44
    }
  }
}
```

---

## 5) Add Item
**POST** `/restaurants/group-orders/{groupOrderId}/items`

### Request
```json
{
  "productId": 300,
  "quantity": 1,
  "modifierIds": [9011, 9012],
  "substituteProductId": null,
  "specialInstructions": "No spicy sauce"
}
```

### Success Response (201)
```json
{
  "message": "Item added to group order.",
  "data": {
    "groupOrder": {
      "id": 901,
      "name": "Lunch Team Order",
      "status": "active",
      "restaurantId": 42,
      "restaurantName": "Green Bowl",
      "shareToken": "xk4j5n2b8v1q9z0p3s6d7f1h2j4k6m8n",
      "shareUrl": "https://dllni.mustafafares.com/group-order/xk4j5n2b8v1q9z0p3s6d7f1h2j4k6m8n",
      "deliveryFeeStrategy": "organizer_pays",
      "endsAt": "2026-05-13T17:00:00Z",
      "secondsRemaining": 2400,
      "creatorUserId": 17,
      "isCreator": false,
      "placedOrderId": null,
      "placedAt": null,
      "createdAt": "2026-05-13T16:00:00Z"
    },
    "participants": [
      {
        "participantId": 335,
        "userId": 22,
        "name": "Sara",
        "status": "joined",
        "hasResponded": false,
        "submittedAt": null,
        "subtotal": 28,
        "itemsCount": 2,
        "items": [
          {
            "id": 10047,
            "productId": 302,
            "name": "Turkey Wrap",
            "quantity": 1,
            "unitPrice": 18,
            "totalPrice": 18,
            "modifierIds": [9015, 9017],
            "note": "Sauce on side"
          },
          {
            "id": 10048,
            "productId": 300,
            "name": "Chicken Salad",
            "quantity": 1,
            "unitPrice": 10,
            "totalPrice": 10,
            "modifierIds": [9011, 9012],
            "note": "No spicy sauce"
          }
        ]
      }
    ],
    "counts": {
      "participants": 2,
      "responded": 1,
      "pending": 1,
      "items": 4
    },
    "amounts": {
      "subtotal": 54,
      "deliveryFee": 0,
      "total": 54
    }
  }
}
```

---

## 6) Update Item
**PATCH** `/restaurants/group-orders/{groupOrderId}/items/{itemId}`

### Request
```json
{
  "quantity": 1,
  "modifierIds": [9011],
  "note": "Sauce on side"
}
```

### Success Response (200)
```json
{
  "message": "Group order item updated.",
  "data": {
    "groupOrder": {
      "id": 901,
      "name": "Lunch Team Order",
      "status": "active",
      "restaurantId": 42,
      "restaurantName": "Green Bowl",
      "shareToken": "xk4j5n2b8v1q9z0p3s6d7f1h2j4k6m8n",
      "shareUrl": "https://dllni.mustafafares.com/group-order/xk4j5n2b8v1q9z0p3s6d7f1h2j4k6m8n",
      "deliveryFeeStrategy": "organizer_pays",
      "endsAt": "2026-05-13T17:00:00Z",
      "secondsRemaining": 2250,
      "creatorUserId": 17,
      "isCreator": false,
      "placedOrderId": null,
      "placedAt": null,
      "createdAt": "2026-05-13T16:00:00Z"
    },
    "participants": [
      {
        "participantId": 335,
        "userId": 22,
        "name": "Sara",
        "status": "joined",
        "hasResponded": false,
        "submittedAt": null,
        "subtotal": 24,
        "itemsCount": 1,
        "items": [
          {
            "id": 10047,
            "productId": 302,
            "name": "Turkey Wrap",
            "quantity": 1,
            "unitPrice": 24,
            "totalPrice": 24,
            "modifierIds": [9011],
            "note": "Sauce on side"
          }
        ]
      }
    ],
    "counts": {
      "participants": 2,
      "responded": 1,
      "pending": 1,
      "items": 3
    },
    "amounts": {
      "subtotal": 50,
      "deliveryFee": 0,
      "total": 50
    }
  }
}
```

---

## 7) Delete Item
**DELETE** `/restaurants/group-orders/{groupOrderId}/items/{itemId}`

### Request
No body.

### Success Response (200)
```json
{
  "message": "Group order item deleted.",
  "data": {
    "groupOrder": {
      "id": 901,
      "name": "Lunch Team Order",
      "status": "active",
      "restaurantId": 42,
      "restaurantName": "Green Bowl",
      "shareToken": "xk4j5n2b8v1q9z0p3s6d7f1h2j4k6m8n",
      "shareUrl": "https://dllni.mustafafares.com/group-order/xk4j5n2b8v1q9z0p3s6d7f1h2j4k6m8n",
      "deliveryFeeStrategy": "organizer_pays",
      "endsAt": "2026-05-13T17:00:00Z",
      "secondsRemaining": 2120,
      "creatorUserId": 17,
      "isCreator": false,
      "placedOrderId": null,
      "placedAt": null,
      "createdAt": "2026-05-13T16:00:00Z"
    },
    "participants": [
      {
        "participantId": 335,
        "userId": 22,
        "name": "Sara",
        "status": "joined",
        "hasResponded": false,
        "submittedAt": null,
        "subtotal": 14,
        "itemsCount": 1,
        "items": [
          {
            "id": 10048,
            "productId": 300,
            "name": "Chicken Salad",
            "quantity": 1,
            "unitPrice": 14,
            "totalPrice": 14,
            "modifierIds": [9011],
            "note": null
          }
        ]
      }
    ],
    "counts": {
      "participants": 2,
      "responded": 1,
      "pending": 1,
      "items": 2
    },
    "amounts": {
      "subtotal": 40,
      "deliveryFee": 0,
      "total": 40
    }
  }
}
```

---

## 8) Submit Participant
**POST** `/restaurants/group-orders/{groupOrderId}/submit`

### Request
No body.

### Success Response (200)
```json
{
  "message": "Participation confirmed.",
  "data": {
    "groupOrder": {
      "id": 901,
      "name": "Lunch Team Order",
      "status": "active",
      "restaurantId": 42,
      "restaurantName": "Green Bowl",
      "shareToken": "xk4j5n2b8v1q9z0p3s6d7f1h2j4k6m8n",
      "shareUrl": "https://dllni.mustafafares.com/group-order/xk4j5n2b8v1q9z0p3s6d7f1h2j4k6m8n",
      "deliveryFeeStrategy": "organizer_pays",
      "endsAt": "2026-05-13T17:00:00Z",
      "secondsRemaining": 1980,
      "creatorUserId": 17,
      "isCreator": false,
      "placedOrderId": null,
      "placedAt": null,
      "createdAt": "2026-05-13T16:00:00Z"
    },
    "participants": [
      {
        "participantId": 335,
        "userId": 22,
        "name": "Sara",
        "status": "submitted",
        "hasResponded": true,
        "submittedAt": "2026-05-13T16:36:20Z",
        "subtotal": 14,
        "itemsCount": 1,
        "items": [
          {
            "id": 10048,
            "productId": 300,
            "name": "Chicken Salad",
            "quantity": 1,
            "unitPrice": 14,
            "totalPrice": 14,
            "modifierIds": [9011],
            "note": null
          }
        ]
      }
    ],
    "counts": {
      "participants": 2,
      "responded": 2,
      "pending": 0,
      "items": 2
    },
    "amounts": {
      "subtotal": 40,
      "deliveryFee": 0,
      "total": 40
    }
  }
}
```

---

## 9) Unsubmit Participant
**POST** `/restaurants/group-orders/{groupOrderId}/unsubmit`

### Request
No body.

### Success Response (200)
```json
{
  "message": "Participation reverted to editing.",
  "data": {
    "groupOrder": {
      "id": 901,
      "name": "Lunch Team Order",
      "status": "active",
      "restaurantId": 42,
      "restaurantName": "Green Bowl",
      "shareToken": "xk4j5n2b8v1q9z0p3s6d7f1h2j4k6m8n",
      "shareUrl": "https://dllni.mustafafares.com/group-order/xk4j5n2b8v1q9z0p3s6d7f1h2j4k6m8n",
      "deliveryFeeStrategy": "organizer_pays",
      "endsAt": "2026-05-13T17:00:00Z",
      "secondsRemaining": 1870,
      "creatorUserId": 17,
      "isCreator": false,
      "placedOrderId": null,
      "placedAt": null,
      "createdAt": "2026-05-13T16:00:00Z"
    },
    "participants": [
      {
        "participantId": 335,
        "userId": 22,
        "name": "Sara",
        "status": "joined",
        "hasResponded": false,
        "submittedAt": null,
        "subtotal": 14,
        "itemsCount": 1,
        "items": [
          {
            "id": 10048,
            "productId": 300,
            "name": "Chicken Salad",
            "quantity": 1,
            "unitPrice": 14,
            "totalPrice": 14,
            "modifierIds": [9011],
            "note": null
          }
        ]
      }
    ],
    "counts": {
      "participants": 2,
      "responded": 1,
      "pending": 1,
      "items": 2
    },
    "amounts": {
      "subtotal": 40,
      "deliveryFee": 0,
      "total": 40
    }
  }
}
```

---

## 10) Cancel Group Order (Organizer Only)
**POST** `/restaurants/group-orders/{groupOrderId}/cancel`

### Request
No body.

### Success Response (200)
```json
{
  "message": "Group order cancelled.",
  "data": {
    "groupOrder": {
      "id": 901,
      "name": "Lunch Team Order",
      "status": "cancelled",
      "restaurantId": 42,
      "restaurantName": "Green Bowl",
      "shareToken": "xk4j5n2b8v1q9z0p3s6d7f1h2j4k6m8n",
      "shareUrl": "https://dllni.mustafafares.com/group-order/xk4j5n2b8v1q9z0p3s6d7f1h2j4k6m8n",
      "deliveryFeeStrategy": "organizer_pays",
      "endsAt": "2026-05-13T17:00:00Z",
      "secondsRemaining": 0,
      "creatorUserId": 17,
      "isCreator": true,
      "placedOrderId": null,
      "placedAt": null,
      "createdAt": "2026-05-13T16:00:00Z"
    },
    "participants": [
      {
        "participantId": 334,
        "userId": 17,
        "name": "Mustafa",
        "status": "joined",
        "hasResponded": false,
        "submittedAt": null,
        "subtotal": 26,
        "itemsCount": 2,
        "items": [
          {
            "id": 10045,
            "productId": 300,
            "name": "Chicken Salad",
            "quantity": 1,
            "unitPrice": 14,
            "totalPrice": 14,
            "modifierIds": [9011],
            "note": "No onions"
          },
          {
            "id": 10046,
            "productId": 301,
            "name": "Lemon Juice",
            "quantity": 2,
            "unitPrice": 6,
            "totalPrice": 12,
            "modifierIds": [],
            "note": null
          }
        ]
      },
      {
        "participantId": 335,
        "userId": 22,
        "name": "Sara",
        "status": "submitted",
        "hasResponded": true,
        "submittedAt": "2026-05-13T16:36:20Z",
        "subtotal": 18,
        "itemsCount": 1,
        "items": [
          {
            "id": 10047,
            "productId": 302,
            "name": "Turkey Wrap",
            "quantity": 1,
            "unitPrice": 18,
            "totalPrice": 18,
            "modifierIds": [9015, 9017],
            "note": "Sauce on side"
          }
        ]
      }
    ],
    "counts": {
      "participants": 2,
      "responded": 1,
      "pending": 1,
      "items": 3
    },
    "amounts": {
      "subtotal": 44,
      "deliveryFee": 0,
      "total": 44
    }
  }
}
```

---

## 11) Force Place Group Order (Organizer Only)
**POST** `/restaurants/group-orders/{groupOrderId}/place`

### Request
No body.

### Success Response (200)
```json
{
  "message": "Group order placed.",
  "data": {
    "groupOrder": {
      "id": 901,
      "name": "Lunch Team Order",
      "status": "placed",
      "restaurantId": 42,
      "restaurantName": "Green Bowl",
      "shareToken": "xk4j5n2b8v1q9z0p3s6d7f1h2j4k6m8n",
      "shareUrl": "https://dllni.mustafafares.com/group-order/xk4j5n2b8v1q9z0p3s6d7f1h2j4k6m8n",
      "deliveryFeeStrategy": "organizer_pays",
      "endsAt": "2026-05-13T17:00:00Z",
      "secondsRemaining": 0,
      "creatorUserId": 17,
      "isCreator": true,
      "placedOrderId": 7788,
      "placedAt": "2026-05-13T16:41:25Z",
      "createdAt": "2026-05-13T16:00:00Z"
    },
    "participants": [
      {
        "participantId": 334,
        "userId": 17,
        "name": "Mustafa",
        "status": "submitted",
        "hasResponded": true,
        "submittedAt": "2026-05-13T16:39:10Z",
        "subtotal": 26,
        "itemsCount": 2,
        "items": [
          {
            "id": 10045,
            "productId": 300,
            "name": "Chicken Salad",
            "quantity": 1,
            "unitPrice": 14,
            "totalPrice": 14,
            "modifierIds": [9011],
            "note": "No onions"
          },
          {
            "id": 10046,
            "productId": 301,
            "name": "Lemon Juice",
            "quantity": 2,
            "unitPrice": 6,
            "totalPrice": 12,
            "modifierIds": [],
            "note": null
          }
        ]
      },
      {
        "participantId": 335,
        "userId": 22,
        "name": "Sara",
        "status": "submitted",
        "hasResponded": true,
        "submittedAt": "2026-05-13T16:36:20Z",
        "subtotal": 18,
        "itemsCount": 1,
        "items": [
          {
            "id": 10047,
            "productId": 302,
            "name": "Turkey Wrap",
            "quantity": 1,
            "unitPrice": 18,
            "totalPrice": 18,
            "modifierIds": [9015, 9017],
            "note": "Sauce on side"
          }
        ]
      }
    ],
    "counts": {
      "participants": 2,
      "responded": 2,
      "pending": 0,
      "items": 3
    },
    "amounts": {
      "subtotal": 44,
      "deliveryFee": 0,
      "total": 44
    }
  }
}
```

---

## Realtime Contract
1. Auth endpoint: `POST /broadcasting/auth`.
2. Private channel: `private-group-order.{groupOrderId}`.
3. Event name: `group-order.updated`.
4. Recommended client behavior: refetch `GET /restaurants/group-orders/{groupOrderId}` and replace state.

---

## Lifecycle States
- `active`: allow join/item CRUD/submit-unsubmit.
- `placing`: lock editing, show progress.
- `placed`: lock editing, navigate using `placedOrderId`.
- `expired`: lock editing, show timeout summary.
- `cancelled`: lock editing, show cancellation summary.

---

## Common Error Examples

### 401 Unauthenticated
```json
{
  "message": "Unauthenticated."
}
```

### 404 Group Order Not Found
```json
{
  "message": "No query results for model [Modules\\Resturants\\Models\\RestaurantGroupOrder] 9999"
}
```

### 422 Not Participant
```json
{
  "message": "You are not a participant in this group order.",
  "errors": {
    "groupOrder": [
      "You are not a participant in this group order."
    ]
  }
}
```

### 422 Submit Without Items
```json
{
  "message": "Add at least one item before confirming participation.",
  "errors": {
    "items": [
      "Add at least one item before confirming participation."
    ]
  }
}
```

### 422 Organizer-Only Action
```json
{
  "message": "Only the organizer can perform this action.",
  "errors": {
    "groupOrder": [
      "Only the organizer can perform this action."
    ]
  }
}
```
