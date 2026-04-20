# API Contract: V1 Deep Links

## Purpose
Production deep-link support for product, restaurant, vote, and group-order links with resolver API, canonical URL routing, analytics, and fallback handling.

## Canonical URLs
- `https://app.dllni.com/product/{id}`
- `https://app.dllni.com/restaurant/{id-or-slug}`
- `https://app.dllni.com/vote/{id}`
- `https://app.dllni.com/group-order/{id-or-slug}`
- `https://app.dllni.com/s/{code}` (short link, resolves to canonical target)

## Resolver Endpoint
- Method: `POST`
- URL: `/api/v1/deep-links/resolve`
- Auth: Optional (`auth:sanctum` token improves private link decisions)

### Request Schema
```json
{
  "url": "https://app.dllni.com/group-order/abcdef1234567890abcdef1234567890"
}
```

### Success Response Schema
```json
{
  "type": "group-order",
  "id": 123,
  "slug": "abcdef1234567890abcdef1234567890",
  "status": "ok",
  "requires_auth": true,
  "canonical_url": "https://app.dllni.com/group-order/abcdef1234567890abcdef1234567890",
  "fallback_url": "https://app.dllni.com/open"
}
```

### Status Values
- `ok`: resolvable and visible
- `not_found`: no matching record
- `forbidden`: hidden/private/inaccessible for current user
- `expired`: time-bound link is no longer active

Notes:
- For private numeric group-order links, `forbidden` now returns `requires_auth=true` to allow Flutter to trigger login flow.
- Product and vote resolver identifiers are currently numeric-only.

### Error Codes
- `422`: request validation failed (`url` missing/invalid)
- `200`: resolver returns business status in body (`ok`, `not_found`, `forbidden`, `expired`)

## Analytics Endpoint
- Method: `POST`
- URL: `/api/v1/deep-links/events`
- Auth: Optional

### Request Schema
```json
{
  "action": "click",
  "url": "https://app.dllni.com/restaurant/al-atrash",
  "source": "whatsapp",
  "medium": "social",
  "campaign": "eid-share",
  "sharer_id": 17,
  "platform": "android"
}
```

### Response
```json
{
  "status": "ok"
}
```

## Web Canonical Routes
- `GET /product/{identifier}`
- `GET /restaurant/{identifier}`
- `GET /vote/{identifier}`
- `GET /group-order/{identifier}`

Behavior:
- Logs click analytics with UTM and device metadata.
- `status=ok`: redirects to configured landing URL with canonical link in query (`deep_link=...`).
- Invalid/forbidden/expired: redirects to safe fallback page.

## Short Links (Optional)
- Route: `GET /s/{code}`
- Backed by `deep_link_short_urls` table.
- Redirects to canonical target if active and not expired/click-exhausted.
- Logs analytics action `short_redirect`.
- Resolver API also supports `/s/{code}` and resolves the short-link target into standard resolver metadata.

## Notes for Flutter Integration
- Resolver `type` + `target` (when present) should drive screen selection (e.g., supermarket product vs restaurant product).
- Use `requires_auth` and `status` to decide immediate navigation vs login/pending-link flow.
- Preserve UTM values when app forwards events to `/api/v1/deep-links/events`.

## Deployment/Config Checklist
- Set config/env values:
  - `DEEP_LINK_CANONICAL_HOST`
  - `DEEP_LINK_CANONICAL_SCHEME`
  - `DEEP_LINK_WEB_LANDING_URL`
  - `DEEP_LINK_STORE_LANDING_URL`
  - `DEEP_LINK_INVALID_FALLBACK_URL`
  - `DEEP_LINK_RESOLVER_CACHE_TTL_SECONDS`
- Run migrations for:
  - `deep_link_events`
  - `deep_link_short_urls`
- Configure Android App Links and iOS Universal Links for `app.dllni.com`.
- Ensure landing URLs are valid and handle `deep_link` query parameter.
