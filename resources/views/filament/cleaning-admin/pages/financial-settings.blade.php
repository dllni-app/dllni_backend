<x-filament-hub.page-shell>
    <x-filament::section :heading="__('cleaning_settings.pricing.section')">
        <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">{{ __('cleaning_settings.pricing.description') }}</p>

        <div class="grid gap-4 md:grid-cols-2">
            <label class="flex flex-col gap-1">
                <span class="text-sm">{{ __('cleaning_settings.pricing.base_unit_price') }}</span>
                <input type="number" min="0" step="0.01" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="cleaningBaseUnitPrice">
                <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('cleaning_settings.pricing.base_unit_price_hint') }}</span>
                @error('cleaningBaseUnitPrice') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </label>

            <label class="flex flex-col gap-1">
                <span class="text-sm">{{ __('cleaning_settings.pricing.deep_multiplier') }}</span>
                <input type="number" min="1" step="0.01" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="cleaningDeepMultiplier">
                <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('cleaning_settings.pricing.deep_multiplier_hint') }}</span>
                @error('cleaningDeepMultiplier') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </label>
        </div>

        <div class="mt-6 space-y-4">
            @foreach ($roomTypes as $roomType)
                <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700">
                    <div class="border-b border-gray-200 bg-gray-50 px-4 py-3 dark:border-gray-700 dark:bg-gray-800/60">
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('cleaning_settings.room_types.'.$roomType) }}</h3>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[760px] divide-y divide-gray-200 text-sm dark:divide-gray-700">
                            <thead class="bg-white dark:bg-gray-900">
                                <tr>
                                    <th class="px-4 py-3 text-start font-medium text-gray-700 dark:text-gray-300">{{ __('cleaning_settings.pricing.room_size') }}</th>
                                    <th class="px-4 py-3 text-start font-medium text-gray-700 dark:text-gray-300">{{ __('cleaning_settings.pricing.pricing_unit') }}</th>
                                    <th class="px-4 py-3 text-start font-medium text-gray-700 dark:text-gray-300">{{ __('cleaning_settings.pricing.regular_minutes') }}</th>
                                    <th class="px-4 py-3 text-start font-medium text-gray-700 dark:text-gray-300">{{ __('cleaning_settings.pricing.deep_minutes') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-900">
                                @foreach ($roomSizes as $roomSize)
                                    <tr>
                                        <td class="whitespace-nowrap px-4 py-3 font-medium text-gray-950 dark:text-white">{{ __('cleaning_settings.room_sizes.'.$roomSize) }}</td>
                                        <td class="px-4 py-3">
                                            <input type="number" min="0" step="0.01" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="roomPricingSettings.{{ $roomType }}.{{ $roomSize }}.pricingUnit">
                                            @error('roomPricingSettings.'.$roomType.'.'.$roomSize.'.pricingUnit') <span class="mt-1 block text-xs text-danger-600">{{ $message }}</span> @enderror
                                        </td>
                                        <td class="px-4 py-3">
                                            <input type="number" min="1" step="1" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="roomPricingSettings.{{ $roomType }}.{{ $roomSize }}.regularMinutes">
                                            @error('roomPricingSettings.'.$roomType.'.'.$roomSize.'.regularMinutes') <span class="mt-1 block text-xs text-danger-600">{{ $message }}</span> @enderror
                                        </td>
                                        <td class="px-4 py-3">
                                            <input type="number" min="1" step="1" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="roomPricingSettings.{{ $roomType }}.{{ $roomSize }}.deepMinutes">
                                            @error('roomPricingSettings.'.$roomType.'.'.$roomSize.'.deepMinutes') <span class="mt-1 block text-xs text-danger-600">{{ $message }}</span> @enderror
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endforeach
        </div>

        <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">{{ __('cleaning_settings.pricing.formula_hint') }}</p>
        @error('roomPricingSettings') <span class="mt-2 block text-xs text-danger-600">{{ $message }}</span> @enderror
    </x-filament::section>

    <x-filament::section :heading="__('cleaning_admin.financial.sections.revenue_model')">
        <div class="grid gap-4 md:grid-cols-2">
            <div class="flex flex-col gap-2"><span class="text-sm font-medium">{{ __('cleaning_admin.financial.fields.commission_type') }}</span><select class="fi-select block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="commissionType"><option value="percent">{{ __('cleaning_admin.financial.options.commission_percent') }}</option><option value="fixed">{{ __('cleaning_admin.financial.options.commission_fixed') }}</option></select></div>
            @if($commissionType === 'percent')
                <label class="flex flex-col gap-1"><span class="text-sm">{{ __('cleaning_admin.financial.fields.default_commission_rate') }}</span><input type="number" min="0" step="0.01" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="defaultCommissionRate">@error('defaultCommissionRate') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror</label>
            @else
                <label class="flex flex-col gap-1"><span class="text-sm">{{ __('cleaning_admin.financial.fields.commission_fixed_amount') }}</span><input type="number" min="0" step="0.01" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="commissionFixedAmount">@error('commissionFixedAmount') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror</label>
            @endif
            <label class="flex flex-col gap-1 md:col-span-2"><span class="text-sm">{{ __('cleaning_admin.financial.fields.vat_rate') }}</span><input type="number" min="0" step="0.01" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="vatRate">@error('vatRate') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror</label>
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('cleaning_admin.financial.sections.travel_costs')">
        <div class="grid gap-4 md:grid-cols-2">
            <div class="flex flex-col gap-1"><span class="text-sm">{{ __('cleaning_admin.financial.fields.travel_markup_type') }}</span><div class="flex items-center rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-600 dark:border-gray-600 dark:bg-gray-800/60 dark:text-gray-300">{{ __('cleaning_admin.financial.options.travel_fixed') }}</div></div>
            <label class="flex flex-col gap-1"><span class="text-sm">{{ __('cleaning_admin.financial.fields.travel_per_km') }}</span><input type="number" min="0" step="0.01" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="travelPerKm">@error('travelPerKm') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror</label>
            <div class="flex flex-col gap-1"><span class="text-sm">{{ __('cleaning_admin.financial.fields.travel_distance_start_point') }}</span><div class="flex items-center rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-600 dark:border-gray-600 dark:bg-gray-800/60 dark:text-gray-300">{{ __('cleaning_admin.financial.options.worker_home') }}</div></div>
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('cleaning_admin.financial.sections.coverage_thresholds')">
        <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">{{ __('cleaning_admin.financial.hints.coverage_thresholds') }}</p>
        <div class="grid gap-4 md:grid-cols-2">
            <label class="flex flex-col gap-1"><span class="text-sm">{{ __('cleaning_admin.financial.fields.coverage_low') }}</span><input type="number" min="0" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="coverageLow"><span class="text-xs text-gray-500 dark:text-gray-400">{{ __('cleaning_admin.financial.hints.coverage_low') }}</span>@error('coverageLow') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror</label>
            <label class="flex flex-col gap-1"><span class="text-sm">{{ __('cleaning_admin.financial.fields.coverage_ok') }}</span><input type="number" min="0" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="coverageOk"><span class="text-xs text-gray-500 dark:text-gray-400">{{ __('cleaning_admin.financial.hints.coverage_ok') }}</span>@error('coverageOk') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror</label>
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('cleaning_admin.financial.sections.time_extension')">
        <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">{{ __('cleaning_admin.financial.hints.time_extension') }}</p>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($extensionRanges as $i => $range)
                <label class="flex flex-col gap-1 rounded-xl border border-gray-200 p-3 dark:border-gray-700"><span class="text-sm font-medium">{{ __('cleaning_admin.financial.extension.range_label', ['start' => $range['start'], 'end' => $range['end']]) }}</span><div class="flex items-center gap-2"><input type="number" min="0" step="0.01" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="extensionRanges.{{ $i }}.price"><span class="text-xs text-gray-500 dark:text-gray-400">{{ __('SYP') }}</span></div>@error('extensionRanges.'.$i.'.price') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror</label>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('cleaning_admin.financial.sections.worker_finance')">
        <div class="grid gap-4 md:grid-cols-2">
            <label class="flex flex-col gap-1"><span class="text-sm">{{ __('cleaning_admin.financial.fields.trust_reject_after_accept_penalty') }}</span><input type="number" min="0" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="trustRejectAfterAcceptPenalty"><span class="text-xs text-gray-500 dark:text-gray-400">{{ __('cleaning_admin.financial.hints.trust_reject_after_accept_penalty') }}</span>@error('trustRejectAfterAcceptPenalty') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror</label>
            <label class="flex flex-col gap-1"><span class="text-sm">{{ __('cleaning_admin.financial.fields.trust_minimum_for_dispatch') }}</span><input type="number" min="0" max="100" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="trustMinimumForDispatch"><span class="text-xs text-gray-500 dark:text-gray-400">أدنى درجة ثقة يجب أن يملكها العامل حتى يدخل ضمن قائمة العاملين المؤهلين لإرسال الطلبات.</span>@error('trustMinimumForDispatch') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror</label>
        </div>
    </x-filament::section>

    <div class="flex justify-end"><x-filament::button wire:click="save" color="primary">{{ __('cleaning_admin.financial.actions.save') }}</x-filament::button></div>
</x-filament-hub.page-shell>
