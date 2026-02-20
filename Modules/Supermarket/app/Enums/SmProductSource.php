<?php

declare(strict_types=1);

namespace Modules\Supermarket\Enums;

enum SmProductSource: string
{
    case BarcodeScan = 'barcode_scan';
    case CatalogSearch = 'catalog_search';
    case Manual = 'manual';
    case Template = 'template';
    case BulkImport = 'bulk_import';
}
