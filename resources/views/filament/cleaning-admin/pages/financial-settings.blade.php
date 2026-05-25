<x-filament-panels::page>
    <x-filament::section heading="نموذج الإيرادات (العمولة)">
        <div class="grid gap-4 md:grid-cols-2">
            <div class="flex flex-col gap-2">
                <span class="text-sm font-medium">نوع العمولة</span>
                <select class="fi-select block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="commissionType">
                    <option value="percent">نسبة مئوية</option>
                    <option value="fixed">مبلغ ثابت</option>
                </select>
            </div>
            @if($commissionType === 'percent')
                <label class="flex flex-col gap-1">
                    <span class="text-sm">نسبة العمولة الافتراضية (%)</span>
                    <input type="number" min="0" step="0.01" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="defaultCommissionRate">
                    @error('defaultCommissionRate') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
                </label>
            @else
                <label class="flex flex-col gap-1">
                    <span class="text-sm">قيمة العمولة الثابتة</span>
                    <input type="number" min="0" step="0.01" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="commissionFixedAmount">
                    @error('commissionFixedAmount') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
                </label>
            @endif
            <label class="flex flex-col gap-1 md:col-span-2">
                <span class="text-sm">نسبة ضريبة القيمة المضافة (%)</span>
                <input type="number" min="0" step="0.01" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="vatRate">
                @error('vatRate') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </label>
        </div>
    </x-filament::section>

    <x-filament::section heading="إعدادات تكلفة التنقل">
        <div class="grid gap-4 md:grid-cols-2">
            <label class="flex flex-col gap-1">
                <span class="text-sm">نوع زيادة التنقل</span>
                <select class="fi-select block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="travelMarkupType">
                    <option value="fixed">ثابت</option>
                    <option value="percent">نسبة مئوية</option>
                </select>
                @error('travelMarkupType') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-sm">قيمة زيادة التنقل</span>
                <input type="number" min="0" step="0.01" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="travelMarkupValue">
                @error('travelMarkupValue') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-sm">سعر التنقل لكل كم</span>
                <input type="number" min="0" step="0.01" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="travelPerKm">
                @error('travelPerKm') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </label>
            <div class="flex flex-col gap-2">
                <span class="text-sm font-medium">نقطة بداية المسافة</span>
                <select class="fi-select block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="travelDistanceStartPoint">
                    <option value="worker_home">موقع منزل العامل</option>
                </select>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section heading="سياسة فوترة الوقت">
        <div class="grid gap-4 md:grid-cols-2">
            <div class="flex flex-col gap-2">
                <span class="text-sm font-medium">وضع الفوترة</span>
                <select class="fi-select block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="timeBillingMode">
                    <option value="full_booked">فوترة كامل الوقت المحجوز</option>
                    <option value="actual">فوترة وقت العمل الفعلي (موصى به)</option>
                </select>
                @error('timeBillingMode') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </div>
            @if($timeBillingMode === 'actual')
                <label class="flex flex-col gap-1">
                    <span class="text-sm">الحد الأدنى للدقائق القابلة للفوترة</span>
                    <input type="number" min="0" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="minBillableMinutes" placeholder="مثال: 120">
                    @error('minBillableMinutes') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
                </label>
            @endif
            <label class="flex flex-col gap-1">
                <span class="text-sm">عدد الدقائق قبل الانتهاء لإرسال التنبيه</span>
                <input type="number" min="0" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="timeWarningMinutesBeforeEnd" placeholder="مثال: 15">
                @error('timeWarningMinutesBeforeEnd') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </label>
        </div>
    </x-filament::section>

    <x-filament::section heading="عتبات التغطية">
        <div class="grid gap-4 md:grid-cols-2">
            <label class="flex flex-col gap-1">
                <span class="text-sm">الحد المنخفض</span>
                <input type="number" min="0" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="coverageLow">
                @error('coverageLow') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-sm">الحد المقبول</span>
                <input type="number" min="0" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="coverageOk">
                @error('coverageOk') <span class="text-xs text-danger-600">{{ $message }}</span> @enderror
            </label>
        </div>
    </x-filament::section>

    <x-filament::button wire:click="save" color="primary">
        حفظ الإعدادات
    </x-filament::button>
</x-filament-panels::page>
