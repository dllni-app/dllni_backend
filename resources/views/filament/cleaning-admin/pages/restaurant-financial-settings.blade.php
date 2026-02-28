<x-filament-panels::page>
    <div dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" class="space-y-6">
        <x-filament::section :heading="__('restaurant_admin.financial.title')">
            <div class="grid gap-4 md:grid-cols-2">
                <label class="flex flex-col gap-1">
                    <span class="text-sm">{{ __('restaurant_admin.financial.base_hour_price') }}</span>
                    <input type="number" step="0.01" wire:model.live="baseHourPrice" class="fi-input block w-full rounded-lg" />
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-sm">{{ __('restaurant_admin.financial.min_hours') }}</span>
                    <input type="number" min="1" wire:model.live="minHours" class="fi-input block w-full rounded-lg" />
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-sm">{{ __('restaurant_admin.financial.commission_type') }}</span>
                    <select wire:model.live="commissionType" class="fi-select block w-full rounded-lg">
                        <option value="percent">{{ __('restaurant_admin.financial.percent') }}</option>
                        <option value="fixed">{{ __('restaurant_admin.financial.fixed') }}</option>
                    </select>
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-sm">{{ __('restaurant_admin.financial.commission_value') }}</span>
                    <input type="number" step="0.01" wire:model.live="commissionValue" class="fi-input block w-full rounded-lg" />
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-sm">{{ __('restaurant_admin.financial.travel_per_km') }}</span>
                    <input type="number" step="0.01" wire:model.live="travelPerKm" class="fi-input block w-full rounded-lg" />
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-sm">{{ __('restaurant_admin.financial.travel_minimum') }}</span>
                    <input type="number" step="0.01" wire:model.live="travelMinimum" class="fi-input block w-full rounded-lg" />
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-sm">{{ __('restaurant_admin.financial.distance_start_point') }}</span>
                    <select wire:model.live="distanceStartPoint" class="fi-select block w-full rounded-lg">
                        <option value="current_location">{{ __('restaurant_admin.financial.current_location') }}</option>
                        <option value="home_address">{{ __('restaurant_admin.financial.home_address') }}</option>
                        <option value="auto">{{ __('restaurant_admin.financial.auto') }}</option>
                    </select>
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-sm">{{ __('restaurant_admin.financial.billing_mode') }}</span>
                    <select wire:model.live="billingMode" class="fi-select block w-full rounded-lg">
                        <option value="full_booked">{{ __('restaurant_admin.financial.full_booked') }}</option>
                        <option value="actual">{{ __('restaurant_admin.financial.actual') }}</option>
                    </select>
                </label>
                @if($billingMode === 'actual')
                    <label class="flex flex-col gap-1">
                        <span class="text-sm">{{ __('restaurant_admin.financial.min_actual_minutes') }}</span>
                        <input type="number" min="0" wire:model.live="minActualMinutes" class="fi-input block w-full rounded-lg" />
                    </label>
                @endif
                <label class="flex flex-col gap-1">
                    <span class="text-sm">{{ __('restaurant_admin.financial.time_warning') }}</span>
                    <input type="number" min="1" wire:model.live="timeWarningMinutesBeforeEnd" class="fi-input block w-full rounded-lg" />
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-sm">{{ __('restaurant_admin.financial.coverage_low') }}</span>
                    <input type="number" min="1" wire:model.live="coverageLow" class="fi-input block w-full rounded-lg" />
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-sm">{{ __('restaurant_admin.financial.coverage_good') }}</span>
                    <input type="number" min="1" wire:model.live="coverageGood" class="fi-input block w-full rounded-lg" />
                </label>
            </div>
        </x-filament::section>

        <x-filament::button wire:click="save" color="primary">{{ __('restaurant_admin.financial.save') }}</x-filament::button>
    </div>
</x-filament-panels::page>
