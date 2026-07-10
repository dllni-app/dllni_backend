<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SmOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $orderId = $this->route('sm_order');
        $lifecycleFieldRule = $this->isMethod('post') ? 'sometimes' : 'prohibited';

        return [
            'customerId' => 'sometimes|required|integer|exists:users,id',
            'storeId' => 'sometimes|required|integer|exists:sm_stores,id',
            'couponId' => 'nullable|integer|exists:sm_coupons,id',
            'cancellationPolicyId' => 'nullable|integer|exists:cancellation_policies,id',
            'orderNumber' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('sm_orders', 'order_number')->ignore($orderId),
            ],
            'status' => [$lifecycleFieldRule, 'required', 'string', 'in:pending,accepted,preparing,ready_for_pickup,picked_up,completed,cancelled'],
            'pickupMode' => 'sometimes|required|string|in:immediate_pickup,scheduled_pickup',
            'pickupScheduledFor' => 'nullable|date',
            'readyForPickupAt' => [$lifecycleFieldRule, 'nullable', 'date'],
            'pickedUpAt' => [$lifecycleFieldRule, 'nullable', 'date'],
            'customerPickupConfirmedAt' => 'nullable|date',
            'subtotal' => 'nullable|numeric|min:0',
            'discountAmount' => 'nullable|numeric|min:0',
            'serviceFee' => 'nullable|numeric|min:0',
            'totalAmount' => 'nullable|numeric|min:0',
            'cancellationFeeAmount' => 'nullable|numeric|min:0',
            'cancellationPolicySnapshot' => 'nullable|array',
            'specialInstructions' => 'nullable|string',
            'cancelledAt' => [$lifecycleFieldRule, 'nullable', 'date'],
            'cancellationReason' => [$lifecycleFieldRule, 'nullable', 'string'],
        ];
    }
}
