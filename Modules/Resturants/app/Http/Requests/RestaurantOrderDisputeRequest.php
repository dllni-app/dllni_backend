<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RestaurantOrderDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $disputeId = $this->route('restaurant_order_dispute')?->id;

        return [
            'orderId' => 'required|exists:orders,id',
            'userId' => 'required|exists:users,id',
            'ticketNumber' => 'required|string|max:255|unique:restaurant_order_disputes,ticket_number,'.$disputeId,
            'status' => 'nullable|string|in:open,under_review,resolved,closed',
            'description' => 'nullable|string',
        ];
    }
}
