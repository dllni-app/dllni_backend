# Phase 1 — Implementation task register and design review gate
Created 2026-11-05. Phase 1 produces reviewable wireframes/prototype/specification; integration belongs to Phases 2–7. Each task lists owner, modification, reusable UI, navigation, state, backend dependency, risk, test, acceptance, and relative complexity.

| Task | Owner | Change and reusable UI | Navigation and state effects | Backend dependency | Risk | Test / acceptance | Size |
|---|---|---|---|---|---|---|---|
| P1-01 | `rs_home` | Commerce header, location, search, category rail, merchant cards | back preserves homepage; partial failures isolated | existing home sections; future aggregation | shared theme regression | review 360/390/430, loading/empty | M |
| P1-02 | `rs_discover` | search scope, filters, result cards | restore query/filter/scroll, generation reject stale | search pagination | stale results | rapid query/scope and back tests | L |
| P1-03 | `rs_discover` merchant/product | menu categories, product rows, modifier editor | merchant cart bar; customization signatures | merchant/product availability | incorrect quick add | required modifier tests | L |
| P1-04 | `rs_offers`, `rs_favourite` | unified offer/favorite cards | preserve bottom destinations | offer and favorite resources | fabricated pricing | unavailable offers hidden | M |
| P1-05 | `sm_home`, `sm_discover` | green category rail, store cards, two-scope search | store results preserved on back | store search API | store-location mismatch | search/pagination/empty | L |
| P1-06 | `sm_stores` | category grid, stock-aware product tiles, alternatives | retain store-scoped selection | stock/modifier/alternative rules | wrong substitution | explicit alternative confirmation | L |
| P1-07 | `sm_offers`, `sm_favorite` | reusable offer and favorite tiles | existing 4 tabs preserved | offers/favorites API | stale offers | actual offers only | M |
| P1-08 | commerce cart store + current cart UIs | cart line, quantity, authoritative total | merchant-specific carts, no cross-store merge | expected revision + immutable signature | lost customization | conflict + quantity concurrency | XL |
| P1-09 | checkout Cubit and current checkout routes | single page, checkout cards, amount breakdown | auth/address return restores draft | quote+idempotency+recovery | duplicate order | timeout, changed quote, coupon conflict | XL |
| P1-10 | order details/tracking | order status card, courier timeline | pickup excludes courier map | actual statuses, timestamps | misrepresented delivery | terminal and pickup timelines | L |
| P1-11 | existing group/vote/lucky features | scoped components with preserved destinations | secondary navigation stays reachable | existing group and lucky APIs | hidden feature regression | group/vote/lucky E2E | L |
| P1-12 | supermarket comparison/lists/reorder | reusable product rows and diff review | store cart target explicit | comparison/lists/reorder preview | silent stock substitution | compare, list, reorder cases | L |
| P1-13 | Backend Laravel/Resturants/User/Supermarket | resource/version proposal and migrations | error recovery mapped to client state | minor-unit money, revisions, quotes, receipts, snapshots | historical migration ambiguity | schema review + contract tests before implementation | XL |
| P1-14 | shared commerce design | Cairo RTL tokens + component variants | no global/cleaning mutation | none | cross-domain CSS/theme | accessibility, screen scale, RTL | M |
| P1-15 | QA and design team | Pen/Figma prototype + screen coverage | each ID cross-linked to source | none | incomplete source recordings | design review sign-off | M |

## Current review artifacts
- Local prototype: `docs/ux-research/commerce-phase1-prototype.html` (browser open via file:///C:/laragon/www/Dllni/Dllni_backend/docs/ux-research/commerce-phase1-prototype.html).
- Editable Figma: https://www.figma.com/design/5O7PTrnorYwWyejL7iEz9k (35 screen wireframes + one foundations frame).
- Screen/state source mapping: `PHASE1_SCREEN_STATE_COVERAGE.md`.
- Tokens/components: `PHASE1_COMPONENT_SPECIFICATIONS.md`.
- Flows/API/database proposals: `PHASE1_USER_FLOWS_AND_CONTRACTS.md`.
- Existing Pen source remains intact.

## Review acceptance and limitations
Present artifacts for review before Phase 2/3 implementation. Browser prototype simulates interactions with fixed illustrative prices and cannot verify real backend reliability. Figma screen boards are editable structural wireframes, not full-fidelity replicas with every variant or complete secondary-flow inner pages. The nine Bee Order recordings were not found in inspected workspace; timestamped source comparison cannot be marked complete. Final pixel-level screens and reviewed variant approvals remain outstanding for Phase 1 gate.
