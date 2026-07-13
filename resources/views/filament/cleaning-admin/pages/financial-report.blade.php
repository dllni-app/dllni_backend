<x-filament-panels::page>
    <div dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" class="space-y-6">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($metrics as $metric)
                @php
                    $tone = $metric['tone'] ?? 'primary';
                    $accent = match ($tone) {
                        'success' => 'text-success-600 dark:text-success-400',
                        'danger' => 'text-danger-600 dark:text-danger-400',
                        'warning' => 'text-warning-600 dark:text-warning-400',
                        default => 'text-primary-600 dark:text-primary-400',
                    };
                @endphp
                <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $metric['label'] }}</p>
                    <p class="mt-2 text-2xl font-extrabold {{ $accent }}">{{ $metric['value'] }}</p>
                </div>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
