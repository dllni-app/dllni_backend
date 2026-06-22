<x-filament-hub.page-shell>
    <div class="grid gap-3 md:grid-cols-3">
        <a href="{{ \App\Filament\Pages\CleaningOverview::getUrl() }}"
            class="rounded-xl border border-primary-200 bg-primary-50 px-4 py-3 text-center text-sm font-semibold text-primary-700 transition hover:border-primary-600 hover:shadow-sm dark:border-primary-700/60 dark:bg-primary-900/20 dark:text-primary-300">
            {{ __('cleaning_admin.shared.actions.view') }}: {{ __('cleaning_admin.overview.title') }}
        </a>
    </div>

    <x-filament::section heading="Cleaning price algorithm">
        <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
            Manage the room/unit pricing algorithm used by the user cleaning order estimate and create APIs. The JSON fields are keyed by room type and size: small, medium, large.
        </p>
        <div class="grid gap-4 md:grid-cols-4">
            <label class="flex flex-col gap-1">
                <span class="text-sm">Base unit price</span>
                <input type="number" min="0" step="0.01" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="cleaningBaseUnitPrice">
                @error('cleaningBaseUnitPrice') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-sm">Deep cleaning multiplier</span>
                <input type="number" min="1" step="0.01" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="cleaningDeepMultiplier">
                @error('cleaningDeepMultiplier') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-sm">Area margin multiplier</span>
                <input type="number" min="1" step="0.01" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="cleaningAreaMarginMultiplier">
                @error('cleaningAreaMarginMultiplier') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-sm">Setup buffer minutes</span>
                <input type="number" min="0" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="cleaningSetupBufferMinutes">
                @error('cleaningSetupBufferMinutes') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </label>
        </div>
        <div class="mt-4 grid gap-4 xl:grid-cols-3">
            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">Room size ranges JSON</span>
                <textarea rows="18" class="fi-textarea block w-full rounded-lg border-gray-300 font-mono text-xs dark:border-gray-600 dark:bg-gray-800" wire:model.defer="cleaningRoomSizeRangesJson"></textarea>
                <span class="text-xs text-gray-500 dark:text-gray-400">Used to estimate square meters. Each size should include min, max, average.</span>
                @error('cleaningRoomSizeRangesJson') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">Room pricing units JSON</span>
                <textarea rows="18" class="fi-textarea block w-full rounded-lg border-gray-300 font-mono text-xs dark:border-gray-600 dark:bg-gray-800" wire:model.defer="cleaningRoomPricingUnitsJson"></textarea>
                <span class="text-xs text-gray-500 dark:text-gray-400">Order price = count × units × base unit price × cleaning mode multiplier.</span>
                @error('cleaningRoomPricingUnitsJson') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">Room time minutes JSON</span>
                <textarea rows="18" class="fi-textarea block w-full rounded-lg border-gray-300 font-mono text-xs dark:border-gray-600 dark:bg-gray-800" wire:model.defer="cleaningRoomTimeMinutesJson"></textarea>
                <span class="text-xs text-gray-500 dark:text-gray-400">Used to estimate duration. Each size should include regular and deep minutes.</span>
                @error('cleaningRoomTimeMinutesJson') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </label>
        </div>
    </x-filament::section>

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
            <div class="flex flex-col gap-1">
                <span class="text-sm">{{ __('cleaning_admin.financial.fields.travel_markup_type') }}</span>
                <div class="flex items-center rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-600 dark:border-gray-600 dark:bg-gray-800/60 dark:text-gray-300">
                    {{ __('cleaning_admin.financial.options.travel_fixed') }}
                </div>
            </div>
            <label class="flex flex-col gap-1">
                <span class="text-sm">{{ __('cleaning_admin.financial.fields.travel_per_km') }}</span>
                <input type="number" min="0" step="0.01" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="travelPerKm">
                @error('travelPerKm') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </label>
            <div class="flex flex-col gap-1">
                <span class="text-sm">{{ __('cleaning_admin.financial.fields.travel_distance_start_point') }}</span>
                <div class="flex items-center rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-600 dark:border-gray-600 dark:bg-gray-800/60 dark:text-gray-300">
                    {{ __('cleaning_admin.financial.options.worker_home') }}
                </div>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('cleaning_admin.financial.sections.coverage_thresholds')">
        <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">{{ __('cleaning_admin.financial.hints.coverage_thresholds') }}</p>
        <div class="grid gap-4 md:grid-cols-2">
            <label class="flex flex-col gap-1">
                <span class="text-sm">{{ __('cleaning_admin.financial.fields.coverage_low') }}</span>
                <input type="number" min="0" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="coverageLow">
                <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('cleaning_admin.financial.hints.coverage_low') }}</span>
                @error('coverageLow') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-sm">{{ __('cleaning_admin.financial.fields.coverage_ok') }}</span>
                <input type="number" min="0" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="coverageOk">
                <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('cleaning_admin.financial.hints.coverage_ok') }}</span>
                @error('coverageOk') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </label>
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('cleaning_admin.financial.sections.time_extension')">
        <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">{{ __('cleaning_admin.financial.hints.time_extension') }}</p>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($extensionRanges as $i => $range)
                <label class="flex flex-col gap-1 rounded-xl border border-gray-200 p-3 dark:border-gray-700">
                    <span class="text-sm font-medium">{{ __('cleaning_admin.financial.extension.range_label', ['start' => $range['start'], 'end' => $range['end']]) }}</span>
                    <div class="flex items-center gap-2">
                        <input type="number" min="0" step="0.01" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="extensionRanges.{{ $i }}.price">
                        <span class="text-xs text-gray-500 dark:text-gray-400">SYP</span>
                    </div>
                    @error('extensionRanges.'.$i.'.price') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
                </label>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('cleaning_admin.financial.sections.worker_finance')">
        <div class="grid gap-4 md:grid-cols-2">
            <label class="flex items-center gap-2 md:col-span-2">
                <input type="checkbox" class="fi-checkbox rounded border-gray-300 dark:border-gray-600" wire:model.live="workerFinanceEnabled">
                <span class="text-sm">{{ __('cleaning_admin.financial.fields.worker_finance_enabled') }}</span>
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-sm">{{ __('cleaning_admin.financial.fields.minimum_deposit_amount') }}</span>
                <input type="number" min="0" step="0.01" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="minimumDepositAmount">
                @error('minimumDepositAmount') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-sm">{{ __('cleaning_admin.financial.fields.default_max_negative_balance') }}</span>
                <input type="number" min="0" step="0.01" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="defaultMaxNegativeBalance">
                @error('defaultMaxNegativeBalance') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-sm">{{ __('cleaning_admin.financial.fields.restriction_threshold_percent') }}</span>
                <input type="number" min="0" max="100" step="0.01" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="restrictionThresholdPercent">
                <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('cleaning_admin.financial.hints.restriction_threshold_percent') }}</span>
                @error('restrictionThresholdPercent') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-sm">{{ __('cleaning_admin.financial.fields.trust_reject_after_accept_penalty') }}</span>
                <input type="number" min="0" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="trustRejectAfterAcceptPenalty">
                <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('cleaning_admin.financial.hints.trust_reject_after_accept_penalty') }}</span>
                @error('trustRejectAfterAcceptPenalty') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-sm">{{ __('cleaning_admin.financial.fields.trust_minimum_for_dispatch') }}</span>
                <input type="number" min="0" max="100" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="trustMinimumForDispatch">
                @error('trustMinimumForDispatch') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </label>
        </div>
    </x-filament::section>

    <div class="flex justify-end">
        <x-filament::button wire:click="save" color="primary">
            {{ __('cleaning_admin.financial.actions.save') }}
        </x-filament::button>
    </div>
</x-filament-hub.page-shell>
