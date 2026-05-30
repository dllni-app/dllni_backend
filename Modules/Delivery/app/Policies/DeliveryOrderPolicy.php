<?php

declare(strict_types=1);

namespace Modules\Delivery\Policies;

use App\Enums\PermissionGroup;
use App\Models\User;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Delivery\Services\DeliveryCompanyContextService;

final class DeliveryOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(PermissionGroup::DeliveryOrders->value.'.view');
    }

    public function view(User $user, DeliveryOrder $deliveryOrder): bool
    {
        if (! $user->can(PermissionGroup::DeliveryOrders->value.'.view')) {
            return false;
        }

        return $this->belongsToUserCompany($user, $deliveryOrder);
    }

    public function create(User $user): bool
    {
        return $user->can(PermissionGroup::DeliveryOrders->value.'.create');
    }

    public function update(User $user, DeliveryOrder $deliveryOrder): bool
    {
        if (! $user->can(PermissionGroup::DeliveryOrders->value.'.update')) {
            return false;
        }

        return $this->belongsToUserCompany($user, $deliveryOrder);
    }

    public function delete(User $user, DeliveryOrder $deliveryOrder): bool
    {
        if (! $user->can(PermissionGroup::DeliveryOrders->value.'.delete')) {
            return false;
        }

        return $this->belongsToUserCompany($user, $deliveryOrder);
    }

    public function retryDispatch(User $user, DeliveryOrder $deliveryOrder): bool
    {
        return $this->update($user, $deliveryOrder);
    }

    public function cancel(User $user, DeliveryOrder $deliveryOrder): bool
    {
        return $this->update($user, $deliveryOrder);
    }

    private function belongsToUserCompany(User $user, DeliveryOrder $deliveryOrder): bool
    {
        $companyId = app(DeliveryCompanyContextService::class)->companyIdForUser($user);

        return (int) $deliveryOrder->company_id === $companyId;
    }
}
