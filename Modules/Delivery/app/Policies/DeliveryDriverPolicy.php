<?php

declare(strict_types=1);

namespace Modules\Delivery\Policies;

use App\Enums\PermissionGroup;
use App\Models\User;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Services\DeliveryCompanyContextService;

final class DeliveryDriverPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionGroup::DeliveryDrivers->value.'.view');
    }

    public function view(User $user, DeliveryDriver $deliveryDriver): bool
    {
        if (! $user->can(PermissionGroup::DeliveryDrivers->value.'.view')) {
            return false;
        }

        return $this->belongsToUserCompany($user, $deliveryDriver);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionGroup::DeliveryDrivers->value.'.create');
    }

    public function update(User $user, DeliveryDriver $deliveryDriver): bool
    {
        if (! $user->can(PermissionGroup::DeliveryDrivers->value.'.update')) {
            return false;
        }

        return $this->belongsToUserCompany($user, $deliveryDriver);
    }

    public function delete(User $user, DeliveryDriver $deliveryDriver): bool
    {
        if (! $user->can(PermissionGroup::DeliveryDrivers->value.'.delete')) {
            return false;
        }

        return $this->belongsToUserCompany($user, $deliveryDriver);
    }

    public function suspend(User $user, DeliveryDriver $deliveryDriver): bool
    {
        return $this->update($user, $deliveryDriver);
    }

    public function unsuspend(User $user, DeliveryDriver $deliveryDriver): bool
    {
        return $this->update($user, $deliveryDriver);
    }

    private function belongsToUserCompany(User $user, DeliveryDriver $deliveryDriver): bool
    {
        $companyId = app(DeliveryCompanyContextService::class)->companyIdForUser($user);

        return (int) $deliveryDriver->company_id === $companyId;
    }
}
