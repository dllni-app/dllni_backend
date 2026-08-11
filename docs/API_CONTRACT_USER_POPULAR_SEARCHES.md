# User Popular Searches API

## GET `/api/v1/user/popular-searches`

Returns the most searched terms for one app department. Search terms are collected automatically from the existing restaurant and supermarket search endpoints.

### Query parameters

- `section` — required. Allowed values: `restaurant`, `supermarket`.
- `filter` — optional. Allowed values: `products`, `merchants`.
- `limit` — optional integer from `1` to `20`. Defaults to `6`.

When `filter` is omitted, the endpoint preserves the existing behavior and returns the combined popularity list for the selected section.

### Product example

`GET /api/v1/user/popular-searches?section=supermarket&filter=products&limit=6`

```json
{
  "section": "supermarket",
  "filter": "products",
  "data": [
    "حليب",
    "رز",
    "اندومي"
  ]
}
```

### Merchant example

`GET /api/v1/user/popular-searches?section=restaurant&filter=merchants&limit=6`

```json
{
  "section": "restaurant",
  "filter": "merchants",
  "data": [
    "مطعم الشام",
    "برغر هاوس"
  ]
}
```

### Tracking behavior

- `restaurant + products` tracks meal/product searches.
- `restaurant + merchants` tracks restaurant searches.
- `supermarket + products` tracks supermarket product searches.
- `supermarket + merchants` tracks supermarket store searches.
- Without `filter`, both product and merchant searches continue contributing to the combined section popularity list.

The filter value is intentionally named `merchants` so both supermarket stores and restaurants use the same client-facing contract.
