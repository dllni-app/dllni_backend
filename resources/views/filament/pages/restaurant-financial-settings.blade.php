<x-filament-hub.page-shell>
    <x-filament::section
        heading="قاعدة عمولة المطاعم"
        description="تطبق على الطلبات الجديدة فقط عند إنشاء financial snapshot. الطلبات السابقة لا يعاد احتسابها."
    >
        <form wire:submit="save" class="grid max-w-2xl gap-5 md:grid-cols-2">
            <div>
                <label class="mb-2 block text-sm font-medium">نوع العمولة</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model="commissionType">
                        <option value="percent">نسبة مئوية</option>
                        <option value="fixed">مبلغ ثابت</option>
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                @error('commissionType')
                    <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="mb-2 block text-sm font-medium">قيمة العمولة</label>
                <x-filament::input.wrapper>
                    <x-filament::input
                        type="number"
                        step="0.01"
                        min="0"
                        wire:model="commissionValue"
                    />
                </x-filament::input.wrapper>
                @error('commissionValue')
                    <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="md:col-span-2 rounded-xl border border-info-200 bg-info-50 p-4 text-sm text-info-800 dark:border-info-800 dark:bg-info-950/20 dark:text-info-200">
                كل تغيير ينشئ إعداداً جديداً ويحافظ على السجل السابق. الطلبات المكتملة تستمر باستخدام snapshot المحفوظة لحظة إنشائها.
            </div>

            @if ($this->canUpdateSetting())
                <div class="md:col-span-2">
                    <x-filament::button type="submit" icon="heroicon-o-check">
                        حفظ قاعدة العمولة
                    </x-filament::button>
                </div>
            @endif
        </form>
    </x-filament::section>
</x-filament-hub.page-shell>
