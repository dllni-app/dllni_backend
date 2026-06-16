# API Contract: Delivery Driver (v1)

## 1. Scope

This document describes the Delivery Driver API under:

- Base path: `/api/v1/delivery/driver`
- Module: `Modules/Delivery`
- Auth: Laravel Sanctum bearer token

It is intended for the driver mobile app integration.

## 2. Authentication

### 2.1 Login

- `POST /api/v1/delivery/driver/auth/login`
- Auth required: `No`

Request body:

```json
{
  "phone": "+963900000001",
  "password": "secret123",
  "fcmToken": "optional-device-token"
}
```

Validation:

- `phone`: required, string, max `20`
- `password`: required, string, min `6`
- `fcmToken`: nullable, string, max `500`

Success response:

```json
{
  "data": {
    "user": { "...": "App\\Http\\Resources\\UserResource" },
    "driver": {
      "id": 12,
      "userId": 77,
      "companyId": 4,
      "firstName": "Ahmad",
      "phone": "+963900000001",
      "vehicleType": "motorbike",
      "plateNumber": "123456",
      "availabilityStatus": "available",
      "isActive": true,
      "isSuspended": false,
      "trustScore": 100,
      "openDisputesCount": 0,
      "lastSeenAt": "2026-05-30T18:10:00+03:00",
      "createdAt": "2026-05-30T17:00:00+03:00"
    }
  },
  "token": "1|sanctum-token"
}
```

Failure:

- `422` invalid credentials
- `403` user exists but is not linked to a delivery driver profile

### 2.2 Authenticated endpoints

All remaining endpoints require:

- `Authorization: Bearer <token>`
- Middleware: `auth:sanctum` + `EnsureDeliveryDriver`

If user is not authenticated: `401`.
If authenticated user has no driver profile: `403`.

### 2.3 Logout

- `POST /api/v1/delivery/driver/auth/logout`
- Revokes current token.

### 2.4 Current driver profile

- `GET /api/v1/delivery/driver/me`
- Returns current driver profile (`DeliveryDriverResource`).

## 3. Availability and Location

### 3.1 Update availability

- `PATCH /api/v1/delivery/driver/availability`

Request body:

```json
{
  "availabilityStatus": "available"
}
```

Allowed values:

- `available`
- `busy`
- `offline`

Response: `data` = `DeliveryDriverResource`.

### 3.2 Post live location

- `POST /api/v1/delivery/driver/location`

Request body:

```json
{
  "latitude": 33.5140,
  "longitude": 36.2767,
  "accuracy": 5,
  "speed": 0,
  "heading": 90
}
```

Validation:

- `latitude`: required numeric `[-90, 90]`
- `longitude`: required numeric `[-180, 180]`
- `accuracy`: nullable numeric `>= 0`
- `speed`: nullable numeric `>= 0`
- `heading`: nullable numeric `[0, 360]`

Response: `201`, `data` = `DeliveryDriverLocationResource`.

## 4. Offers and Assignment

### 4.1 Current open offer

- `GET /api/v1/delivery/driver/offers/current`
- Returns `data = null` or `DeliveryAssignmentAttemptResource`.

### 4.2 Accept offer

- `POST /api/v1/delivery/driver/offers/{attempt}/accept`

Success:

- `200`, `data` = `DeliveryOrderResource`.

Conflict responses (`409`):

- offer expired / no longer open
- another driver owns the offer
- driver already has an active order

### 4.3 Reject offer

- `POST /api/v1/delivery/driver/offers/{attempt}/reject`

Request body:

```json
{
  "reason": "Too far away"
}
```

Validation:

- `reason`: required string, max `255`

Success: `200` with `{ "data": { "ok": true } }`.

Conflict responses: `409` with a business message.

## 5. Current Order and Lifecycle

### 5.1 Current active order

- `GET /api/v1/delivery/driver/orders/current`
- Returns `data = null` or `DeliveryOrderResource`.

### 5.2 Start order

- `POST /api/v1/delivery/driver/orders/{order}/start`

### 5.3 Pick up order

- `POST /api/v1/delivery/driver/orders/{order}/pickup`

### 5.4 Deliver order

- `POST /api/v1/delivery/driver/orders/{order}/deliver`

All lifecycle endpoints return:

- `200` with `DeliveryOrderResource` on success
- `422` for invalid transition/ownership rules

Expected happy-path status transitions:

- `dispatching -> offered -> accepted -> in_progress -> picked_up -> delivered -> completed`

## 6. Financial

### 6.1 Driver financial summary

- `GET /api/v1/delivery/driver/financial/summary`

Response (`DeliveryFinancialSummaryResource`):

```json
{
  "data": {
    "currentBalance": 15000,
    "financialLimit": 100000,
    "isSuspended": false,
    "suspensionReason": null,
    "currency": "SYP"
  }
}
```

## 7. Notifications

### 7.1 List notifications

- `GET /api/v1/delivery/driver/notifications?perPage=10&filter[unread]=1`

Validation:

- `perPage`: integer `1..100`
- `filter.unread`: boolean

Response:

- paginated `UserNotificationResource` collection

### 7.2 Mark notification read

- `PATCH /api/v1/delivery/driver/notifications/{id}/read`

Response:

```json
{
  "data": {
    "ok": true
  }
}
```

## 8. Disputes

### 8.1 Driver dispute list

- `GET /api/v1/delivery/driver/disputes?perPage=10`
- Returns paginated `DeliveryDisputeResource` scoped to disputes whose delivery order belongs to this driver.

## 9. Primary Response Shapes

### DeliveryOrderResource

Key fields:

- `id`, `orderNumber`, `companyId`, `driverId`
- `status`
- `customerName`, `customerPhone`
- `pickupAddress`, `pickupLatitude`, `pickupLongitude`
- `dropoffAddress`, `dropoffLatitude`, `dropoffLongitude`
- `distanceKm`, `deliveryFee`, `currency`
- `acceptedAt`, `startedAt`, `pickedUpAt`, `deliveredAt`, `completedAt`
- `events[]` (`DeliveryOrderEventResource`)

### DeliveryAssignmentAttemptResource

- `id`, `orderId`, `driverId`, `attemptNo`, `status`
- `distanceToPickupKm`
- `offeredAt`, `expiresAt`, `respondedAt`
- `order` (optional loaded `DeliveryOrderResource`)

### DeliveryDisputeResource

- `id`, `orderId`, `status`, `category`, `resolution`
- `ticketNumber`, `description`
- `createdAt`, `updatedAt`

## 10. Source of Truth

- Routes: `Modules/Delivery/routes/api.php`
- Driver controllers: `Modules/Delivery/app/Http/Controllers/API/Driver/*`
- Driver requests: `Modules/Delivery/app/Http/Requests/Driver/*`
- Driver resources: `Modules/Delivery/app/Http/Resources/*`
- Driver middleware: `Modules/Delivery/app/Http/Middleware/EnsureDeliveryDriver.php`
