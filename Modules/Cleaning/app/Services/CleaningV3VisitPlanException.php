<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use DomainException;

/**
 * A stable, client-safe field error for V3 draft planning.
 * No financial or persistent side effects are permitted.
 */
final class CleaningV3VisitPlanException extends DomainException
{
    public function __construct(
        public readonly string $field,
        public readonly string $reasonCode,
    ) {
        parent::__construct($reasonCode);
    }
}
