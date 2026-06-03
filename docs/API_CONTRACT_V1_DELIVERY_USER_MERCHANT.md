# API Contract: Delivery User and Merchant Surfaces (v1)

This document describes the delivery MVP API surface that is consumed by:

- the user app for order list/detail tracking
- the restaurant owner app for delivery status visibility
- the supermarket owner app for delivery status visibility

It is intentionally backend-only. No Flutter implementation details are included here.

## Scope

- Base URL: `/api/v1`
- Auth: `Authorization: Bearer <sanctum_token>`
- Content type: `application/json`

This contract does **not** cover the driver API. See:

- `docs/API_CONTRACT_V1_DELIVERY_DRIVER.md`

## 1) User delivery endpoints

### 1.1 List delivery orders

- Method: `GET`
- Path: `/api/v1/delivery/user/orders`
- Middleware: `auth:sanctum`
- Controller: `Modules/Delivery/app/Http/Controllers/API/User/DeliveryUserOrderController.php@index`

#### Query parameters

| Param | Type | Required | Notes |
| --- | --- | --- | --- |
| `perPage` | integer | No | Optional page size. Backend clamps it to `1..50`. Default is `20`. |
| `page` | integer | No | Standard Laravel paginator page number. |

#### Behavior

- Returns only orders owned by the authenticated user.
- Ownership is enforced by `created_by_user_id`.
- Orders are sorted by `updated_at` descending.

#### Success response

`200 OK` with a Laravel paginator payload:

The example below is shortened for readability. The `data[]` items use the same `DeliveryOrderResource` shape as the detail endpoint.

```json
{
  "data": [
    {
      "id": 101,
      "orderNumber": "DO-2026-000101",
      "companyId": 4,
      "company": {
        "id": 4,
        "name": "Dllni Delivery",
        "phone": "+963900000000",
        "email": "ops@example.com"
      },
      "driverId": 12,
      "driver": {
        "id": 12,
        "userId": 77,
        "companyId": 4,
        "firstName": "Ahmad",
        "displayName": "Ahmad Ali",
        "phone": "+963900000001",
        "vehicleType": "motorbike",
        "plateNumber": "123456",
        "availabilityStatus": "available",
        "isActive": true,
        "isSuspended": false,
        "trustScore": 100,
        "openDisputesCount": 0,
        "lastSeenAt": "2026-06-03T08:10:00+03:00",
        "latestLocation": {
          "id": 9001,
          "driverId": 12,
          "latitude": 33.514,
          "longitude": 36.2767,
          "accuracy": 5,
          "speed": 0,
          "heading": 90,
          "recordedAt": "2026-06-03T08:09:30+03:00"
        },
        "createdAt": "2026-06-01T10:00:00+03:00"
      },
    "status": "accepted",
    "statusLabelAr": "localized Arabic label",
      "customerName": "Ali Hassan",
      "customerPhone": "+963988888888",
      "customerNotes": "Leave at the gate",
      "pickupAddress": "Old City",
      "pickupLatitude": 33.5139,
      "pickupLongitude": 36.2765,
      "dropoffAddress": "Mazza",
      "dropoffLatitude": 33.5071,
      "dropoffLongitude": 36.2901,
      "distanceKm": 3.7,
      "deliveryFee": 2500,
      "currency": "SYP",
      "acceptedAt": "2026-06-03T08:00:00+03:00",
      "startedAt": "2026-06-03T08:05:00+03:00",
      "pickedUpAt": null,
      "deliveredAt": null,
      "completedAt": null,
      "stoppedAt": null,
      "cancelledAt": null,
      "stopReason": null,
      "cancelReason": null,
      "timeline": [],
      "tracking": {
        "currentStatus": "accepted"
      },
      "events": [],
      "createdByUserId": 17,
      "createdAt": "2026-06-03T07:50:00+03:00",
      "updatedAt": "2026-06-03T08:10:00+03:00"
    }
  ],
  "links": {
    "first": "...",
    "last": "...",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "path": "...",
    "per_page": 20,
    "to": 1,
    "total": 1
  }
}
```

#### Errors

| Status | Meaning |
| --- | --- |
| `401` | Missing or invalid Sanctum token. |

### 1.2 Show delivery order details

