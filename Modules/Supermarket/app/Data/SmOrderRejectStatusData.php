<?php

declare(strict_types=1);

namespace Modules\Supermarket\Data;

use Spatie\LaravelData\Data;

final class SmOrderRejectStatusData extends Data
{
    public function __construct(
        public string $reason,
        public string $rejectionType,
    ) {}
}
