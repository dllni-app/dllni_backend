# Phase 1 — Commerce component and token contract
Date: 2026-11-05. Implementation target: Flutter with existing Cairo fonts and current BLoC/Cubit architecture. Components scoped to REST/SM; never alter cleaning/global theme.

## Tokens
| Token | Value | Role |
|---|---|---|
| action/primary | #172554 | checkout, confirmation, nav actions |
| accent/rest | #A63C66 | restaurant selection and chips |
| accent/sm | #168A67 | supermarket selection and chips |
| bg/page | #F6F7F9 | backdrop |
| surface | #FFFFFF | merchant/product cards |
| text/primary | #182232 | body/headings |
| text/secondary | #697386 | metadata only |
| border/subtle | #E0E5EB | separation |
| type | Cairo regular/medium/bold/extra bold | RTL typography |
| spacing | 4,8,12,16,20,24,32 dp | baseline grid |
| radius/card/control/sheet | 16/12/24 dp | shapes |
| minTouch/minCTA | 48/52 dp | accessible action sizes |
| motion | 150–220ms | selection feedback; disable when reduced motion |

**Money:** show Western digits, zero = 0, omit trailing `.00`, show two decimals for fractional SYP. Never treat missing quote as free delivery. A prior price must be backend-supported.

## Reusable components: anatomy, inputs, callbacks, state and accessibility
| Component | Anatomy / tokens | Inputs | Callback / interaction | Loading, error, disabled / accessibility |
|---|---|---|---|---|
| CommerceHeader | surface + Cairo title + back + location + cart badge | title, section, location, count | onBack, onLocation, onCart | restore previous route, RTL back semantics, minimum 48dp |
| LocationSelector | pin, two-line label, chevron | address summary, permission state | onSelect | no address = select prompt; accessibility full address |
| SearchField | 52dp control, icon, text, clear | value, hint, scope | onChange, onSubmit, onClear | 350ms debounce at repository edge; preserve query; semantic search label |
| SearchScope | paired segmented controls | scope, search counts | onScopeSelected | selected state text+color; preserve independent list positions |
| CategoryRail | horizontally scrolling short category pills | categories, selected id | onSelect | network fail preserves cached list; each chip 48dp |
| FilterChipSheet | chips + grouped bottom sheet + Apply/reset | supported filters, provisional values | onApply, onReset, onDismiss | closing without apply discards draft; reset retains query; focus trapped |
| MerchantCard | image, name, real rating, availability, fulfillment | merchant DTO, location | onOpen, onFavorite | fallback image, unknown ETA labeled unavailable; full-card semantic name |
| OfferCard | image, merchant, factual terms | server offer DTO | onOpen | expired offer hidden; no fabricated discounts |
| RestaurantProductRow | 96dp image, name, detail, price, Add | product, required options | onOpen, onAdd | quick-add only when no required choices; unavailable disabled with reason |
| SupermarketProductTile | square contain image, unit, name, price | item, stock, unit | onOpen, onQuantity | 2 columns; 1 for scale 2.0; stock-bound action |
| Gallery | food 4:3 / packshot 1:1 | media list, fallback | onImageSwipe | no swipe for one image; descriptive alt label |
| PriceBlock | current strong price, optional struck previous | minor units, currency, previous price | none | unknown amount explicitly pending |
| ModifierGroup | label, required marker, radio/checkbox, min/max | options, selected ids, rules | onChange | accessible group name; inline missing/invalid selection |
| QuantityControl | 48dp minus, value, plus | qty, bounds, pending | onIncrement, onDecrement | rollback/reconcile after failure; announce changes |
| ProductFooter | fixed safe-area navy action, total | amount, eligible, qty | onAdd | show explanation for disabled; prevent duplicate tap |
| MerchantCartBar | fixed navy count/subtotal | section, merchant, total | onOpen | retains confirmed count on failure; does not combine stores |
| CartLine | image, purchase config, price, edit, qty, remove | immutable line config and signature | onEdit, onSetQuantity, onRemove | pending sync + stale conflict |
| CheckoutCard | heading, selected value, edit | section enum, selection | onEdit | preserve draft across quote refresh |
| AmountBreakdown | subtotal, discounts, merchant charges, delivery, payable | server quote, currency | onRefreshQuote | stale quote never newly confirmed; unavailable not zero |
| OrderStatusCard | status, merchant, eligible actions | order DTO and policy actions | onAction | independent per-action loader; last known data persists |
| TrackingTimeline | merchant and courier events separately | actual events, timestamps, fulfillment | onRetry | terminal stops polling; no courier map for pickup |
| FeedbackStates | inline error, skeleton, snack confirmation | error code, retry action | onRetry | failed subregion retries alone; live announcement for errors |

## RTL and responsive checklist
Test 360 / 390 / 430dp, text scale 1.0 / 1.3 / 2.0, logical chevrons, currency alignment, keyboard focus, screen reader labels, 48dp tap surfaces, reduced motion, product units and mixed Arabic/Latin text. Food uses cover/crop and supermarket packshots use contain. Do not assume API imagery exists: show stable fallback.

## UI ownership
Restaurant accent applies only `rs_*` and shared commerce instances opened in restaurant context. Supermarket accent only `sm_*` and shared commerce instances opened in supermarket context. The primary CTA stays navy. Source components should be reused instead of forking new global widgets.
