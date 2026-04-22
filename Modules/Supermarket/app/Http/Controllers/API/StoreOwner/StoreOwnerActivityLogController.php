<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Controllers\API\StoreOwner;

use App\Http\Resources\ActivityLogResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Supermarket\Services\StoreOwnerContextService;
use Spatie\Activitylog\Models\Activity;

final class StoreOwnerActivityLogController
{
    public function __construct(private StoreOwnerContextService $contextService) {}

    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'logName' => 'nullable|string|in:products,offers,orders,inventory,system',
            'perPage' => 'nullable|integer|min:1|max:100',
        ]);

        $storeId = $this->contextService->ownedStore()->id;

        $perPage = (int) $request->get('perPage', 15);
        $logName = $request->get('logName');

        $query = Activity::query()
            ->whereJsonContains('properties->store_id', $storeId)
            ->with('causer');

        if ($logName !== null) {
            $query->where('log_name', $logName);
        }

        $logs = $query->orderByDesc('created_at')->paginate($perPage);

        return ActivityLogResource::collection($logs);
    }
}
