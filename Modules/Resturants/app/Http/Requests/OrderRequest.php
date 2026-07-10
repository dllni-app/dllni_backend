<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class OrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $orderId = $this->route('order')?->id;
        $lifecycleFieldRule = $this->isMethod('post') ? 'nullable' : 'prohibited';

        return [
            'userId' => 'required|exists:users,id',
            'restaurantId' => 'required|exists:restaurants,id',
            'promoCodeId' => 'nullable|exists:promo_codes,id',
            'assignedStaffId' => 'nullable|exists:users,id',
            'cancellationPolicyId' => 'nullable|exists:cancellation_policies,id',
            'orderNumber' => 'required|string|max:255|unique:orders,order_number,'.$orderId,
            'status' => [$lifecycleFieldRule, 'string', 'in:pending,accepted,preparing,ready_for_pickup,picked_up,completed,cancelled'],
            'orderType' => 'nullable|string|in:delivery,pickup,dine_in',
            'pickupMode' => 'nullable|string|in:immediate_pickup,scheduled_pickup',
            'pickupScheduledFor' => 'nullable|date',
            'readyForPickupAt' => [$lifecycleFieldRule, 'date'],
            'pickedUpAt' => [$lifecycleFieldRule, 'date'],
            'customerPickupConfirmedAt' => 'nullable|date',
            'subtotal' => 'required|numeric|min:0',
            'discountAmount' => 'nullable|numeric|min:0',
            'taxAmount' => 'nullable|numeric|min:0',
            'serviceFee' => 'nullable|numeric|min:0',
            'totalAmount' => 'required|numeric|min:0',
            'cancellationFeeAmount' => 'nullable|numeric|min:0',
            'cancellationPolicySnapshot' => 'nullable|array',
            'specialInstructions' => 'nullable|string',
            'acceptedAt' => [$lifecycleFieldRule, 'date'],
            'preparingAt' => [$lifecycleFieldRule, 'date'],
            'completedAt' => [$lifecycleFieldRule, 'date'],
            'cancelledAt' => [$lifecycleFieldRule, 'date'],
            'cancellationReason' => [$lifecycleFieldRule, 'string'],
        ];
    }
}
