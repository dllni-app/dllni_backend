<?php

declare(strict_types=1);

namespace Modules\Supermarket\Data;

use Modules\Supermarket\Enums\SmStockOperation;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;

final class SmStockUpdateData extends Data
{
    public function __construct(
        #[Required, IntegerType, Min(0)]
        public int $quantity,

        #[Required, In(['SET', 'INCREMENT', 'DECREMENT'])]
        public SmStockOperation $operation,
    ) {}
}
