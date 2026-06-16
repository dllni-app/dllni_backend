# API Contract: User Cleaning Order Review

## Endpoint

- Method: `POST`
- Path: `/api/v1/user/cleaning/orders/{order}/review`
- Auth: `Bearer` token required (`auth:sanctum`, user role)

## Purpose

Submit or update a customer review for a cleaning order.

- One review per `(order, customer)`.
- If a review already exists, this endpoint updates it.

## Path Parameters

- `order` (integer, required): cleaning order id.

## Request Body

```json
{
  "rating": 5,
  "comment": "Great service and on-time delivery."
}
```

## Request Validation Rules

- `rating`: required, integer, min `1`, max `5`
- `comment`: optional, string, max length `1000`

## Business Rules

- Order must belong to authenticated user (`customer_id = auth user id`).
- Order must be in status `completed`.
- If status is not `completed`, response is `422`.
- If order does not exist for this user, response is `404`.

## Success Response

- Status: `200 OK`

```json
{
  "data": {
    "ok": true
  },
  "message": "Review submitted successfully."
}
```

## Error Responses

### 401 Unauthorized

Missing or invalid token.

### 404 Not Found

Order id not found for authenticated user.

Example:

```json
{
  "message": "No query results for model [Modules\\Cleaning\\Models\\CleaningBooking] 15"
}
```

### 422 Unprocessable Entity (validation)

Example invalid rating:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "rating": [
      "The rating field is required."
    ]
  }
}
```

### 422 Unprocessable Entity (business rule)

Order not completed:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "status": [
      "Review can only be submitted for completed orders."
    ]
  }
}
```

## Idempotency and Upsert Behavior

This endpoint is not strictly idempotent by HTTP definition, but backend behavior is deterministic:

- First call creates review row.
- Next calls from same user for same order update the existing row.
- Uniqueness key: `booking_id + booking_type + customer_id`.

## Backend Source of Truth

- Route: `Modules/User/routes/api.php`
- Controller: `Modules/User/app/Http/Controllers/API/UserCleaningOrderReviewController.php`
- Request: `Modules/User/app/Http/Requests/UserCleaningOrderReviewRequest.php`
- Service: `Modules/User/app/Services/UserCleaningOrderService.php::submitReview()`
- Table: `booking_reviews`

