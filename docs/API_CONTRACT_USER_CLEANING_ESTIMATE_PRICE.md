# API Contract: User Cleaning Estimate Price

## Purpose
This contract defines the request payload for:

`POST /api/v1/user/cleaning/orders/estimate-price`

It covers the regular cleaning estimate flow, optional room breakdown support, and preferred-worker pricing.

## Base Rules
- Base path: `/api/v1/user/cleaning/orders`
- Auth: `Authorization: Bearer <token>`
- Content-Type: `application/json`
- `propertyType` is required.
- `propertyDetails` is required and must be an object.
- `propertyDetails.room_size_breakdown` is optional, but if present it must be a nested object with the full room shape.
- `preferredWorkerId` is optional, but if provided in preferred-worker mode it must be a valid worker id.

## Supported Property Types
- `apartment`
- `villa`
- `house`
- `office`
- `studio`
- `event_assistance`

## Regular Cleaning Request Shape

### Required fields
- `propertyType`
- `propertyDetails`

### Optional fields
- `propertyDetails.bedrooms`
- `propertyDetails.rooms`
- `propertyDetails.bathrooms`
- `propertyDetails.kitchens`
- `propertyDetails.balconies`
- `propertyDetails.living_room_size`
- `propertyDetails.cleaning_mode`
- `propertyDetails.room_size_breakdown`
- `addressLatitude`
- `addressLongitude`
- `preferredWorkerId`
- `assignmentMode`
- `numberOfWorkers`

### Regular cleaning example
```json
{
  "propertyType": "apartment",
  "propertyDetails": {
    "rooms": 2,
    "bedrooms": 1,
    "bathrooms": 1,
    "kitchens": 1,
    "balconies": 0,
    "living_room_size": "medium",
    "cleaning_mode": "regular"
  },
  "addressLatitude": 33.5138,
  "addressLongitude": 36.2765
}
```

## Room Breakdown Shape

If you send `propertyDetails.room_size_breakdown`, it must be a JSON object with these top-level keys:

- `bedroom`
- `bathroom`
- `kitchen`
- `living_room`
- `balcony`

Each room type must contain:

- `small`
- `medium`
- `large`

### Valid room breakdown example
```json
{
  "propertyType": "apartment",
  "propertyDetails": {
    "room_size_breakdown": {
      "bedroom": { "small": 1, "medium": 0, "large": 0 },
      "bathroom": { "small": 1, "medium": 0, "large": 0 },
      "kitchen": { "small": 1, "medium": 0, "large": 0 },
      "living_room": { "small": 0, "medium": 1, "large": 0 },
      "balcony": { "small": 0, "medium": 0, "large": 0 }
    }
  }
}
```

### Important
- Do not send `room_size_breakdown` as a string or list.
- Do not omit a room group if you include `room_size_breakdown`.
- Do not omit `small`, `medium`, or `large` inside any room group.

## Preferred Worker Mode

If you want the estimate to be priced for a specific worker, send:

- `assignmentMode = "preferred_worker"`
- `preferredWorkerId`
- `numberOfWorkers = 1`
- `addressLatitude`
- `addressLongitude`

### Preferred worker example
```json
{
  "propertyType": "apartment",
  "propertyDetails": {
    "rooms": 2,
    "bedrooms": 1,
    "bathrooms": 1,
    "living_room_size": "small"
  },
  "assignmentMode": "preferred_worker",
  "preferredWorkerId": 44,
  "numberOfWorkers": 1,
  "addressLatitude": 33.5138,
  "addressLongitude": 36.2765
}
```

### Assignment mode rules
- `preferred_worker` implies one worker only.
- `open_count` means the customer wants a team of `numberOfWorkers`.
- If `assignmentMode` is omitted, the backend infers it from `preferredWorkerId` and `numberOfWorkers`.
- `preferredWorkerId` cannot be used with `open_count`.

## Event Assistance Mode

When `propertyType = "event_assistance"`, the request follows the event-assistance contract instead of the regular cleaning shape.

Required fields:
- `propertyDetails.eventType`
- `propertyDetails.guestCount`
- `propertyDetails.venueType`
- `serviceIds`

## Validation Errors

### Missing property details
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "propertyDetails": [
      "The property details field is required."
    ]
  }
}
```

### Invalid room breakdown shape
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "propertyDetails.room_size_breakdown": [
      "The property details room size breakdown field must be an array."
    ]
  }
}
```

### Invalid room bucket keys
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "propertyDetails.room_size_breakdown.bedroom": [
      "The property details room size breakdown bedroom field must be an array."
    ],
    "propertyDetails.room_size_breakdown.bedroom.small": [
      "The property details room size breakdown bedroom small field is required."
    ]
  }
}
```

### Preferred worker mode without worker id
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "preferredWorkerId": [
      "The preferred worker is required for preferred worker mode."
    ]
  }
}
```

### Preferred worker mode with more than one worker
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "numberOfWorkers": [
      "Preferred worker mode only allows one worker."
    ]
  }
}
```

## Notes for Flutter
- Send `room_size_breakdown` only when the UI has collected full per-room size buckets.
- Keep legacy fields like `rooms`, `bedrooms`, `bathrooms`, and `living_room_size` available for the regular flow.
- For preferred worker pricing, include `preferredWorkerId` and location coordinates together.
- If you do not need worker-specific pricing, omit `preferredWorkerId`, `assignmentMode`, and `numberOfWorkers`.