- Method: `GET`
- Path: `/api/v1/delivery/user/orders/{order}`
- Middleware: `auth:sanctum`
- Route constraint: `{order}` must be numeric
- Controller: `Modules/Delivery/app/Http/Controllers/API/User/DeliveryUserOrderController.php@show`

#### Behavior

- Returns a single delivery order owned by the authenticated user.
- If the order does not exist or is not owned by the authenticated user, the API returns `404`.

#### Success response

`200 OK`

The example below is abbreviated. The real payload includes the full `timeline`, `tracking`, and `events` arrays returned by the resource.

```json
{
  "data": {
    "id": 101,
    "orderNumber": "DO-2026-000101",
    "companyId": 4,
    "company": {
      "id": 4,
      "name": "Dllni Delivery",
      "phone": "+963900000000",
      "email": "ops@example.com"
    },
    "driverId": 12,
    "driver": {
      "id": 12,
      "userId": 77,
      "companyId": 4,
      "firstName": "Ahmad",
      "displayName": "Ahmad Ali",
      "phone": "+963900000001",
      "vehicleType": "motorbike",
      "plateNumber": "123456",
      "availabilityStatus": "available",
      "isActive": true,
      "isSuspended": false,
      "trustScore": 100,
      "openDisputesCount": 0,
      "lastSeenAt": "2026-06-03T08:10:00+03:00",
      "latestLocation": {
        "id": 9001,
        "driverId": 12,
        "latitude": 33.514,
        "longitude": 36.2767,
        "accuracy": 5,
        "speed": 0,
        "heading": 90,
        "recordedAt": "2026-06-03T08:09:30+03:00"
      },
      "createdAt": "2026-06-01T10:00:00+03:00"
      },
      "status": "accepted",
      "statusLabelAr": "localized Arabic label",
    "customerName": "Ali Hassan",
    "customerPhone": "+963988888888",
    "customerNotes": "Leave at the gate",
    "pickupAddress": "Old City",
    "pickupLatitude": 33.5139,
    "pickupLongitude": 36.2765,
    "dropoffAddress": "Mazza",
    "dropoffLatitude": 33.5071,
    "dropoffLongitude": 36.2901,
    "distanceKm": 3.7,
    "deliveryFee": 2500,
    "currency": "SYP",
    "acceptedAt": "2026-06-03T08:00:00+03:00",
    "startedAt": "2026-06-03T08:05:00+03:00",
    "pickedUpAt": null,
    "deliveredAt": null,
    "completedAt": null,
    "stoppedAt": null,
    "cancelledAt": null,
    "stopReason": null,
    "cancelReason": null,
    "timeline": [
      {
        "key": "created",
        "timestamp": "2026-06-03T07:50:00+03:00",
        "completed": true,
        "active": false
      }
    ],
    "tracking": {
      "currentStatus": "accepted",
      "currentStatusLabelAr": "localized Arabic label",
      "eta": {
        "minutes": 12,
        "text": "localized Arabic text",
        "referenceDistanceKm": 5.2,
        "updatedAt": "2026-06-03T08:10:00+03:00"
      },
      "map": {
        "enabled": true,
        "centerLatitude": 33.5105,
        "centerLongitude": 36.2834,
        "zoom": 13.5,
        "markers": [
          {
            "kind": "pickup",
            "latitude": 33.5139,
            "longitude": 36.2765,
            "address": "Old City"
          },
          {
            "kind": "dropoff",
            "latitude": 33.5071,
            "longitude": 36.2901,
            "address": "Mazza"
          },
          {
            "kind": "driver",
            "latitude": 33.514,
            "longitude": 36.2767,
            "accuracy": 5,
            "speed": 0,
            "heading": 90,
            "recordedAt": "2026-06-03T08:09:30+03:00"
          }
        ],
        "route": [
          {
            "latitude": 33.5139,
            "longitude": 36.2765
          },
          {
            "latitude": 33.5071,
            "longitude": 36.2901
          }
        ],
        "routeDistanceKm": 3.7
      },
      "timeline": [],
      "stages": [],
      "driver": {
        "id": 12,
        "userId": 77,
        "companyId": 4,
        "firstName": "Ahmad",
        "displayName": "Ahmad Ali",
        "phone": "+963900000001",
        "vehicleType": "motorbike",
        "plateNumber": "123456",
        "availabilityStatus": "available",
        "isActive": true,
        "isSuspended": false,
        "trustScore": 100,
        "openDisputesCount": 0,
        "lastSeenAt": "2026-06-03T08:10:00+03:00",
        "latestLocation": {
          "id": 9001,
          "driverId": 12,
          "latitude": 33.514,
          "longitude": 36.2767,
          "accuracy": 5,
          "speed": 0,
          "heading": 90,
          "recordedAt": "2026-06-03T08:09:30+03:00"
        }
      },
      "pickup": {
        "kind": "pickup",
        "latitude": 33.5139,
        "longitude": 36.2765,
        "address": "Old City"
      },
      "dropoff": {
        "kind": "dropoff",
        "latitude": 33.5071,
        "longitude": 36.2901,
        "address": "Mazza"
      },
      "route": [
        {
          "kind": "pickup",
          "latitude": 33.5139,
          "longitude": 36.2765,
          "address": "Old City"
        },
        {
          "kind": "dropoff",
          "latitude": 33.5071,
          "longitude": 36.2901,
          "address": "Mazza"
        }
      ]
    },
    "events": [
      {
        "id": 1,
        "fromStatus": "dispatching",
        "toStatus": "accepted",
        "note": null,
        "payload": {},
        "createdAt": "2026-06-03T08:00:00+03:00"
      }
    ],
    "createdByUserId": 17,
    "createdAt": "2026-06-03T07:50:00+03:00",
    "updatedAt": "2026-06-03T08:10:00+03:00"
  }
}
```

