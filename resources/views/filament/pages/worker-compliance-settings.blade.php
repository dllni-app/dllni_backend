<x-filament-panels::page>
    <x-filament::section>
        <p class="text-sm text-gray-700 dark:text-gray-300">
            {{ __('worker_compliance.settings.read_only') }}
        </p>
        <p class="mt-3 text-sm text-amber-700 dark:text-amber-300">
            {{ __('worker_compliance.settings.release_gate') }}
        </p>
    </x-filament::section>

    @foreach ($configurationGroups as $heading => $entries)
        <x-filament::section :heading="$heading">
            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($entries as $label => $value)
                    <div class="min-w-0 rounded-lg bg-gray-50 px-4 py-3 dark:bg-gray-900/50">
                        <dt class="text-xs font-medium text-gray-600 dark:text-gray-400">{{ $label }}</dt>
                        <dd class="mt-1 break-words text-sm font-semibold text-gray-950 dark:text-white">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
