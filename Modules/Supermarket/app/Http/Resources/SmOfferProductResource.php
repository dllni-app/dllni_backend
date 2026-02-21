<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Supermarket\Models\SmOfferProduct;

/**
 * @mixin SmOfferProduct
 */
final class SmOfferProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'offerId' => $this->offer_id,
            'offer' => SmOfferResource::make($this->whenLoaded('offer')),
            'productId' => $this->product_id,
            'product' => SmProductResource::make($this->whenLoaded('product')),
            'offerPrice' => $this->offer_price,
            'maxQuantity' => $this->max_quantity,
            'createdAt' => $this->created_at?->toDateTimeString(),
            'updatedAt' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
