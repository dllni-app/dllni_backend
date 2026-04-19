# Flutter Integration: Restaurant Group Ordering Realtime (Pusher)

This guide defines the backend API contract and the expected Flutter integration for realtime restaurant group ordering.

It is written to match the current Flutter app architecture in the profile module and the existing voting realtime pattern.

## 1) Feature Goal

Group ordering allows one organizer to open a shared restaurant session, send a share link, let participants add their own items, and place one final order automatically when the session completes.

## 2) Core Business Rules

1. All group-order endpoints require authenticated users (Sanctum token).
2. One organizer can have only one active group order at a time.
3. A group order is tied to one restaurant only.
4. Delivery fee strategy is organizer pays.
5. Session placement happens when either condition is true:
  - all joined participants are submitted
  - deadline is reached
6. Realtime event is metadata-only. Client must fetch full details after each event.

## 3) REST Endpoints

All endpoints are under `/api/v1/user`.

1. `POST /restaurants/group-orders`
  - Creates a new group order as organizer.
2. `POST /restaurants/group-orders/join`
  - Joins by share token.
3. `GET /restaurants/group-orders/active`
  - Returns active sessions where user is organizer or participant.
4. `GET /restaurants/group-orders/{groupOrder}`
  - Returns full source-of-truth payload for one session.
5. `POST /restaurants/group-orders/{groupOrder}/items`
  - Adds item for current participant.
6. `PATCH /restaurants/group-orders/{groupOrder}/items/{itemId}`
  - Updates current participant item.
7. `DELETE /restaurants/group-orders/{groupOrder}/items/{itemId}`
  - Deletes current participant item.
8. `POST /restaurants/group-orders/{groupOrder}/submit`
  - Marks current participant as submitted.
9. `POST /restaurants/group-orders/{groupOrder}/unsubmit`
  - Returns current participant to editable state.
10. `POST /restaurants/group-orders/{groupOrder}/cancel`
  - Organizer-only cancel.
11. `POST /restaurants/group-orders/{groupOrder}/place`
  - Organizer-only force place.

### 3.1 Request Bodies and Response Examples

All examples use `Content-Type: application/json` and authenticated bearer token.

#### 1) Create Group Order

`POST /api/v1/user/restaurants/group-orders`

Request body:

```json
{
  "restaurantId": 42,
  "name": "Lunch Team Order",
  "endsAt": "2026-04-19T14:30:00Z"
}
```

Success response (201):

```json
{
  "message": "Group order created successfully.",
  "data": {
    "groupOrder": {
      "id": 901,
      "status": "active",
      "name": "Lunch Team Order",
      "restaurantId": 42,
      "restaurantName": "Green Bowl",
      "shareToken": "grp_6f4f4a4fbf9e",
      "endsAt": "2026-04-19T14:30:00Z",
      "secondsRemaining": 3600,
      "creatorUserId": 17,
      "isCreator": true,
      "placedOrderId": null,
      "placedAt": null
    }
  }
}
```

#### 2) Join Group Order

`POST /api/v1/user/restaurants/group-orders/join`

Request body:

```json
{
  "shareToken": "grp_6f4f4a4fbf9e"
}
```

Success response (200):

```json
{
  "message": "Joined group order successfully.",
  "data": {
    "groupOrderId": 901,
    "participantId": 334,
    "status": "active"
  }
}
```

#### 3) List Active Group Orders

`GET /api/v1/user/restaurants/group-orders/active`

Request body: none

Success response (200):

```json
{
  "data": [
    {
      "groupOrder": {
        "id": 901,
        "status": "active",
        "name": "Lunch Team Order",
        "restaurantId": 42,
        "restaurantName": "Green Bowl",
        "shareToken": "grp_6f4f4a4fbf9e",
        "endsAt": "2026-04-19T14:30:00Z",
        "secondsRemaining": 3520,
        "creatorUserId": 17,
        "isCreator": true,
        "placedOrderId": null,
        "placedAt": null
      },
      "counts": {
        "participants": 3,
        "responded": 2,
        "pending": 1,
        "items": 6
      },
      "amounts": {
        "subtotal": 44.5,
        "deliveryFee": 3,
        "total": 47.5
      }
    }
  ]
}
```

#### 4) Show Group Order Details (Source of Truth)

`GET /api/v1/user/restaurants/group-orders/{groupOrder}`

Request body: none

Success response (200):

```json
{
  "data": {
    "groupOrder": {
      "id": 901,
      "status": "active",
      "name": "Lunch Team Order",
      "restaurantId": 42,
      "restaurantName": "Green Bowl",
      "shareToken": "grp_6f4f4a4fbf9e",
      "endsAt": "2026-04-19T14:30:00Z",
      "secondsRemaining": 3488,
      "creatorUserId": 17,
      "isCreator": false,
      "placedOrderId": null,
      "placedAt": null
    },
    "participants": [
      {
        "participantId": 334,
        "userId": 22,
        "name": "Sara",
        "status": "submitted",
        "hasResponded": true,
        "submittedAt": "2026-04-19T13:45:11Z",
        "subtotal": 14,
        "itemsCount": 2,
        "items": [
          {
            "id": 10001,
            "productId": 300,
            "productName": "Chicken Salad",
            "quantity": 1,
            "unitPrice": 10,
            "lineTotal": 10,
            "notes": "No onions",
            "modifiers": [
              {
                "id": 9011,
                "name": "Extra Avocado",
                "price": 4
              }
            ]
          }
        ]
      }
    ],
    "counts": {
      "participants": 3,
      "responded": 2,
      "pending": 1,
      "items": 6
    },
    "amounts": {
      "subtotal": 44.5,
      "deliveryFee": 3,
      "total": 47.5
    }
  }
}
```

