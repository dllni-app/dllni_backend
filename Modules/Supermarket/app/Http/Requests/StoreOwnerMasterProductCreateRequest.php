<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class StoreOwnerMasterProductCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'storeId' => ['required', 'integer', 'exists:sm_stores,id'],
            'products' => ['required', 'array', 'min:1'],
            'products.*.categoryId' => ['required', 'integer', 'exists:sm_categories,id'],
            'products.*.masterProductId' => ['required', 'integer', 'exists:master_products,id'],
            'products.*.title' => ['required', 'string', 'max:255'],
            'products.*.price' => ['required', 'numeric', 'min:0'],
            'products.*.stockQuantity' => ['required', 'integer', 'min:0'],
            'products.*.lowStockThreshold' => ['sometimes', 'integer', 'min:0'],
            'products.*.discountedPrice' => ['nullable', 'numeric', 'min:0'],
            'products.*.description' => ['nullable', 'string'],
            'products.*.expiresAt' => ['nullable', 'date'],
            'products.*.isAvailable' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $products = $this->input('products', []);

            if (! is_array($products)) {
                return;
            }

            foreach ($products as $index => $product) {
                if (! is_array($product)) {
                    continue;
                }

                if (! array_key_exists('discountedPrice', $product)) {
                    continue;
                }

                $discountedPrice = $product['discountedPrice'];
                $price = $product['price'] ?? null;

                if (
                    is_numeric($discountedPrice)
                    && is_numeric($price)
                    && (float) $discountedPrice > (float) $price
                ) {
                    $validator->errors()->add(
                        "products.{$index}.discountedPrice",
                        'The discounted price field must be less than or equal to price.'
                    );
                }
            }
        });
    }
}
