# Supermarket demo catalog

## Local development use

The standard `DatabaseSeeder` continues to include `SupermarketDatabaseSeeder`.
For an existing **local demo database**, refresh only the catalog (known demo stores,
categories, products, master links and promotions):

```powershell
php artisan db:seed --class='Modules\Supermarket\Database\Seeders\SmDemoCatalogSeeder'
```

This targeted seeder deliberately does not recreate order history, coupons,
commission rules or run the global price normalizer. It does refresh seed-owned
catalog prices, stock, offer dates and store metadata. The dedicated owner demo
store is included when its existing seller-supermarket-* fixture is present.
Do not run it against production or merchant-owned data.

The versioned `supermarket-catalog.json` contains 84 new SKUs across 23
departments. Legacy product names and barcode ordering remain intact. Product
variants are separate master identities; the 6 x 500 ml water pack has a
3,000 ml package quantity. A subset of products has differentiated prices for
cross-store comparison.

Original artwork lives in `public/seed-media/supermarket`: 177 product-specific
illustrated packshots and eight store branding files. It is local, unbranded,
and does not depend on remote image services. Regenerate only when artwork
changes:

```powershell
php Modules/Supermarket/database/seeders/generate-supermarket-images.php
```

The media integration uses the existing Spatie collections, checks file
dimensions and checksum, and replaces recognized seed placeholders without
removing genuine merchant uploads.

## Promotion fixtures

Each demo store receives twelve offers: eight active, two scheduled, one
expired, one paused. The fixture exercises percent/value discounts, 100%
discounts, same-product, cheapest-eligible and separate-product rewards,
partial rewards, repeatability, caps, and scheduled visibility. Offer product
and reward links are restricted to their own store.

## Execution evidence (2026-11-27)

- Fresh migration and complete supermarket module seed in isolated local MySQL
  schema `alnadha_supermarket_seed_qa_20261127`; the worker QA database was not
  altered. The seller store in this isolated schema was created with a test
  fixture. QA media files were isolated in `storage/app/supermarket-seed-qa-20261127`
  with a process-local public-disk override; the standard server configuration
  remains unchanged.
- Actual counts: 4 stores, 92 categories, 433 products (336 new listings),
  48 offers, 72 qualifying product links, 8 reward links, 12 coupons, 45 orders,
  135 order items. All 433 product originals verified against their source hash;
  177 distinct images, zero missing master links and zero cross-store offer links.
- Original images were seeded without bulk derivative generation in the QA run
  for runtime efficiency; all three standard image conversions were generated
  and checked on a representative product.
- MySQL customer store-details endpoint: HTTP 200; 23 categories, 81 preview
  products with images, maximum 4 per category, eight active offers. The existing
  per-category preview regression was repaired without changing the API shape.
- Catalog-only reseed on MySQL: unchanged row counts, including 544 media rows;
  all 45 order records unchanged.
- Backend: 21 passed, 1,629 assertions. Customer Flutter: 15 passed. Supermarket
  owner Flutter: all six existing tests passed.
- Native visual acceptance remains unverified: no connected ADB emulator.
  No production deployment or branch push was performed.
