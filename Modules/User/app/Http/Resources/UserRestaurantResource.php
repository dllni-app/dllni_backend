<?php

declare(strict_types=1);

namespace Modules\User\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use Modules\Resturants\Http\Resources\RestaurantResource;

final class UserRestaurantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $payload = (new RestaurantResource($this->resource))->toArray($request);

        $public = Arr::except($payload, [
            'userId',
            'reputationScore',
            'warningCount',
            'visibilityScore',
            'manualVisibilityOverride',
            'suspensionUntil',
            'user',
            'documents',
            'reputationLogs',
            'penalties',
        ]);

        $legacyPreparationTime = $this->resource->estimated_preparation_time !== null
            ? (int) $this->resource->estimated_preparation_time
            : null;
        $preparationTimeMax = $this->resource->estimated_preparation_time_max !== null
            ? (int) $this->resource->estimated_preparation_time_max
            : $legacyPreparationTime;
        $preparationTimeMin = $this->resource->estimated_preparation_time_min !== null
            ? (int) $this->resource->estimated_preparation_time_min
            : ($preparationTimeMax !== null ? max(1, $preparationTimeMax - 10) : null);

        $public['estimatedPreparationTimeMin'] = $preparationTimeMin;
        $public['estimatedPreparationTimeMax'] = $preparationTimeMax;
        $public['preparationTimeRange'] = [
            'min' => $preparationTimeMin,
            'max' => $preparationTimeMax,
        ];

        return $public;
    }
}