#### Errors

| Status | Meaning |
| --- | --- |
| `401` | Missing or invalid Sanctum token. |
| `404` | Order does not exist or is not owned by the current user. |

## 2) Delivery order resource contract

### `DeliveryOrderResource`

The resource returned by the user delivery endpoints contains these fields:

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | Delivery order ID. |
| `orderNumber` | string | Human-readable order number. |
| `companyId` | integer | Delivery company ID. |
| `company` | object \| null | Loaded company snapshot: `id`, `name`, `phone`, `email`. |
| `driverId` | integer | Assigned driver ID. |
| `driver` | object \| null | Loaded `DeliveryDriverResource`. |
| `status` | string | Delivery status enum string. |
| `statusLabelAr` | string \| null | Arabic label for the current status. |
| `customerName` | string \| null | Customer name. |
| `customerPhone` | string \| null | Customer phone. |
| `customerNotes` | string \| null | Customer notes. |
| `pickupAddress` | string \| null | Pickup address. |
| `pickupLatitude` | number | Pickup latitude. |
| `pickupLongitude` | number | Pickup longitude. |
| `dropoffAddress` | string \| null | Dropoff address. |
| `dropoffLatitude` | number | Dropoff latitude. |
| `dropoffLongitude` | number | Dropoff longitude. |
| `distanceKm` | number | Delivery distance in km. |
| `deliveryFee` | number | Delivery fee. |
| `currency` | string \| null | Currency code, for example `SYP`. |
| `acceptedAt` | string \| null | ISO-8601 datetime. |
| `startedAt` | string \| null | ISO-8601 datetime. |
| `pickedUpAt` | string \| null | ISO-8601 datetime. |
| `deliveredAt` | string \| null | ISO-8601 datetime. |
| `completedAt` | string \| null | ISO-8601 datetime. |
| `stoppedAt` | string \| null | ISO-8601 datetime. |
| `cancelledAt` | string \| null | ISO-8601 datetime. |
| `stopReason` | string \| null | Stop reason, if any. |
| `cancelReason` | string \| null | Cancel reason, if any. |
| `timeline` | array | Same stage array as `tracking.timeline` for compatibility. |
| `tracking` | object | Full tracking payload, described below. |
| `events` | array | `DeliveryOrderEventResource` list. |
| `createdByUserId` | integer \| null | Owning user ID. |
| `createdAt` | string \| null | ISO-8601 datetime. |
| `updatedAt` | string \| null | ISO-8601 datetime. |

### `DeliveryDriverResource`

