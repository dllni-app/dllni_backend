# API Contract: V1 Deep Links

## Purpose
Deep-link APIs for resolving links, tracking deep-link analytics events, and opening API deep links via redirect.

## Base Path
- `/api/v1/deep-links`

## Authentication
- All deep-link endpoints are public.
- `auth:sanctum` token is optional on `resolve` and `events` and can improve private link decisions.

## 1) Resolve Deep Link
- Method: `POST`
- URL: `/api/v1/deep-links/resolve`
- Controller: `ResolveDeepLinkController`

### Request Body
```json
{
  "url": "https://dllni.mustafafares.com/group-order/abcdef1234567890abcdef1234567890"
}
```

### Validation
- `url`: required, string, max 2048

### Success Response (HTTP 200)
```json
{
  "type": "group-order",
  "id": 123,
  "slug": "abcdef1234567890abcdef1234567890",
  "status": "ok",
  "requires_auth": true,
  "canonical_url": "https://dllni.mustafafares.com/group-order/abcdef1234567890abcdef1234567890",
  "fallback_url": "https://dllni.mustafafares.com/open",
  "query": {
    "source": "whatsapp"
  }
}
```

### Business Status Values (in response body)
- `ok`: resolvable and accessible
- `not_found`: no matching resource
- `forbidden`: resource exists but inaccessible
- `expired`: time-bound resource no longer active

### Error Responses
- `422 Unprocessable Entity`: validation error

### Supported Deep-Link Shapes
Canonical paths:
- `/product/{identifier}`
- `/restaurant/{identifier}`
- `/store/{identifier}`
- `/vote/{identifier}`
- `/group-order/{identifier}`

API-shaped paths normalized by resolver:
- `/api/v1/user/products/{id}` => `/product/{id}`
- `/api/v1/user/supermarket/products/{id}` => `/product/{id}`
- `/api/v1/user/supermarket/stores/{id}` => `/store/{id}`
- `/api/v1/user/restaurants/{id-or-slug}` => `/restaurant/{id-or-slug}`
- `/api/v1/user/restaurants/votes/{id}` => `/vote/{id}`
- `/api/v1/user/restaurants/group-orders/{id-or-token}` => `/group-order/{id-or-token}`

## 2) Track Deep-Link Event
- Method: `POST`
- URL: `/api/v1/deep-links/events`
- Controller: `TrackDeepLinkEventController`

### Request Body
```json
{
  "action": "click",
  "url": "https://dllni.mustafafares.com/restaurant/al-atrash",
  "source": "whatsapp",
  "medium": "social",
  "campaign": "eid-share",
  "sharer_id": 17,
  "platform": "android"
}
```

### Validation
- `action`: required, string, one of `open|click|resolve`
- `url`: nullable, string, max 2048
- `source`: nullable, string, max 100
- `medium`: nullable, string, max 100
- `campaign`: nullable, string, max 100
- `sharer_id`: nullable, integer, min 1
- `platform`: nullable, string, max 50

### Success Response (HTTP 200)
```json
{
  "status": "ok"
}
```

### Behavior Notes
- If `url` is present, backend resolves it first and stores resolution metadata with the event.
- UTM-like fields may be taken from query string and/or body.
- Platform can come from `X-Platform` header or body `platform`.

## 3) Open Deep Link (API Redirect Endpoint)
- Method: `GET`
- URL: `/api/v1/deep-links/{type}/{identifier}`
- Controller: `OpenDeepLinkController`

### Path Parameters
- `type`: one of `product|restaurant|store|vote|group-order`
- `identifier`: regex `[A-Za-z0-9\-_.~%]+`

### Query Parameters (optional)
- `source`
- `medium`
- `campaign`
- `sharer_id`

### Success Behavior
- Returns HTTP `302` redirect.
- If resource resolves with `status=ok`, redirects to configured web landing URL with query:
  - `deep_link={canonical_url}`
  - forwards optional `source`, `medium`, `campaign`, `sharer_id`
  - `store_url={configured_store_landing_url}`

Example redirect target:
```text
https://dllni.mustafafares.com/open?deep_link=https%3A%2F%2Fdllni.mustafafares.com%2Fproduct%2F123&source=whatsapp
```

### Invalid/Forbidden/Expired Behavior
- Returns HTTP `302` redirect to configured invalid fallback URL:
```text
{DEEP_LINK_INVALID_FALLBACK_URL}?reason={status}
```

## Canonical URL Generation
Canonical URLs are generated using:
- `DEEP_LINK_CANONICAL_SCHEME` (default: `https`)
- `DEEP_LINK_CANONICAL_HOST` (default: `dllni.mustafafares.com`)

## Config Checklist
- `DEEP_LINK_CANONICAL_HOST`
- `DEEP_LINK_CANONICAL_SCHEME`
- `DEEP_LINK_WEB_LANDING_URL`
- `DEEP_LINK_STORE_LANDING_URL`
- `DEEP_LINK_INVALID_FALLBACK_URL`
- `DEEP_LINK_RESOLVER_CACHE_TTL_SECONDS`

## Data Dependencies
- `deep_link_events` table
- `deep_link_short_urls` table (for short-link flows via `/s/{code}` in web routes)

## Notes for Clients
- Use `status` + `requires_auth` from `/resolve` for navigation decisions.
- Treat `/events` as fire-and-forget analytics endpoint.
- Use `/api/v1/deep-links/{type}/{identifier}` for server-driven redirect/open flow.
