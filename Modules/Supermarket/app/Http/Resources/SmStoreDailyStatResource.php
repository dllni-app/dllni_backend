<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SmStoreDailyStatResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'storeId' => $this->store_id,
            'date' => $this->date?->toDateString(),
            'ordersCount' => $this->orders_count,
            'ordersRevenue' => $this->orders_revenue,
            'uniqueCustomers' => $this->unique_customers,
            'newCustomers' => $this->new_customers,
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
