<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use RuntimeException;

final class CleaningV3ClockTransitionException extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        public readonly int $httpStatus = 409,
    ) {
        parent::__construct($reasonCode);
    }
}
