# API Contract - User Restaurant Product Search

**Audience:** Mobile / frontend developers
**Base URL:** `https://dllni.mustafafares.com`
**API prefix:** `/api/v1/user`
**Auth:** public endpoint. If a Sanctum token is provided, `isFavorite` is resolved for the authenticated user.

---

## 1. Scope

This contract documents the user restaurant product search endpoint:

- `GET /api/v1/user/restaurants/products/search`

It supports three filters:

- `restaurantId` optional
- `text` optional, with semantic AI search support
- `categoryId` required

---

## 2. Request

### 2.1 Query parameters

| Param | Type | Required | Notes |
| ----- | ---- | -------- | ----- |
| restaurantId | integer | no | Limit results to one restaurant |
| categoryId | integer | yes | Category filter (`categories.id`) |
| text | string | no | Semantic search phrase |
| perPage | integer | no | Default `20`, max `100` |
| page | integer | no | Default `1` |

Behavior:

- When `text` is provided, the backend tries semantic search through `RestaurantSemanticProductSearchService` first.
- If the semantic service is unavailable, the endpoint falls back to local matching on product name and description.
- If `text` is omitted, the endpoint uses local filtering only.

### 2.2 Example request

```http
GET https://dllni.mustafafares.com/api/v1/user/restaurants/products/search?restaurantId=12&categoryId=4&text=spicy%20chicken&perPage=20&page=1
Accept: application/json
Authorization: Bearer {token}
```

The `Authorization` header is optional.

---

## 3. Success response

**Status:** `200 OK`

Response shape matches Laravel pagination with the restaurant product resource payload.

```json
{
  "data": [
    {
      "id": 501,
      "name": "Spicy Chicken Burger",
      "description": "Grilled chicken burger with spicy sauce.",
      "displayPrice": 5500,
      "originalPrice": 6200,
      "currency": "IQD",
      "isAvailable": true,
      "isFavorite": false,
      "isMostOrdered": true,
      "popularOrdersCount": 18,
      "primaryImageUrl": "https://cdn.example.com/products/501.jpg",
      "restaurant": {
        "id": 12,
        "name": "Fire Grill",
        "city": "Baghdad",
        "district": "Karrada"
      },
      "category": {
        "id": 4,
        "name": "Burgers"
      },
      "activeOffers": [
        {
          "id": 9,
          "title": "20% off lunch",
          "discountType": "percent",
          "discountValue": 20,
          "startsAt": "2026-04-01 00:00:00",
          "endsAt": "2026-04-30 23:59:59"
        }
      ],
      "createdAt": "2026-04-18 10:22:00"
    }
  ],
  "links": {
    "first": "https://dllni.mustafafares.com/api/v1/user/restaurants/products/search?restaurantId=12&categoryId=4&text=spicy%20chicken&perPage=20&page=1",
    "last": "https://dllni.mustafafares.com/api/v1/user/restaurants/products/search?restaurantId=12&categoryId=4&text=spicy%20chicken&perPage=20&page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "path": "https://dllni.mustafafares.com/api/v1/user/restaurants/products/search",
    "per_page": 20,
    "to": 1,
    "total": 1
  }
}
```

Notes:

- `isFavorite` is `false` for anonymous requests.
- `activeOffers` is an empty array when no active offers are loaded.
- `displayPrice` reflects the discounted price when a valid offer is active.

---

## 4. Error responses

### 4.1 Validation error

**Status:** `422 Unprocessable Entity`

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "categoryId": [
      "The category id field is required."
    ]
  }
}
```

### 4.2 Unauthorized token

**Status:** `401 Unauthorized`

Returned only when an invalid bearer token is supplied.

---

## 5. Integration notes

- Use this endpoint for restaurant catalog search in the user app.
- Prefer `text` for semantic queries instead of manually filtering by product name.
- Keep `perPage` at `20` or less for mobile UX consistency.
