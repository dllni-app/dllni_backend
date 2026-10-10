<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Models\CleaningSpecialService;
use Modules\Cleaning\Models\CleaningSpecialServiceEquipment;

final class CleaningSpecialistAuthorizationService
{
    public function qualified(Worker $worker, CleaningSpecialService $service): bool
    {
        return DB::table('cleaning_worker_special_service_skills')
            ->where('cleaning_special_service_id', $service->id)
            ->where('worker_id', $worker->id)
            ->where('is_active', true)
            ->whereNotNull('approved_at')
            ->exists();
    }

    public function authorizedForEquipment(Worker $worker, CleaningSpecialServiceEquipment $equipment): bool
    {
        return DB::table('cleaning_worker_equipment_authorizations')
            ->where('cleaning_special_service_equipment_id', $equipment->id)
            ->where('worker_id', $worker->id)
            ->where('is_active', true)
            ->whereNotNull('approved_at')
            ->exists();
    }

    public function allowed(Worker $worker, CleaningSpecialService $service): bool
    {
        if (! (bool) $service->is_active
            || (filled($service->gender_constraint) && (string) $service->gender_constraint !== (string) $worker->gender)
            || ! $this->qualified($worker, $service)) {
            return false;
        }

        foreach ($service->equipment as $equipment) {
            if (! (bool) $equipment->is_active || ! $this->authorizedForEquipment($worker, $equipment)) {
                return false;
            }
        }

        return true;
    }

    public function assertAllowed(Worker $worker, CleaningSpecialService $service): void
    {
        if (! (bool) $service->is_active
            || (filled($service->gender_constraint) && (string) $service->gender_constraint !== (string) $worker->gender)
            || ! $this->qualified($worker, $service)) {
            throw ValidationException::withMessages(['worker' => ['An active, approved specialist qualification matching service constraints is required.']]);
        }

        $service->loadMissing('equipment');
        foreach ($service->equipment as $equipment) {
            if (! (bool) $equipment->is_active || ! $this->authorizedForEquipment($worker, $equipment)) {
                throw ValidationException::withMessages(['equipment' => ['Separate active, approved equipment authorization is required.']]);
            }
        }
    }
}
