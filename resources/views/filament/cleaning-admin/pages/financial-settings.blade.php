<x-filament-panels::page>
    <x-filament::section heading="Revenue Model (Commission)">
        <div class="grid gap-4 md:grid-cols-2">
            <div class="flex flex-col gap-2">
                <span class="text-sm font-medium">Commission Type</span>
                <select class="fi-select block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="commissionType">
                    <option value="percent">Percentage</option>
                    <option value="fixed">Fixed Amount</option>
                </select>
            </div>
            @if($commissionType === 'percent')
                <label class="flex flex-col gap-1">
                    <span class="text-sm">Default commission rate (%)</span>
                    <input type="number" min="0" step="0.01" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="defaultCommissionRate">
                    @error('defaultCommissionRate') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
                </label>
            @else
                <label class="flex flex-col gap-1">
                    <span class="text-sm">Fixed commission amount</span>
                    <input type="number" min="0" step="0.01" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="commissionFixedAmount">
                    @error('commissionFixedAmount') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
                </label>
            @endif
            <label class="flex flex-col gap-1 md:col-span-2">
                <span class="text-sm">VAT rate (%)</span>
                <input type="number" min="0" step="0.01" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="vatRate">
                @error('vatRate') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </label>
        </div>
    </x-filament::section>

    <x-filament::section heading="Travel Cost Settings">
        <div class="grid gap-4 md:grid-cols-2">
            <label class="flex flex-col gap-1">
                <span class="text-sm">Travel markup type</span>
                <select class="fi-select block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="travelMarkupType">
                    <option value="fixed">Fixed</option>
                    <option value="percent">Percentage</option>
                </select>
                @error('travelMarkupType') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-sm">Travel markup value</span>
                <input type="number" min="0" step="0.01" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="travelMarkupValue">
                @error('travelMarkupValue') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-sm">Travel price per km</span>
                <input type="number" min="0" step="0.01" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="travelPerKm">
                @error('travelPerKm') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </label>
            <div class="flex flex-col gap-2">
                <span class="text-sm font-medium">Distance start point</span>
                <select class="fi-select block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="travelDistanceStartPoint">
                    <option value="worker_home">Worker home location</option>
                </select>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section heading="Time Billing Policy">
        <div class="grid gap-4 md:grid-cols-2">
            <div class="flex flex-col gap-2">
                <span class="text-sm font-medium">Billing mode</span>
                <select class="fi-select block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="timeBillingMode">
                    <option value="full_booked">Bill full booked time</option>
                    <option value="actual">Bill actual worked time (recommended)</option>
                </select>
                @error('timeBillingMode') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </div>
            @if($timeBillingMode === 'actual')
                <label class="flex flex-col gap-1">
                    <span class="text-sm">Minimum billable minutes</span>
                    <input type="number" min="0" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="minBillableMinutes" placeholder="Example: 120">
                    @error('minBillableMinutes') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
                </label>
            @endif
            <label class="flex flex-col gap-1">
                <span class="text-sm">Minutes before end for warning</span>
                <input type="number" min="0" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="timeWarningMinutesBeforeEnd" placeholder="Example: 15">
                @error('timeWarningMinutesBeforeEnd') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </label>
        </div>
    </x-filament::section>

    <x-filament::section heading="Coverage Thresholds">
        <div class="grid gap-4 md:grid-cols-2">
            <label class="flex flex-col gap-1">
                <span class="text-sm">Low threshold</span>
                <input type="number" min="0" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="coverageLow">
                @error('coverageLow') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-sm">OK threshold</span>
                <input type="number" min="0" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="coverageOk">
                @error('coverageOk') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </label>
        </div>
    </x-filament::section>

    <x-filament::button wire:click="save" color="primary">
        Save Settings
    </x-filament::button>
</x-filament-panels::page>
