<?php

declare(strict_types=1);

namespace Modules\Delivery\Support;

use App\Enums\DisputeStatus;
use App\Models\Dispute;

trait ResolvesDisputeStatus
{
    private function resolveDisputeStatus(mixed $value): ?DisputeStatus
    {
        if ($value instanceof DisputeStatus) {
            return $value;
        }

        if (is_string($value)) {
            return DisputeStatus::tryFrom($value);
        }

        if (is_object($value) && property_exists($value, 'value') && is_string($value->value)) {
            return DisputeStatus::tryFrom($value->value);
        }

        return null;
    }

    private function resolvePreviousDisputeStatus(Dispute $dispute): ?DisputeStatus
    {
        $previousAttributes = method_exists($dispute, 'getPrevious') ? $dispute->getPrevious() : [];

        return $this->resolveDisputeStatus($previousAttributes['status'] ?? $dispute->getOriginal('status'));
    }
}
