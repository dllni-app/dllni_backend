<?php

declare(strict_types=1);

namespace Modules\Supermarket\Data;

use Spatie\LaravelData\Attributes\Validation\ArrayType;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

final class SmInventoryAuditData extends Data
{
    public function __construct(
        /** @var DataCollection<SmInventoryAuditProductData> */
        #[Required, ArrayType]
        public DataCollection $products,
    ) {}
}

final class SmInventoryAuditProductData extends Data
{
    public function __construct(
        #[Required, IntegerType]
        public int $product_id,

        #[Required, IntegerType, Min(0)]
        public int $actual_stock,
    ) {}
}
