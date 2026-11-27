# Restaurant Food Taxonomy & Menu Sections — Implementation Plan

Date: 2026-11-25 | Status: PLANNED | Module Engineering

Workspace: C:\laragon\www\Dllni


## 1. Scope, decisions and delivery constraints

This is a redesign of the existing Restaurant catalogue and a cross-module integration, not a new generic taxonomy module. Reuse Laravel's existing Resturants module (repository spelling), User module consumers, Filament dashboard, the customer and restaurant owner Flutter apps, and the existing AI meal-search service. BeeOrder screenshots inform menu UX; its internal database architecture is not known.

User confirmed the data are seeded and explicitly authorized correcting source seeds and recreating the existing local QA database with php artisan migrate:fresh --seed. Correct the fixtures, not just current rows. This is not permission to reset a remote/production database or create another database. The present task is a plan only; no code or database changes have been made.

### Decision ledger
| ID | Recommended design | State / alternative |
|---|---|---|
| D01 | Restaurant-owned local Category, platform-owned FoodDiscoveryCategory; root + optional child; one primary menu section per product | Proposed; approval gate before implementation. Forced global menus reduce flexibility; unrestricted nesting/multi-membership adds complexity. |
| D02 | Independent food family, item_type, product_kind, catalog_role, servings; sandwich may be main dish but not necessarily a complete meal | Proposed; preserve existing fields rather than creating parallel enum. |
| D03 | Local orderability independent of editorial review; reviewed metadata required for curated meals; semantic changes invalidate review; admin approves | Proposed; preserve existing policy direction, no AI auto-approval. |
| D04 | Integrate existing product voting and group order; category-level vote options as optional future extension | Deferred unless expressly approved; changes social lifecycle/public contract. |
| D05 | Lucky Box single merchant, subtotal budget, known serving coverage, no auto-order; fees separate | Proposed; all-in delivery budget or multi-merchant needs separate approval. |
| D06 | Fix seed source, verified local fresh --seed | Authorized; runtime safety check pending. |

D01–D03 require explicit agreement before affected changes; this plan proposes a concrete implementation baseline. Scope excludes payment redesign, supermarket/cleaning taxonomy, new recommendation microservice, unverified medical/dietary claims, and production deployment. Do not copy BeeOrder prices or photos into seed.

## 2. Verified source baseline (Rider)

Backend paths in the table are relative to Dllni_backend/. Lines are navigation aids, refresh before changes.

