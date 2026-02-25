<?php

declare(strict_types=1);

namespace Modules\Supermarket\Data;

use Spatie\LaravelData\Attributes\Validation\ArrayType;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

final class SmOrderReturnData extends Data
{
    public function __construct(
        /** @var DataCollection<SmOrderReturnItemData> */
        #[Required, ArrayType]
        public DataCollection $items,

        #[Required, StringType]
        public string $reason,
    ) {}
}

final class SmOrderReturnItemData extends Data
{
    public function __construct(
        #[Required, IntegerType]
        public int $order_item_id,

        #[Required, IntegerType, Min(1)]
        public int $quantity,
    ) {}
}
