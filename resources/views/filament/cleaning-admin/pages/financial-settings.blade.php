<x-filament-hub.page-shell>
    <div class="grid gap-3 md:grid-cols-3">
        <a href="{{ \App\Filament\Pages\CleaningOverview::getUrl() }}"
            class="rounded-xl border border-primary-200 bg-primary-50 px-4 py-3 text-center text-sm font-semibold text-primary-700 transition hover:border-primary-600 hover:shadow-sm dark:border-primary-700/60 dark:bg-primary-900/20 dark:text-primary-300">
            {{ __('cleaning_admin.shared.actions.view') }}: {{ __('cleaning_admin.overview.title') }}
        </a>
    </div>

    <x-filament::section :heading="__('cleaning_admin.financial.sections.revenue_model')">
        <div class="grid gap-4 md:grid-cols-2">
            <div class="flex flex-col gap-2">
                <span class="text-sm font-medium">{{ __('cleaning_admin.financial.fields.commission_type') }}</span>
                <select class="fi-select block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="commissionType">
                    <option value="percent">{{ __('cleaning_admin.financial.options.commission_percent') }}</option>
                    <option value="fixed">{{ __('cleaning_admin.financial.options.commission_fixed') }}</option>
                </select>
            </div>
            @if($commissionType === 'percent')
                <label class="flex flex-col gap-1">
                    <span class="text-sm">{{ __('cleaning_admin.financial.fields.default_commission_rate') }}</span>
                    <input type="number" min="0" step="0.01" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="defaultCommissionRate">
                    @error('defaultCommissionRate') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
                </label>
            @else
                <label class="flex flex-col gap-1">
                    <span class="text-sm">{{ __('cleaning_admin.financial.fields.commission_fixed_amount') }}</span>
                    <input type="number" min="0" step="0.01" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="commissionFixedAmount">
                    @error('commissionFixedAmount') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
                </label>
            @endif
            <label class="flex flex-col gap-1 md:col-span-2">
                <span class="text-sm">{{ __('cleaning_admin.financial.fields.vat_rate') }}</span>
                <input type="number" min="0" step="0.01" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="vatRate">
                @error('vatRate') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </label>
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('cleaning_admin.financial.sections.travel_costs')">
        <div class="grid gap-4 md:grid-cols-2">
            <label class="flex flex-col gap-1">
                <span class="text-sm">{{ __('cleaning_admin.financial.fields.travel_markup_type') }}</span>
                <select class="fi-select block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="travelMarkupType">
                    <option value="fixed">{{ __('cleaning_admin.financial.options.travel_fixed') }}</option>
                    <option value="percent">{{ __('cleaning_admin.financial.options.travel_percent') }}</option>
                </select>
                @error('travelMarkupType') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-sm">{{ __('cleaning_admin.financial.fields.travel_markup_value') }}</span>
                <input type="number" min="0" step="0.01" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="travelMarkupValue">
                @error('travelMarkupValue') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-sm">{{ __('cleaning_admin.financial.fields.travel_per_km') }}</span>
                <input type="number" min="0" step="0.01" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="travelPerKm">
                @error('travelPerKm') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </label>
            <div class="flex flex-col gap-2">
                <span class="text-sm font-medium">{{ __('cleaning_admin.financial.fields.travel_distance_start_point') }}</span>
                <select class="fi-select block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="travelDistanceStartPoint">
                    <option value="worker_home">{{ __('cleaning_admin.financial.options.worker_home') }}</option>
                </select>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('cleaning_admin.financial.sections.time_billing_policy')">
        <div class="grid gap-4 md:grid-cols-2">
            <div class="flex flex-col gap-2">
                <span class="text-sm font-medium">{{ __('cleaning_admin.financial.fields.time_billing_mode') }}</span>
                <select class="fi-select block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="timeBillingMode">
                    <option value="full_booked">{{ __('cleaning_admin.financial.options.time_billing_full_booked') }}</option>
                    <option value="actual">{{ __('cleaning_admin.financial.options.time_billing_actual') }}</option>
                </select>
                @error('timeBillingMode') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </div>
            @if($timeBillingMode === 'actual')
                <label class="flex flex-col gap-1">
                    <span class="text-sm">{{ __('cleaning_admin.financial.fields.min_billable_minutes') }}</span>
                    <input type="number" min="0" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="minBillableMinutes" placeholder="{{ __('cleaning_admin.financial.placeholders.min_billable_minutes') }}">
                    @error('minBillableMinutes') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
                </label>
            @endif
            <label class="flex flex-col gap-1">
                <span class="text-sm">{{ __('cleaning_admin.financial.fields.time_warning_minutes_before_end') }}</span>
                <input type="number" min="0" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="timeWarningMinutesBeforeEnd" placeholder="{{ __('cleaning_admin.financial.placeholders.time_warning_minutes_before_end') }}">
                @error('timeWarningMinutesBeforeEnd') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </label>
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('cleaning_admin.financial.sections.coverage_thresholds')">
        <div class="grid gap-4 md:grid-cols-2">
            <label class="flex flex-col gap-1">
                <span class="text-sm">{{ __('cleaning_admin.financial.fields.coverage_low') }}</span>
                <input type="number" min="0" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="coverageLow">
                @error('coverageLow') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-sm">{{ __('cleaning_admin.financial.fields.coverage_ok') }}</span>
                <input type="number" min="0" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="coverageOk">
                @error('coverageOk') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </label>
        </div>
    </x-filament::section>

    <div class="flex justify-end">
        <x-filament::button wire:click="save" color="primary">
            {{ __('cleaning_admin.financial.actions.save') }}
        </x-filament::button>
    </div>
</x-filament-hub.page-shell>