#### 5) Add Item (Current Participant)

`POST /api/v1/user/restaurants/group-orders/{groupOrder}/items`

Request body:

```json
{
  "productId": 300,
  "quantity": 2,
  "notes": "No spicy sauce",
  "modifiers": [
    {
      "modifierId": 9011,
      "quantity": 1
    }
  ]
}
```

Success response (201):

```json
{
  "message": "Item added successfully.",
  "data": {
    "itemId": 10045,
    "participantId": 334,
    "subtotal": 28,
    "itemsCount": 3
  }
}
```

#### 6) Update Item (Current Participant)

`PATCH /api/v1/user/restaurants/group-orders/{groupOrder}/items/{itemId}`

Request body:

```json
{
  "quantity": 1,
  "notes": "Sauce on side",
  "modifiers": [
    {
      "modifierId": 9011,
      "quantity": 2
    }
  ]
}
```

Success response (200):

```json
{
  "message": "Item updated successfully.",
  "data": {
    "itemId": 10045,
    "participantId": 334,
    "subtotal": 24,
    "itemsCount": 3
  }
}
```

#### 7) Delete Item (Current Participant)

`DELETE /api/v1/user/restaurants/group-orders/{groupOrder}/items/{itemId}`

Request body: none

Success response (200):

```json
{
  "message": "Item deleted successfully.",
  "data": {
    "itemId": 10045,
    "participantId": 334,
    "subtotal": 14,
    "itemsCount": 2
  }
}
```

#### 8) Submit Participant

`POST /api/v1/user/restaurants/group-orders/{groupOrder}/submit`

Request body: none

Success response (200):

```json
{
  "message": "Participant submitted successfully.",
  "data": {
    "participantId": 334,
    "status": "submitted",
    "submittedAt": "2026-04-19T13:45:11Z"
  }
}
```

#### 9) Unsubmit Participant

`POST /api/v1/user/restaurants/group-orders/{groupOrder}/unsubmit`

Request body: none

Success response (200):

```json
{
  "message": "Participant returned to editable state.",
  "data": {
    "participantId": 334,
    "status": "active",
    "submittedAt": null
  }
}
```

#### 10) Cancel Group Order (Organizer)

`POST /api/v1/user/restaurants/group-orders/{groupOrder}/cancel`

Request body:

```json
{
  "reason": "Restaurant closed unexpectedly"
}
```

Success response (200):

```json
{
  "message": "Group order cancelled successfully.",
  "data": {
    "groupOrderId": 901,
    "status": "cancelled",
    "cancelledAt": "2026-04-19T14:00:00Z"
  }
}
```

#### 11) Force Place Group Order (Organizer)

`POST /api/v1/user/restaurants/group-orders/{groupOrder}/place`

Request body: none

Success response (200):

```json
{
  "message": "Group order placed successfully.",
  "data": {
    "groupOrderId": 901,
    "status": "placed",
    "placedOrderId": 7788,
    "placedAt": "2026-04-19T14:02:18Z"
  }
}
```

### 3.2 Error Response Examples

