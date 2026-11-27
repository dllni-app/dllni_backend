<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

/** Release evidence boundary; independent from booking-specific funding. */
interface CleaningV3ReleaseReadiness
{
    public function audit(): array;
}
