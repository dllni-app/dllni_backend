<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class WorkerCompliancePilotEnrollmentService
{
    /**
     * This only allows the worker to see the consent notice. It must not
     * start device location collection or bypass the device-side consent.
     */
    public function setEnrollment(Worker $worker, User $actor, bool $enrolled): void
    {
        abort_unless($actor->can('cleaning.worker_compliance.configure'), 403);

        if ($enrolled && (! $worker->is_active || $worker->is_suspended)) {
            throw ValidationException::withMessages([
                'worker' => 'لا يمكن إدراج عامل غير نشط أو موقوف في تجربة الموقع.',
            ]);
        }

        DB::transaction(function () use ($worker, $enrolled): void {
            $state = DB::table('worker_compliance_states')
                ->where('worker_id', $worker->id)
                ->lockForUpdate()
                ->first();

            if ($state !== null && (bool) $state->is_enrolled === $enrolled) {
                return;
            }

            // Revocation also clears the access-token-bound lease. Any
            // in-flight mobile samples will be rejected by the API.
            $attributes = [
                'is_enrolled' => $enrolled,
                'installation_hash' => null,
                'access_token_hash' => null,
                'epoch' => null,
                'lease_expires_at' => null,
                'revoked_at' => now(),
                'updated_at' => now(),
            ];

            if ($state === null) {
                DB::table('worker_compliance_states')->insert([
                    'worker_id' => $worker->id,
                    ...$attributes,
                    'created_at' => now(),
                ]);
                return;
            }

            DB::table('worker_compliance_states')
                ->where('worker_id', $worker->id)
                ->update($attributes);
        });
    }

    public function isEnrolled(Worker $worker): bool
    {
        return (bool) DB::table('worker_compliance_states')
            ->where('worker_id', $worker->id)
            ->value('is_enrolled');
    }
}