Validation error (422):

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "productId": [
      "The selected product id is invalid for this restaurant."
    ]
  }
}
```

Unauthorized (401):

```json
{
  "message": "Unauthenticated."
}
```

Business rule violation (422):

```json
{
  "message": "You cannot submit without at least one item."
}
```

Not found (404):

```json
{
  "message": "Group order not found."
}
```

## 4) Realtime Contract

1. Auth endpoint: `POST /broadcasting/auth`
2. Client channel: `private-group-order.{groupOrderId}`
3. Server channel key: `group-order.{groupOrderId}`
4. Event name: `group-order.updated`

Important behavior:

1. The event contains lightweight session metadata under `groupOrder`.
2. It does not replace the full session object.
3. After each `group-order.updated` event, Flutter must call:
  - `GET /api/v1/user/restaurants/group-orders/{groupOrderId}`
4. The GET response is the only source of truth for participants, items, counters, and amounts.

## 5) Payload Semantics Flutter Should Assume

From the show endpoint response, use:

1. `groupOrder`
  - id, status, name, restaurantId, restaurantName, shareToken, endsAt, secondsRemaining, creatorUserId, isCreator, placedOrderId, placedAt
2. `participants`
  - participantId, userId, name, status, hasResponded, submittedAt, subtotal, itemsCount, items
3. `counts`
  - participants, responded, pending, items
4. `amounts`
  - subtotal, deliveryFee, total

## 6) Session Lifecycle in UI

Recommended client behavior by status:

1. `active`
  - allow join, item CRUD, submit/unsubmit
2. `placing`
  - lock actions and show progress state
3. `placed`
  - lock editing and navigate to placed order using `placedOrderId`
4. `expired`
  - lock editing and show timeout summary
5. `cancelled`
  - lock editing and show cancellation summary

## 7) Flutter Project Integration (Based on Current Structure)

This section maps directly to current project files and architecture.

### A) Reuse Existing Voting Realtime Pattern

Existing implementation reference:

1. `lib/features/profile/view/screens/vote_followup_screen.dart`
  - uses PusherChannelsFlutter
  - uses `/broadcasting/auth`
  - subscribes to private channel
  - refreshes by API on event

Apply the same pattern for group orders using `private-group-order.{id}` and `group-order.updated`.

### B) Data Layer Additions

Recommended new files under profile feature:

1. `lib/features/profile/domain/usecases/` for group-order use-cases
2. `lib/features/profile/data/models/` for group-order API models
3. `lib/features/profile/data/source/profile_remote_data_source.dart`
  - add methods for all 11 group-order endpoints
4. `lib/features/profile/domain/repository/profile_repo.dart`
  - add abstract signatures for all group-order operations
5. `lib/features/profile/data/repository/profile_repo_impl.dart`
  - wire remote datasource calls with existing tryCall pattern

Use the same endpoint and conversion style already used for vote endpoints.

### C) DI and Injection

Current DI style references:

1. `lib/core/di/injection.dart`
2. `lib/core/di/injection.config.dart`

Add group-order use-cases and any new bloc/cubit to injectable registration, following current `ProfileBloc` and vote use-case registration style.

### D) State Management

Current profile state management references:

1. `lib/features/profile/view/manager/bloc/profile_bloc.dart`
2. `lib/features/profile/view/manager/bloc/profile_event.dart`
3. `lib/features/profile/view/manager/bloc/profile_state.dart`

You can either:

1. extend `ProfileBloc` with group-order events/state
2. or create a dedicated group-order bloc/cubit in the same profile module

Preferred for maintainability: dedicated group-order bloc/cubit, because voting, shopping lists, and account states are already dense in one bloc.

### E) Routing and Navigation

Current generated route map reference:

1. `lib/generated/app_routes.g.dart`

Add group-order screens in profile feature and route entries via your route generation flow (do not hand-edit generated files).

Suggested screen set:

1. organizer create/share screen
2. participant join and item editing screen
3. organizer tracking dashboard screen
4. completion screen for placed/expired/cancelled

### F) Deep Link Handling

Share links must carry at least `shareToken` and optionally `groupOrderId`.

Expected behavior:

1. open app from link
2. validate session/auth
3. call join endpoint with token
4. navigate to group-order details screen

## 8) Recommended Flutter Execution Steps

1. Add data models for group-order response objects.
2. Add remote datasource methods for all group-order APIs.
3. Add repo contracts and implementation wiring.
4. Add use-cases per endpoint action.
5. Register all new dependencies in injectable.
6. Build group-order bloc/cubit with clear action states.
7. Build screens and route entries.
8. Add realtime service or screen-level realtime connection (same pattern as vote follow-up).
9. Implement event-driven refetch on `group-order.updated`.
10. Add deep-link join flow.
11. Add analytics and logging for create, join, submit, place, cancel.

## 9) Realtime and Sync Rules

1. Always fetch details once before subscribing.
2. After each event, re-fetch and replace full screen state.
3. Keep local countdown from `secondsRemaining`, but re-align on every fresh response.
4. On app resume, re-fetch immediately to avoid stale state.
5. On logout, disconnect pusher and clear transient group-order state.

## 10) Error Handling Contract

Flutter should treat these as expected business outcomes:

1. unauthorized when token expired
2. unprocessable for invalid actions (submit without items, organizer-only violations, session closed)
3. item validation failures (wrong restaurant product, invalid modifiers)
4. not found for invalid token/session references

UI guidance:

1. show user-facing messages for business errors
2. keep state editable when validation fails
3. lock actions when status is not active

## 11) Runtime Requirements

Backend requirements:

1. `BROADCAST_CONNECTION=pusher`
2. `QUEUE_CONNECTION=database`
3. valid pusher credentials
4. queue worker running
5. scheduler worker running for deadline processing

Flutter requirements already satisfied in current project:

1. `pusher_channels_flutter` dependency is present
2. app base URL is centralized in `lib/core/app_config.dart`
3. authenticated Dio stack with token interceptor is configured in DI

## 12) QA Checklist for Flutter Team

1. create group order success
2. join by token success
3. participant item CRUD updates totals correctly
4. submit and unsubmit flows update participant status correctly
5. organizer can cancel and force place
6. realtime update refresh works across two devices
7. placed flow navigates to final order details using `placedOrderId`
8. deadline transition from active to placed or expired appears correctly

## 13) Final Notes

1. Keep API naming and payload mapping close to existing vote implementation for consistency.
2. Treat the show endpoint response as authoritative state.
3. Prefer incremental rollout: first API and polling flow, then realtime subscription, then deep links.
