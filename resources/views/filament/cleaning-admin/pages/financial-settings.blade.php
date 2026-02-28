<x-filament-panels::page>
    <x-filament::section heading="الإعدادات المالية الأساسية">
        <div class="grid gap-4 md:grid-cols-2">
            <label class="flex flex-col gap-1">
                <span class="text-sm">نسبة العمولة الافتراضية (%)</span>
                <input type="number" step="0.01" class="fi-input block w-full rounded-lg border-gray-300" wire:model.live="defaultCommissionRate">
            </label>

            <label class="flex flex-col gap-1">
                <span class="text-sm">نسبة الضريبة (%)</span>
                <input type="number" step="0.01" class="fi-input block w-full rounded-lg border-gray-300" wire:model.live="vatRate">
            </label>

            <label class="flex flex-col gap-1">
                <span class="text-sm">نوع إضافة السفر</span>
                <select class="fi-select block w-full rounded-lg border-gray-300" wire:model.live="travelMarkupType">
                    <option value="fixed">ثابت</option>
                    <option value="percent">نسبة مئوية</option>
                </select>
            </label>

            <label class="flex flex-col gap-1">
                <span class="text-sm">قيمة إضافة السفر</span>
                <input type="number" step="0.01" class="fi-input block w-full rounded-lg border-gray-300" wire:model.live="travelMarkupValue">
            </label>
        </div>
    </x-filament::section>

    <x-filament::section heading="حدود التغطية">
        <div class="grid gap-4 md:grid-cols-2">
            <label class="flex flex-col gap-1">
                <span class="text-sm">حد Low</span>
                <input type="number" class="fi-input block w-full rounded-lg border-gray-300" wire:model.live="coverageLow">
            </label>

            <label class="flex flex-col gap-1">
                <span class="text-sm">حد OK</span>
                <input type="number" class="fi-input block w-full rounded-lg border-gray-300" wire:model.live="coverageOk">
            </label>
        </div>
    </x-filament::section>

    <x-filament::button wire:click="save" color="primary">
        حفظ الإعدادات
    </x-filament::button>
</x-filament-panels::page>
