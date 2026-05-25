# API Contract: User Regular Cleaning Services (`serviceIds` support)

## Scope
This contract defines how Flutter can attach optional cleaning services to **regular cleaning** orders (`propertyType` other than `event_assistance`).

- Base path: `/api/v1/user/cleaning/orders`
- Auth: `Authorization: Bearer <token>`
- Content-Type: `application/json`

Regular property types:
- `apartment`
- `villa`
- `house`
- `office`
- `studio`

---

## Service Discovery

Use public cleaning-services endpoint and filter by category:

- `GET /api/v1/cleaning-services?filter[category]=cleaning&filter[isActive]=1`

Each service includes `pricing[]` entries:
- `propertyType`
- `livingRoomSize` (nullable)
- `basePrice`
- `pricePerSqm` (nullable)
- `minHours` (nullable)

---

## Supported Endpoints

1) `POST /api/v1/user/cleaning/orders/estimate-size`  
2) `POST /api/v1/user/cleaning/orders/estimate-price`  
3) `POST /api/v1/user/cleaning/orders`  
4) `PATCH /api/v1/user/cleaning/orders/{orderId}`  

---

## Request Contract Changes

`serviceIds` is now supported for regular cleaning payloads.

- `serviceIds`: optional array
- `serviceIds.*`: distinct integer ids from `cleaning_services`
- If sent, array must be non-empty (`min:1`)

Example request body (estimate/create/update):

```json
{
  "propertyType": "apartment",
  "propertyDetails": {
    "address": "Damascus, Mazzeh",
    "rooms": 2,
    "bedrooms": 1,
    "bathrooms": 1,
    "living_room_size": "small"
  },
  "serviceIds": [12, 15]
}
```

---

## Pricing Behavior

For regular cleaning:

- `basePrice` remains computed by existing property-size algorithm.
- Selected `serviceIds` are priced as **addons**.
- `addonsTotal = sum(serviceLines[].totalPrice)`.
- `totalPrice = basePrice + addonsTotal + travelFee + adminMargin`.

Service line pricing rule per selected service:

1. Resolve service pricing by best match:
   - exact `propertyType` + `livingRoomSize`
   - else same `propertyType` with nullable `livingRoomSize`
   - else first row with same `propertyType`
   - else first available pricing row for that service
2. `unitPrice`:
   - if `pricePerSqm` exists: `max(basePrice, pricePerSqm * estimatedSqm)`
   - otherwise: `basePrice`
3. `quantity=1`, `totalPrice=unitPrice`

---

## Response Additions

`POST /estimate-price` returns `pricing.serviceLines` for regular cleaning when `serviceIds` is sent:

```json
{
  "pricing": {
    "basePrice": 920,
    "addonsTotal": 210,
    "travelFee": 0,
    "adminMargin": 0,
    "totalPrice": 1130,
    "currency": "SYP",
    "serviceLines": [
      {
        "cleaningServiceId": 12,
        "name": "Balcony add-on",
        "quantity": 1,
        "unitPrice": 120,
        "totalPrice": 120,
        "minHours": 1
      },
      {
        "cleaningServiceId": 15,
        "name": "Window add-on",
        "quantity": 1,
        "unitPrice": 90,
        "totalPrice": 90,
        "minHours": 1
      }
    ]
  }
}
```

Create/update responses continue returning `order` (`CleaningBookingResource`) and now persist selected regular services to `order.services`.

---

## Validation and Errors

### Invalid category or inactive service in regular mode
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "pricing": [
      "One or more selected regular cleaning services are invalid."
    ]
  }
}
```

### Service has no pricing rows
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "pricing": [
      "No pricing configured for regular cleaning service [Service Name]."
    ]
  }
}
```

---

## Flutter Notes

- Keep current regular-cleaning flow unchanged if no services are selected.
- When user selects optional services, send `serviceIds` in estimate-price, create, and update payloads.
- Render `pricing.serviceLines` as selected add-ons summary and use `pricing.addonsTotal` for totals UI.
- Keep event assistance contract in `API_CONTRACT_USER_CLEANING_EVENT_ASSISTANCE.md` for `propertyType=event_assistance`.
