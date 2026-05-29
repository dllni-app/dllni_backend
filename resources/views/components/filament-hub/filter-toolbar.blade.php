@props([
    'searchModel' => null,
    'searchPlaceholder' => null,
    'statusModel' => null,
    'statusOptions' => [],
    'rangeModel' => null,
    'rangeOptions' => [],
])

<div {{ $attributes->class(['rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900']) }}>
    <div class="grid gap-3 md:grid-cols-3">
        @if (filled($searchModel))
            <label class="flex flex-col gap-1">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                    {{ __('cleaning_admin.filters.search') }}
                </span>
                <input
                    type="text"
                    wire:model.live.debounce.400ms="{{ $searchModel }}"
                    placeholder="{{ $searchPlaceholder ?? __('cleaning_admin.filters.search_placeholder') }}"
                    class="fi-input block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800"
                />
            </label>
        @endif

        @if (filled($statusModel))
            <label class="flex flex-col gap-1">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                    {{ __('cleaning_admin.filters.status') }}
                </span>
                <select wire:model.live="{{ $statusModel }}" class="fi-select block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800">
                    @foreach ($statusOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        @if (filled($rangeModel))
            <label class="flex flex-col gap-1">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                    {{ __('cleaning_admin.filters.date_range') }}
                </span>
                <select wire:model.live="{{ $rangeModel }}" class="fi-select block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800">
                    @foreach ($rangeOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        @endif
    </div>
</div>
