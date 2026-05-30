<?php

declare(strict_types=1);

namespace Modules\Delivery\Services;

use App\Models\User;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Models\DeliveryCompanyStaff;

final class DeliveryCompanyContextService
{
    public function resolveFromUser(User $user): DeliveryCompany
    {
        $ownedCompany = DeliveryCompany::query()
            ->where('owner_user_id', $user->id)
            ->first();

        if ($ownedCompany instanceof DeliveryCompany) {
            return $ownedCompany;
        }

        $staffMembership = DeliveryCompanyStaff::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->with('company')
            ->firstOrFail();

        return $staffMembership->company;
    }

    public function companyIdForUser(User $user): int
    {
        return $this->resolveFromUser($user)->id;
    }
}
