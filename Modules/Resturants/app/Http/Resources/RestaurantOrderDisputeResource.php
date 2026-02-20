<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Resturants\Models\RestaurantOrderDispute;

/**
 * @mixin RestaurantOrderDispute
 */
final class RestaurantOrderDisputeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'orderId' => $this->order_id,
            'userId' => $this->user_id,
            'ticketNumber' => $this->ticket_number,
            'status' => $this->status?->value ?? $this->status,
            'description' => $this->description,
            'order' => $this->whenLoaded('order', fn () => [
                'id' => $this->order->id,
                'orderNumber' => $this->order->order_number,
                'restaurantId' => $this->order->restaurant_id,
            ]),
            'messages' => $this->whenLoaded('messages'),
            'createdAt' => $this->created_at->toDateTimeString(),
            'updatedAt' => $this->updated_at->toDateTimeString(),
        ];
    }
}
