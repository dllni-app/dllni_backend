<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use DomainException;

/**
 * Capacity validation fails closed: infeasible schedules are 422, while
 * unsupported scheduling rules or an exhausted search budget are 503.
 */
final class CleaningV3CapacityException extends DomainException
{
    public function __construct(
        public readonly string $reasonCode,
        public readonly int $httpStatus = 422,
        public readonly string $field = 'tasks',
    ) {
        parent::__construct($reasonCode);
    }
}