| ID | Existing code | Evidence / gap |
|---|---|---|
| E01 | Modules/Resturants/app/Models/Category.php L22–42 | Flat merchant section; no parent. |
| E02 | Modules/Resturants/database/migrations/2026_11_11_220001_add_food_discovery_taxonomy.php; app/Models/Product.php L27–90; FoodDiscoveryCategory.php | Existing canonical fields and family; reuse. |
| E03 | Modules/Resturants/app/Http/Requests/ProductRequest.php L26–50; Data/ProductData.php; Services/ProductService.php L17–69 | API accepts explicit itemType and kind; some semantic edits reset review, audit null vs omitted. |
| E04 | Modules/User/app/Services/RestaurantFoodEligibility.php; Product.php scopes | Existing browse/curated policy not consistently applied. |
| E05 | Modules/User/app/Services/RestaurantV2CatalogService.php L384–419; Http/Controllers/API/UserRestaurantMenuSectionsController.php L21–93 | V2 flat paged menu; legacy loads all category products, filters raw availability. |
| E06 | Modules/User/app/Services/RestaurantProductTypeResolver.php L9–78; RestaurantSmartSearchService.php L317–324 | Text fallback first-match can confuse a meal containing sandwich with sandwich. |
| E07 | Modules/User/app/Services/RestaurantLuckBoxService.php L52–199, L204–218, L264–348 | Raw stock/availability; negative keyword restrictions; qty initially 1; balanced by local category_id, not serving coverage. |
| E08 | Modules/User/app/Services/RestaurantGroupVoteService.php L362–401; RestaurantGroupOrderService.php | Vote suggestions use text/cuisine/raw availability; transactional group order, audit complete write flow before edits. |
| E09 | Modules/User/app/Services/RestaurantPopularMealRanker.php; RestaurantMealComplements.php | Existing completed_units_30d ranking and complements reusable. |
| E10 | Modules/Resturants/app/Jobs/SyncRestaurantMealIndex.php L29–56; alnadha-ai-main/services/meal-search/app/models.py | Index carries item_type/local category; global family/kind indirectly in tags. |
| E11 | app/Filament/Resources/RestaurantFoodCategories/RestaurantFoodCategoryResource.php; RestaurantProducts/RestaurantProductResource.php and Pages/EditRestaurantProductClassification.php | Existing global category/review; slug immutable; deletion disabled. |
| E12 | dllni_resturant_owner_app/lib/features/products/view/screens/add_product_details_screen.dart L64–67, L590–698 | Already sends local category, family, kind and servings; presentation selection needs completion. |
| E13 | dllni-user-app/lib/features/restaurant_v2/presentation/screens/restaurant_v2_merchant.dart L38–40, L431–461 | Scroll keys exist, rail selected:false. |
| E14 | database/seeders/DatabaseSeeder.php L29–54, L62–97; Modules/Resturants/database/seeders/RestaurantSeeder.php near L246 | Default nonproduction demo seed invokes restaurant catalog; final SYP normalization. |
| E15 | Modules/Resturants/database/seeders/RestaurantMenuCatalogSeeder.php L117–195, L218–241; restaurant-menu-catalog.json | Positional rows derive kind from itemType and family from local sectionKey; mark active items reviewed/independent. |
| E16 | Modules/Resturants/database/seeders/FoodDiscoveryCategorySeeder.php; RestaurantFoodQaClassificationSeeder.php L21–85 | 11 stable family slugs; QA repair has hard-coded 84 rows and fixed IDs. |
| E17 | Modules/Resturants/app/Http/Controllers/API/CategoryController.php L25–77; Services/CategoryService.php | Owner-scoped CRUD; direct delete. |
| E18 | Modules/User/routes/restaurant_v2.php L20–37; Modules/User/routes/api.php L187–197, L329–330; Modules/Resturants/routes/api.php L151–160 | Extend existing endpoints. |

Git status observed: backend dev has unrelated dirty cleaning changes and QA artifacts; customer dev has untracked screenshot; owner dev and AI main were clean. Preserve unrelated work. Local DB reset also affects other modules' test data. No runtime DB verification yet.

## 3. Domain contract and invariants

| Axis | Existing or proposed storage | Target |
|---|---|---|
| Local section | categories.id / products.category_id; add parent_id, proposed is_active | Two levels, one primary section, independent of food family. |
| Family | food_discovery_categories, primary_food_category_id | Stable ID/slug, one primary family, Arabic/English aliases. Cuisine belongs to restaurant separately. |
| Presentation | item_type | meal,sandwich,burger,pizza,dish,combo,family_meal,appetizer,side,salad,dessert,drink,breakfast,other. Explicit group/strict matching. |
| Role | product_kind | main_dish,combo_meal,side_dish,beverage,dessert,standalone_sauce,other. |
| Orderability | catalog_role | independent vs modifier_only; use actual ModifierGroup/Modifier for selectable extras. |
| Serving count | servings nullable | Known positive integer; family >=2 when known; null unknown (not one). |
| Included contents | proposed nullable included_components JSON if existing model inadequate | Controlled side/beverage/dessert/sauce/etc; included in base price, not optional paid modifiers. Null unknown, [] explicitly none. |
| Review | classification_status, classified_at | unreviewed/reviewed; audit, only authorized review. |
| Dietary | existing ingredient_exclusions and verified structured evidence only | No false allergy-free claims; unknown excludes from strict verified filter. |

