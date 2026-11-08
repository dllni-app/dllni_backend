# Phase 1 — Navigation and interaction state diagrams

These are proposed design diagrams, not evidence that backend routes already implement the contracts.

## Commerce navigation (both sections)

```mermaid
flowchart TD
    A["Shared homepage (unchanged)"] --> R["Restaurants / REST-01"]
    A --> S["Supermarkets / SM-01"]
    R --> RD["REST-02 Discovery"]
    S --> SD["SM-02 Discovery"]
    R --> RO["REST-04 Offers"]
    S --> SO["SM-04 Offers"]
    R --> RF["REST-05 Favorites"]
    S --> SF["SM-05 Favorites"]
    RD --> RM["REST-06 Restaurant"]
    SD --> SM["SM-06 Store"]
    RM --> RP["REST-07 Meal"]
    SM --> SP["SM-07 Product"]
    RP --> RC["REST-08 Merchant cart"]
    SP --> SC["SM-09 Store cart"]
    RC --> CK["Shared checkout / REST-09"]
    SC --> CK2["Shared checkout / SM-10"]
    CK --> Q["Authoritative quote"]
    CK2 --> Q
    Q --> C{"Ready and current?"}
    C -->|No| U["Review changed pricing / selection"]
    U --> Q
    C -->|Yes| P["Submit with idempotency key"]
    P --> O{"Result known?"}
    O -->|Unknown| REC["Recover original receipt"]
    REC --> O
    O -->|Confirmed| SUC["Confirmation"]
    SUC --> TRK["Delivery / pickup tracking"]
    RM --> GR["Existing group / vote / lucky"]
    SM --> COMP["Existing compare / shopping lists"]
```

## Cart and quote state machine

```mermaid
stateDiagram-v2
    [*] --> Editing
    Editing --> SynchronizingCart: add / quantity / configuration
    SynchronizingCart --> Editing: authoritative cart received
    SynchronizingCart --> Conflict: revision mismatch
    Conflict --> Editing: resolve latest cart
    Editing --> RequestingQuote: checkout entered / edited
    RequestingQuote --> Ready: valid quote
    RequestingQuote --> ValidationRequired: invalid stock/address/coupon
    ValidationRequired --> Editing: customer resolves
    Ready --> RequestingQuote: address / schedule / cart change
    Ready --> Submitting: confirm once
    Submitting --> Confirmed: order receipt
    Submitting --> Recovering: timeout / unknown response
    Recovering --> Confirmed: original receipt found
    Recovering --> Recovering: processing
    Recovering --> Editing: definitive non-creation
    Submitting --> QuoteChanged: server reprice
    QuoteChanged --> Editing: customer acknowledges
```

## Search generation guard

```mermaid
sequenceDiagram
    participant U as Customer
    participant B as Discovery BLoC
    participant D as Dio / API
    U->>B: Edit query or switch scope
    B->>B: increment request generation
    B->>D: debounced request (350ms)
    U->>B: Edit again
    B->>B: increment generation and cancel older read
    B->>D: new request
    D-->>B: older response arrives
    B->>B: discard stale generation
    D-->>B: current response arrives
    B-->>U: render current matching results
```

## Tracking refresh and timeout behavior

Foreground active orders use 15-second polling fallback, notification-triggered refresh and app-resume refresh. Deduplicate overlapping requests. No polling in background or terminal states. Pickup orders omit courier maps. On connectivity loss, show last known status with freshness timestamp; never overwrite it with an empty status. If order submission timed out, recover by original idempotency key rather than issuing a fresh order.

## Component callback boundaries
- Commerce header: `onBack`, `onLocationSelect`, `onCartOpen`.
- Search: `onTextChanged`, `onScopeChange`, `onFilterApply`, `onFilterReset`, `onLoadNext`.
- Merchant/product: `onOpen`, `onModifierChanged`, `onQuantityChanged`, `onAddToCart`.
- Cart/checkout: `onCartReconcile`, `onEditAddress`, `onEditSchedule`, `onApplyCoupon`, `onRequestQuote`, `onConfirm`, `onRecoverSubmission`.
- Order: `onRefresh`, `onCancel` / `onReschedule` only when server policy allows.
