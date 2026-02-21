<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests\SmOfferProductRequests;

use Illuminate\Foundation\Http\FormRequest;

final class SmOfferProductFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'perPage' => 'sometimes|integer|min:1|max:100',
            'filter.offerId' => 'sometimes|integer|exists:sm_offers,id',
            'filter.productId' => 'sometimes|integer|exists:sm_products,id',
            'sort' => 'sometimes|string|in:offerPrice,-offerPrice,maxQuantity,-maxQuantity,createdAt,-createdAt',
        ];
    }
}
