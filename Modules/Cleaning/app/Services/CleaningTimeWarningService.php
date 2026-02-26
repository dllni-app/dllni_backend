<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningTimeWarningResponse;
use Modules\Cleaning\Models\CleaningTimeWarning;

final class CleaningTimeWarningService
{
    public function accept(CleaningTimeWarning $warning, ?int $additionalMinutes = null): CleaningTimeWarning
    {
        return DB::transaction(static function () use ($warning, $additionalMinutes): CleaningTimeWarning {
            if ($warning->worker_responded_at !== null) {
                throw new InvalidArgumentException('Extension request has already been responded to.');
            }

            $warning->update([
                'worker_response' => CleaningTimeWarningResponse::ExtendTime,
                'worker_responded_at' => now(),
                'additional_minutes' => $additionalMinutes,
            ]);

            return $warning->fresh();
        });
    }

    public function reject(CleaningTimeWarning $warning, ?string $message = null): CleaningTimeWarning
    {
        return DB::transaction(static function () use ($warning, $message) {
            if ($warning->worker_responded_at !== null) {
                throw new InvalidArgumentException('Extension request has already been responded to.');
            }

            $warning->update([
                'worker_response' => CleaningTimeWarningResponse::CommitCurrentTime,
                'worker_responded_at' => now(),
                'worker_reject_message' => $message,
            ]);

            return $warning->fresh();
        });
    }
}
