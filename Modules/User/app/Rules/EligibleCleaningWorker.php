<?php

declare(strict_types=1);

namespace Modules\User\Rules;

use App\Models\Worker;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Modules\Cleaning\Services\DepositService;

final class EligibleCleaningWorker implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value) || (int) $value <= 0) {
            return;
        }

        $worker = Worker::query()
            ->with(['user', 'deposit'])
            ->find((int) $value);

        if (
            ! $worker instanceof Worker
            || $worker->user === null
            || ! (bool) $worker->user->is_active
            || ! app(DepositService::class)->isWorkerEligibleForDispatch($worker)
        ) {
            $fail('Selected worker cannot receive new cleaning requests.');
        }
    }
}
