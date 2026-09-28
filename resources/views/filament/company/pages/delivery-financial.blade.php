<x-filament-panels::page>
    @php($transactions = $this->transactions())

    @if ($this->account?->is_suspended)
        <x-filament::section>
            <p class="text-sm font-medium text-danger-600 dark:text-danger-400">
                {{ __('delivery_company.financial.warnings.suspended') }}
                @if ($this->account->suspension_reason)
                    — {{ $this->account->suspension_reason }}
                @endif
            </p>
        </x-filament::section>
    @elseif ($this->isAtOrOverLimit())
        <x-filament::section>
            <p class="text-sm font-medium text-danger-600 dark:text-danger-400">
                {{ __('delivery_company.financial.warnings.at_limit') }}
            </p>
        </x-filament::section>
    @elseif ($this->isNearLimit())
        <x-filament::section>
            <p class="text-sm font-medium text-warning-600 dark:text-warning-400">
                {{ __('delivery_company.financial.warnings.near_limit') }}
            </p>
        </x-filament::section>
    @endif

    <x-filament::section :heading="__('delivery_company.financial.sections.summary')">
        <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('delivery_company.financial.fields.current_balance') }}</dt>
                <dd class="text-lg font-semibold">
                    {{ number_format((float) $this->account?->current_balance, 2) }}
                    {{ $this->account?->currency }}
                </dd>
            </div>
            <div>
                <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('delivery_company.financial.fields.financial_limit') }}</dt>
                <dd class="text-lg font-semibold">
                    {{ number_format((float) $this->account?->financial_limit, 2) }}
                    {{ $this->account?->currency }}
                </dd>
            </div>
            <div>
                <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('delivery_company.financial.fields.is_suspended') }}</dt>
                <dd class="text-lg font-semibold">
                    {{ $this->account?->is_suspended ? __('cleaning_admin.boolean.yes') : __('cleaning_admin.boolean.no') }}
                </dd>
            </div>
            <div>
                <dt class="text-sm text-gray-500 dark:text-gray-400">{{ __('delivery_company.financial.fields.currency') }}</dt>
                <dd class="text-lg font-semibold">{{ $this->account?->currency }}</dd>
            </div>
        </dl>
    </x-filament::section>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <div>
            <label class="text-sm font-medium text-gray-700 dark:text-gray-300">
                {{ __('delivery_company.financial.filters.period') }}
            </label>
            <select wire:model.live="periodDays" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900">
                @foreach ($this->periodOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-sm font-medium text-gray-700 dark:text-gray-300">
                {{ __('delivery_company.financial.filters.type') }}
            </label>
            <select wire:model.live="transactionType" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900">
                @foreach ($this->transactionTypeOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-sm font-medium text-gray-700 dark:text-gray-300">
                {{ __('delivery_company.financial.filters.per_page') }}
            </label>
            <select wire:model.live="perPage" class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="100">100</option>
            </select>
        </div>
        <div class="flex items-end">
            <x-filament::button wire:click="exportCsv" icon="heroicon-o-arrow-down-tray" class="w-full justify-center">
                {{ __('delivery_company.financial.actions.export_csv') }}
            </x-filament::button>
        </div>
    </div>

    <x-filament::section :heading="__('delivery_company.financial.sections.ledger')">
        @if ($transactions->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('delivery_company.financial.empty_ledger') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-start text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <th class="px-3 py-2 font-medium">{{ __('delivery_company.financial.fields.created_at') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('delivery_company.financial.fields.transaction_type') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('delivery_company.financial.fields.direction') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('delivery_company.financial.fields.amount') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('delivery_company.financial.fields.balance_after') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('delivery_company.financial.fields.note') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($transactions as $transaction)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="px-3 py-2 whitespace-nowrap">{{ $transaction->created_at?->format('Y-m-d H:i') }}</td>
                                <td class="px-3 py-2">{{ $this->transactionTypeLabel((string) $transaction->transaction_type) }}</td>
                                <td class="px-3 py-2">{{ $this->directionLabel((string) $transaction->direction) }}</td>
                                <td class="px-3 py-2">{{ number_format((float) $transaction->amount, 2) }}</td>
                                <td class="px-3 py-2">{{ number_format((float) $transaction->balance_after, 2) }}</td>
                                <td class="px-3 py-2">{{ $transaction->note ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($transactions->hasPages())
                <div class="mt-4">
                    {{ $transactions->onEachSide(1)->links() }}
                </div>
            @endif
        @endif
    </x-filament::section>
</x-filament-panels::page>
