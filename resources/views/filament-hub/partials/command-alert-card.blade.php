{{-- resources/views/filament-hub/partials/command-alert-card.blade.php --}}

@php
    $isDanger = $variant === 'danger';

    $badgeClasses = $isDanger
        ? 'bg-danger-100 dark:bg-danger-900 text-danger-700 dark:text-danger-300'
        : 'bg-primary-100 dark:bg-primary-900 text-primary-700 dark:text-primary-300';

    $dotClasses = $isDanger ? 'bg-danger-600 dark:bg-danger-400' : 'bg-primary-600 dark:bg-primary-400';

    $cardClasses = $isDanger
        ? 'border-danger-200 dark:border-danger-800 bg-white dark:bg-gray-900'
        : 'border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 hover:bg-gray-100 dark:hover:bg-gray-800';
@endphp

<div class="rounded-2xl border p-4 transition {{ $cardClasses }}">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">

        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
                <span
                    class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-sm font-semibold {{ $badgeClasses }}">
                    <span class="h-2 w-2 rounded-full {{ $dotClasses }}"></span>
                    {{ $alertTypeLabels[$alert->alert_type?->value ?? ''] ?? $alert->alert_type?->value }}
                </span>

                <span
                    class="rounded-full bg-gray-100 dark:bg-gray-800 px-2.5 py-1 text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">
                    {{ $alert->severity?->value ?? $alert->severity }}
                </span>

                @if ($alert->booking)
                    <span class="text-sm font-medium text-gray-600 dark:text-gray-400">
                        الحجز #{{ $alert->booking->booking_number ?? $alert->booking_id }}
                    </span>
                @endif
            </div>

            <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-gray-500 dark:text-gray-400">
                @if ($alert->created_at)
                    <span class="inline-flex items-center gap-1">
                        <x-filament::icon icon="heroicon-o-clock" class="h-4 w-4" />
                        {{ $alert->created_at->diffForHumans() }}
                    </span>
                @endif

                @if ($alert->status)
                    <span>
                        الحالة: {{ $alert->status?->value ?? $alert->status }}
                    </span>
                @endif
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2 lg:justify-end">

            @if ($alert->booking && method_exists($alert->booking, 'customer') && $alert->booking->customer?->phone)
                <a href="tel:{{ $alert->booking->customer->phone }}"
                    class="inline-flex items-center gap-2 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 px-3 py-2 text-sm font-semibold text-gray-700 dark:text-gray-200 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-800">
                    <x-filament::icon icon="heroicon-o-phone" class="h-4 w-4" />
                    اتصال بالعميل
                </a>
            @endif

            @if ($alert->booking && method_exists($alert->booking, 'worker') && filled($alert->booking->worker?->user?->phone))
                <a href="tel:{{ $alert->booking->worker->user->phone }}"
                    class="inline-flex items-center gap-2 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 px-3 py-2 text-sm font-semibold text-gray-700 dark:text-gray-200 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-800">
                    <x-filament::icon icon="heroicon-o-phone-arrow-up-right" class="h-4 w-4" />
                    اتصال بالعامل
                </a>
            @endif

            @if ($alert->status?->value !== 'resolved')
                <button type="button" wire:click="resolveAlert({{ $alert->id }})"
                    class="inline-flex items-center gap-2 rounded-xl bg-success-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-success-700">
                    <x-filament::icon icon="heroicon-o-check-circle" class="h-4 w-4" />
                    حل / إغلاق
                </button>
            @endif
        </div>
    </div>
</div>
