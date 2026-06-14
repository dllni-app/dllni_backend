<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Resturants\Models\Order;

final class UserSosStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $orderId = $this->input('order_id');

        if (! is_numeric($orderId)) {
            return true;
        }

        $order = Order::query()
            ->select(['id', 'user_id'])
            ->whereKey((int) $orderId)
            ->first();

        if ($order === null) {
            return true;
        }

        return (int) $order->user_id === (int) $user->getAuthIdentifier();
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'message' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('message') && is_string($this->input('message'))) {
            $this->merge([
                'message' => trim((string) $this->input('message')),
            ]);
        }
    }
}
