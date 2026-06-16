<?php

declare(strict_types=1);

namespace App\Filament\Company\Concerns;

use App\Models\Dispute;
use Illuminate\Database\Eloquent\Builder;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Delivery\Services\DeliveryCompanyContextService;

trait ScopesDeliveryCompanyDisputes
{
    protected static function companyDisputesQuery(Builder $query): Builder
    {
        $user = auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        $companyId = app(DeliveryCompanyContextService::class)->companyIdForUser($user);
        $orderIds = DeliveryOrder::query()
            ->where('company_id', $companyId)
            ->pluck('id');

        return $query
            ->where('booking_type', 'delivery_order')
            ->whereIn('booking_id', $orderIds);
    }

    protected static function disputeBelongsToCompany(Dispute $dispute): bool
    {
        if ($dispute->booking_type !== 'delivery_order') {
            return false;
        }

        $user = auth()->user();

        if (! $user) {
            return false;
        }

        $companyId = app(DeliveryCompanyContextService::class)->companyIdForUser($user);

        return DeliveryOrder::query()
            ->where('company_id', $companyId)
            ->whereKey($dispute->booking_id)
            ->exists();
    }
}
