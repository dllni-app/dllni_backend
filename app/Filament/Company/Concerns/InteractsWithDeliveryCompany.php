<?php

declare(strict_types=1);

namespace App\Filament\Company\Concerns;

use App\Enums\PermissionGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Delivery\Services\DeliveryCompanyContextService;

trait InteractsWithDeliveryCompany
{
    protected static function companyScopedQuery(Builder $query): Builder
    {
        $user = auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        $companyId = app(DeliveryCompanyContextService::class)->companyIdForUser($user);

        return $query->where('company_id', $companyId);
    }

    protected static function hasDeliveryPermission(string $action, PermissionGroup $group = PermissionGroup::DeliveryOrders): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        return $user->can("{$group->value}.{$action}");
    }

    protected static function assertBelongsToCompany(Model $record): void
    {
        $user = auth()->user();

        if (! $user || ! isset($record->company_id)) {
            abort(403);
        }

        $companyId = app(DeliveryCompanyContextService::class)->companyIdForUser($user);

        if ((int) $record->company_id !== $companyId) {
            abort(403);
        }
    }
}
