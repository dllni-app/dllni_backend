<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Modules\Cleaning\Models\CleaningMaterial;

final class CleaningMaterialLowStockPolicy
{
    public function shouldNotify(
        CleaningMaterial $material,
        float $stockBefore,
        float $stockAfter,
    ): bool {
        $threshold = max(0.0, (float) $material->low_stock_threshold);

        return $stockBefore > $threshold && $stockAfter <= $threshold;
    }
}
