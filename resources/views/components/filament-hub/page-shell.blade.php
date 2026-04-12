@props([])

<x-filament-panels::page {{ $attributes }}>
    <div dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" class="space-y-6">
        {{ $slot }}
    </div>
</x-filament-panels::page>
