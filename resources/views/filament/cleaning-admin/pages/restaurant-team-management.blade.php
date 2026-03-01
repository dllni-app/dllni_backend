<x-filament-panels::page>
    <div dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" class="space-y-6">
        <x-filament::section :heading="__('restaurant_admin.team.title')" :description="__('restaurant_admin.team.description')">
            <div class="grid gap-4 md:grid-cols-2">
                @foreach($cards as $card)
                    <a href="{{ $card['url'] }}" class="rounded-xl border border-gray-200 bg-white p-5 transition hover:border-primary-500 hover:shadow dark:border-gray-700 dark:bg-gray-900">
                        <div class="text-sm font-semibold text-gray-900 dark:text-white">{{ $card['label'] }}</div>
                    </a>
                @endforeach
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
