<x-filament-panels::page>
    <x-filament::section heading="نموذج الإيرادات (العمولة)">
        <div class="grid gap-4 md:grid-cols-2">
            <div class="flex flex-col gap-2">
                <span class="text-sm font-medium">نوع العمولة</span>
                <select class="fi-select block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="commissionType">
                    <option value="percent">نسبة مئوية</option>
                    <option value="fixed">مبلغ مقطوع</option>
                </select>
            </div>
            @if($commissionType === 'percent')
                <label class="flex flex-col gap-1">
                    <span class="text-sm">نسبة العمولة الافتراضية (%)</span>
                    <input type="number" step="0.01" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="defaultCommissionRate">
                </label>
            @else
                <label class="flex flex-col gap-1">
                    <span class="text-sm">مبلغ العمولة المقطوع</span>
                    <input type="number" step="0.01" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="commissionFixedAmount">
                </label>
            @endif
            <label class="flex flex-col gap-1 md:col-span-2">
                <span class="text-sm">نسبة الضريبة (%)</span>
                <input type="number" step="0.01" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="vatRate">
            </label>
        </div>
    </x-filament::section>

    <x-filament::section heading="إعدادات بدل الانتقال">
        <div class="grid gap-4 md:grid-cols-2">
            <label class="flex flex-col gap-1">
                <span class="text-sm">نوع إضافة السفر</span>
                <select class="fi-select block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="travelMarkupType">
                    <option value="fixed">ثابت</option>
                    <option value="percent">نسبة مئوية</option>
                </select>
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-sm">قيمة إضافة السفر</span>
                <input type="number" step="0.01" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="travelMarkupValue">
            </label>
            <div class="flex flex-col gap-2 md:col-span-2">
                <span class="text-sm font-medium">نقطة انطلاق حساب المسافة</span>
                <select class="fi-select block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="travelDistanceStartPoint">
                    <option value="worker_current">من الموقع الحالي للعامل</option>
                    <option value="worker_home">من عنوان المنزل المسجل</option>
                    <option value="auto">تلقائي (النظام يختار)</option>
                </select>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section heading="سياسة المحاسبة على الوقت">
        <div class="grid gap-4 md:grid-cols-2">
            <div class="flex flex-col gap-2">
                <span class="text-sm font-medium">طريقة المحاسبة</span>
                <select class="fi-select block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="timeBillingMode">
                    <option value="full_booked">المحاسبة على كامل الوقت المحجوز</option>
                    <option value="actual">المحاسبة على الوقت الفعلي (موصى به)</option>
                </select>
            </div>
            @if($timeBillingMode === 'actual')
                <label class="flex flex-col gap-1">
                    <span class="text-sm">الحد الأدنى للدقائق القابلة للفوترة</span>
                    <input type="number" min="0" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="minBillableMinutes" placeholder="مثال: 120">
                </label>
            @endif
            <label class="flex flex-col gap-1">
                <span class="text-sm">دقائق قبل انتهاء الوقت لإرسال التنبيه</span>
                <input type="number" min="0" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="timeWarningMinutesBeforeEnd" placeholder="مثال: 15">
            </label>
        </div>
    </x-filament::section>

    <x-filament::section heading="حدود التغطية الجغرافية">
        <div class="grid gap-4 md:grid-cols-2">
            <label class="flex flex-col gap-1">
                <span class="text-sm">حد Low</span>
                <input type="number" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="coverageLow">
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-sm">حد OK</span>
                <input type="number" class="fi-input block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800" wire:model.live="coverageOk">
            </label>
        </div>
    </x-filament::section>

    <x-filament::button wire:click="save" color="primary">
        حفظ الإعدادات
    </x-filament::button>
</x-filament-panels::page>
