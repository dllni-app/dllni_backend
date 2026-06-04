# Worker Reviews API Guide

This endpoint powers the cleaning worker app reviews screen.

## Endpoint

`GET /api/v1/cleaning/worker/reviews`

Auth:

- `Authorization: Bearer <token>`
- Worker must have an associated `worker` profile.

## Query params

- `page` integer, optional, default `1`
- `perPage` integer, optional, default `20`, max `50`

Example:

```http
GET /api/v1/cleaning/worker/reviews?page=1&perPage=20
```

## Success response

```json
{
  "data": [
    {
      "id": 12,
      "customerName": "Mohammad Al Tayeb",
      "rating": 5,
      "comment": "Excellent service.",
      "createdAt": "2026-05-28T10:30:00.000000Z"
    }
  ],
  "meta": {
    "averageRating": 4.4,
    "totalCount": 12,
    "currentPage": 1,
    "lastPage": 1,
    "perPage": 20
  }
}
```

## Flutter mapping

Use these fields in `fetch_worker_reviews_model.dart`:

- `data[].id` -> review id
- `data[].customerName` -> customer display name
- `data[].rating` -> rating value from 1 to 5
- `data[].comment` -> nullable review text
- `data[].createdAt` -> ISO-8601 timestamp
- `meta.averageRating` -> worker average across all returned reviews
- `meta.totalCount` -> total number of reviews
- `meta.currentPage` -> current page
- `meta.lastPage` -> last page
- `meta.perPage` -> page size

## Empty state

When the worker has no reviews, return:

```json
{
  "data": [],
  "meta": {
    "averageRating": 0,
    "totalCount": 0,
    "currentPage": 1,
    "lastPage": 1,
    "perPage": 20
  }
}
```

## Error handling

- `401` unauthenticated
- `403` authenticated user has no worker profile
- `422` invalid `page` or `perPage`
- `500` unexpected server error

## Implementation note

The backend source of truth is `booking_reviews` joined through `cleaning_bookings`.
The endpoint only returns reviews for bookings that belong to the authenticated worker, including legacy single-worker bookings and team bookings with accepted assignments.
