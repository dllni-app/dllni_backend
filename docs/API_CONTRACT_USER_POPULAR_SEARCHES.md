# User Popular Searches API

## GET `/api/v1/user/popular-searches`

Returns the most searched terms for one app department. Search terms are collected automatically from the existing restaurant and supermarket search endpoints.

### Query parameters

- `section` — required. Allowed values: `restaurant`, `supermarket`.
- `limit` — optional integer from `1` to `20`. Defaults to `6`.

### Example

`GET /api/v1/user/popular-searches?section=supermarket&limit=6`

```json
{
  "section": "supermarket",
  "data": [
    "حليب",
    "رز",
    "اندومي"
  ]
}
```

For restaurant search terms, send `section=restaurant`.

The `restaurant` section aggregates searches made through both restaurant discovery and restaurant product/meal search. The `supermarket` section aggregates supermarket store and supermarket product search.