Illustrative examples (not live data):
| Product | Local section | Family | Presentation | Kind | Servings |
|---|---|---|---|---|---|
| ساندويش شاورما دجاج | شاورما / ساندويشات | shawarma | sandwich | main_dish | 1 |
| وجبة شاورما عربي | شاورما / وجبات | shawarma | meal | combo_meal | 1 |
| شاورما عائلية | عروض المشاركة | shawarma | family_meal | combo_meal | 4 if known |
| برغر دجاج | ساندويش غربي | burger | burger | main_dish | 1 |
| وجبة برغر مع بطاطا ومشروب | وجبات | burger | combo | combo_meal | 1 |
| ساندويش بطاطا | بطاطا / ساندويشات | potato | sandwich | main_dish if sold as main | 1 |
| صحن بطاطا | إضافات | potato | side | side_dish | optional |
| عبوة ثوم | إضافات | sides | side | standalone_sauce | unknown |

- I01: Scope every product/category/parent/modifier to authenticated merchant; one commerce identity, no duplicate products for collections.
- I02: No self-parent, cycle, third level, foreign tenant or cascading product deletion. Stable sibling order; inactive parent hides descendants.
- I03: Local rename/move/reorder does not change family/review; semantic change resets approval, price-only does not.
- I04: Reviewed main/combo requires active global family and valid explicit type; modifier-only never standalone.
- I05: Browse can include unreviewed valid independent food, curated requires reviewed metadata. Separate display eligibility from order eligibility.
- I06: Live stock, merchant eligibility, effective price and required modifiers checked on apply/checkout; suggestions aren't reservations.
- I07: Lucky Box covers known servings within subtotal, not sauces-only; no false dietary guarantee or duplicate included components.
- I08: Vote/order history stable; stale winner not automatically purchased; preserve permissions, expiry and realtime refresh.
- I09: Search index/cache is projection; DB remains truth.
- I10: Default fresh seed consistent, no manual patch, no hard-coded IDs, other local modules still function.

## 4. Shared API target (additive, not current behavior)

Extend existing V2 GET {restaurant}/menu and legacy GET /api/v1/user/restaurants/{restaurant}/menu-sections. Preserve IDs/legacy keys; add parentId, depth, effectiveActive and stable sectionKey=category:<id>. Legacy keeps flat product-bearing sections; V2 tree or ordered flat parentId projection. Explicit direct vs subtree counts; page by scoped category ID; no duplicate child count, bounded queries. Dynamic collection key popular is not category FK. Hide empty sections, retain ancestors with visible children.

Product cards add itemType, productKind, isIndependentlyOrderable, foodCategory{id,slug,name}, servings, includedComponents; owner private review fields. Preserve categoryId, currency, prices, discounts, favorites. Filter foodCategoryId/slug + itemTypes (OR within axis, AND across); cuisine separate. Exact meal vs sandwich and similar alternatives separated. Owner category CRUD adds parentId, sortOrder, optional isActive; transactional reorder if absent. Preserve omitted vs explicit null; no owner review flags.

Luck-box/options adds food categories and modes; suggest accepts optional family/type/mode along with current groupSize, budgetPerPerson, cuisine, restaurant, coordinates. Return current bundle fields + coverage, requiresConfiguration, includedComponents, priceBasis=food_subtotal, no-match reason. Vote suggestions accepts optional family/type filter; product IDs and existing aliases preserved. Group menu shares policy and section model. Use route:list and actual DTOs before adding fields.

## 5. Delivery sequence

WP00 preflight → WP01 schema/rules → WP02 seed correction + guarded fresh → WP03 shared API → WP04 admin / WP05 owner / WP06 customer → WP07 Lucky Box / WP08 social / WP09 search/index → WP10 integrated QA → WP11 docs and closure. Focused tests in every WP. Proposed NEW files below are not existing files. All checkboxes outstanding.