The top-level `driver` field is the full delivery driver snapshot:

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | Driver ID. |
| `userId` | integer | Linked user ID. |
| `companyId` | integer | Delivery company ID. |
| `firstName` | string \| null | Driver first name. |
| `displayName` | string \| null | User display name if loaded, otherwise first name. |
| `phone` | string \| null | Driver phone. |
| `vehicleType` | string \| null | Vehicle type string. |
| `plateNumber` | string \| null | Vehicle plate. |
| `availabilityStatus` | string \| null | Driver availability status. |
| `isActive` | boolean | Active flag. |
| `isSuspended` | boolean | Suspension flag. |
| `trustScore` | number \| null | Trust score. |
| `openDisputesCount` | integer \| null | Open disputes count. |
| `lastSeenAt` | string \| null | ISO-8601 datetime. |
| `latestLocation` | object \| null | Latest GPS snapshot, if loaded. |
| `createdAt` | string \| null | ISO-8601 datetime. |

### `DeliveryOrderEventResource`

The `events` array contains delivery status change records:

| Field | Type | Notes |
| --- | --- | --- |
| `id` | integer | Event ID. |
| `fromStatus` | string \| null | Previous delivery status. |
| `toStatus` | string \| null | New delivery status. |
| `note` | string \| null | Optional free-form note. |
| `payload` | object \| array \| null | Raw event payload. |
| `createdAt` | string \| null | ISO-8601 datetime. |

### `tracking` payload

The `tracking` object is generated by `Modules/Delivery/app/Support/DeliveryPresentation.php::orderTracking()`.

| Field | Type | Notes |
| --- | --- | --- |
| `currentStatus` | string | Current delivery status. |
| `currentStatusLabelAr` | string \| null | Arabic label for the current status. |
| `eta` | object | Estimated arrival/update metadata. |
| `map` | object | Marker and route payload for map rendering. |
| `timeline` | array | Stage history. |
| `stages` | array | Alias of `timeline` for compatibility. |
| `driver` | object \| null | Compact driver snapshot used by the tracking UI. |
| `pickup` | object \| null | Pickup point payload. |
| `dropoff` | object \| null | Dropoff point payload. |
| `route` | array | Compact pickup/dropoff point list. |

#### `eta`

| Field | Type | Notes |
| --- | --- | --- |
| `minutes` | integer \| null | Estimated minutes remaining when calculable. |
| `text` | string | Localized status hint. |
| `referenceDistanceKm` | number \| null | Distance basis used for ETA estimation. |
| `updatedAt` | string | ISO-8601 datetime. |

#### `map`

| Field | Type | Notes |
| --- | --- | --- |
| `enabled` | boolean | True when at least one marker is available. |
| `centerLatitude` | number \| null | Suggested map center latitude. |
| `centerLongitude` | number \| null | Suggested map center longitude. |
| `zoom` | number | Default zoom value. |
| `markers` | array | Pickup, dropoff, and driver markers. |
| `route` | array | Compact coordinate line for pickup/dropoff. |
| `routeDistanceKm` | number \| null | Geodesic distance between pickup and dropoff. |

#### `tracking.timeline` / `tracking.stages`

Each stage item has:

| Field | Type | Notes |
| --- | --- | --- |
| `key` | string | Stage key. |
| `timestamp` | string \| null | ISO-8601 datetime. |
| `completed` | boolean | Whether the stage is completed. |
| `active` | boolean | Whether the stage is currently active. |

Stage keys returned by the backend:

- `created`
- `searching_driver`
- `driver_en_route`
- `arrived_pickup`
- `handover_complete`
- `delivered`
- `completed`
- `stopped`
- `cancelled`

#### `tracking.driver`

The compact driver snapshot inside `tracking` omits `createdAt`, but otherwise matches the top-level driver shape except that it is optimized for tracking UI consumption.

#### `tracking.pickup` / `tracking.dropoff`

| Field | Type | Notes |
| --- | --- | --- |
| `kind` | string | `pickup` or `dropoff`. |
| `latitude` | number | Coordinate. |
| `longitude` | number | Coordinate. |
| `address` | string \| null | Display address. |

#### `tracking.map.markers`

Marker items are one of:

- pickup marker: `kind`, `latitude`, `longitude`, `address`
- dropoff marker: `kind`, `latitude`, `longitude`, `address`
- driver marker: `kind`, `latitude`, `longitude`, `accuracy`, `speed`, `heading`, `recordedAt`

## 3) Merchant payload additions

### 3.1 Restaurant owner orders

`Modules/Resturants/app/Http/Resources/OrderResource.php` now adds:

- `deliverySummary`

This is an additive field. Existing restaurant order fields are unchanged.

### 3.2 Supermarket owner orders

`Modules/Supermarket/app/Http/Resources/SmOrderResource.php` now adds:

- `deliverySummary`

This is an additive field. Existing supermarket order fields are unchanged.

### 3.3 `deliverySummary` schema

The merchant summary is generated by `Modules/Delivery/app/Support/DeliveryPresentation.php::merchantSummary()`.

| Field | Type | Notes |
| --- | --- | --- |
| `enabled` | boolean | Always `true` when the summary is present. |
| `status` | string | Normalized order status string. |
| `statusLabelAr` | string \| null | Arabic label for the current status. |
| `currentStage` | string | Merchant-facing delivery stage. |
| `isTerminal` | boolean | True when the merchant flow is in a terminal stage. |
| `pickupMode` | string \| null | Normalized pickup mode. |
| `readyForPickupAt` | string \| null | ISO-8601 datetime. |
| `pickedUpAt` | string \| null | ISO-8601 datetime. |
| `completedAt` | string \| null | ISO-8601 datetime. |
| `cancelledAt` | string \| null | ISO-8601 datetime. |
| `cancellationReason` | string \| null | Cancellation reason, if any. |
| `timeline` | array | Merchant stage timeline. |

#### `deliverySummary.currentStage`

Possible values:

- `driver_en_route`
- `arrived`
- `handover_complete`
- `completed`
- `not_received`
- `cancelled`

#### `deliverySummary.timeline`

Each item has:

| Field | Type | Notes |
| --- | --- | --- |
| `key` | string | Merchant stage key. |
| `timestamp` | string \| null | ISO-8601 datetime. |
| `completed` | boolean | Whether the stage has been reached. |
| `active` | boolean | Whether the stage is the active merchant stage. |

Merchant stage keys:

- `driver_en_route`
- `arrived`
- `handover_complete`
- `completed`
- `not_received`
- `cancelled`

#### Pickup mode values

The helper currently normalizes pickup mode to the existing enum values:

- `immediate_pickup`
- `scheduled_pickup`

## 4) Notification and deep-link metadata

Delivery notifications are emitted through the existing user notification feed.

### 4.1 Notification feed

- Existing endpoint: `GET /api/v1/user/notifications`
- Delivery notifications resolve to `module = delivery`

### 4.2 Delivery notification payload keys

Backend-delivered notification `data` may include:

- `deepLinkTarget` or `deep_link_target`
- `orderId`
- `orderNumber`

Known delivery targets used by the backend:

- `delivery_order_details`
- `delivery_order_tracking`

### 4.3 Deep-link canonical type

The user app deep-link resolver accepts the canonical type:

- `delivery-order`

It also recognizes API-shaped delivery links for user orders:

- `/api/v1/user/delivery/orders/{id}`

These are client-routing inputs, not additional backend REST endpoints.

## 5) Timestamp and compatibility notes

- User delivery API timestamps are returned as ISO-8601 strings.
- `deliverySummary` timestamps are also ISO-8601 strings.
- Existing restaurant and supermarket base order timestamps remain in the current resource format used by those modules.
- `timeline` and `tracking.stages` are intentionally duplicated for compatibility.
- The user delivery endpoints are read-only in this contract; the MVP expects polling/refresh rather than realtime sockets.

## 6) Error handling

| Endpoint | Possible errors |
| --- | --- |
| `GET /api/v1/delivery/user/orders` | `401` |
| `GET /api/v1/delivery/user/orders/{order}` | `401`, `404` |

## 7) Source of truth

- `Modules/Delivery/routes/api.php`
- `Modules/Delivery/app/Http/Controllers/API/User/DeliveryUserOrderController.php`
- `Modules/Delivery/app/Http/Resources/DeliveryOrderResource.php`
- `Modules/Delivery/app/Http/Resources/DeliveryDriverResource.php`
- `Modules/Delivery/app/Http/Resources/DeliveryOrderEventResource.php`
- `Modules/Delivery/app/Support/DeliveryPresentation.php`
- `Modules/Resturants/app/Http/Resources/OrderResource.php`
- `Modules/Supermarket/app/Http/Resources/SmOrderResource.php`
- `tests/Feature/Delivery/UserDeliveryApiTest.php`
