<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ServiceAddonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $serviceAddon = $this->route('service_addon');

        return [
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:service_addons,slug,'.($serviceAddon?->id ?? 'NULL'),
            'pricingType' => 'required|string|in:fixed,percentage',
            'priceValue' => 'required|numeric|min:0|decimal:0,2',
            'isActive' => 'nullable|boolean',
        ];
    }
}
