<x-filament-hub.page-shell>
    <x-filament::section heading="الفترة">
        <div class="max-w-xs">
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="dateRange">
                    @foreach ($rangeOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
    </x-filament::section>

    <x-filament::section heading="المطاعم" description="القيم من settlement snapshot المحفوظة على الطلبات الجديدة؛ كوبون المنصة لا يخفض مستحق المطعم إلا بمقدار التمويل المنسوب للمطعم صراحةً.">
        <x-filament-hub.kpi-grid columns="md:grid-cols-3 xl:grid-cols-5">
            <x-filament-hub.kpi-stat label="طلبات مكتملة" :value="$restaurant['completed_orders']" format-value-as-integer />
            <x-filament-hub.kpi-stat label="إجمالي العميل" :value="$restaurant['customer_total']" />
            <x-filament-hub.kpi-stat label="عمولة المنصة" :value="$restaurant['commission']" tone="info" />
            <x-filament-hub.kpi-stat label="صافي المطاعم" :value="$restaurant['merchant_net']" tone="success" />
            <x-filament-hub.kpi-stat label="تكلفة كوبونات المنصة" :value="$restaurant['platform_coupon_cost']" tone="warning" />
            <x-filament-hub.kpi-stat label="تمويل المطاعم للكوبونات" :value="$restaurant['merchant_coupon_funding']" tone="warning" />
            <x-filament-hub.kpi-stat label="صافي إيراد المنصة" :value="$restaurant['platform_net']" />
            <x-filament-hub.kpi-stat label="رسوم الخدمة" :value="$restaurant['service_fees']" />
            <x-filament-hub.kpi-stat label="طلبات ملغاة" :value="$restaurant['cancelled_orders']" format-value-as-integer tone="warning" />
            <x-filament-hub.kpi-stat label="طلبات تاريخية بدون Snapshot" :value="$restaurant['unsnapshotted']" format-value-as-integer :tone="$restaurant['unsnapshotted'] > 0 ? 'warning' : 'success'" />
        </x-filament-hub.kpi-grid>
        @if ($restaurant['commission_setting_type'] !== null)
            <div class="mt-4 text-sm">
                إعداد العمولة الحالي للطلبات الجديدة:
                <strong>{{ $restaurant['commission_setting_type'] }} / {{ number_format((float) $restaurant['commission_setting_value'], 2) }}</strong>
            </div>
        @endif
        <div class="mt-4">
            <x-filament-hub.workflow-link :label="$restaurant['settlement_status']" :url="$restaurant['url']" tone="info" action-emphasis />
        </div>
    </x-filament::section>

    <x-filament::section heading="السوبرماركت" description="العمولة وصافي المتجر وتمويل Platform Coupons من snapshot محفوظة على كل طلب جديد.">
        <x-filament-hub.kpi-grid columns="md:grid-cols-3 xl:grid-cols-5">
            <x-filament-hub.kpi-stat label="طلبات مكتملة" :value="$supermarket['completed_orders']" format-value-as-integer />
            <x-filament-hub.kpi-stat label="إجمالي العميل" :value="$supermarket['customer_total']" />
            <x-filament-hub.kpi-stat label="عمولة المنصة" :value="$supermarket['commission']" tone="info" />
            <x-filament-hub.kpi-stat label="صافي المتاجر" :value="$supermarket['store_net']" tone="success" />
            <x-filament-hub.kpi-stat label="تكلفة كوبونات المنصة" :value="$supermarket['platform_coupon_cost']" tone="warning" />
            <x-filament-hub.kpi-stat label="تمويل المتاجر للكوبونات" :value="$supermarket['merchant_coupon_funding']" tone="warning" />
            <x-filament-hub.kpi-stat label="صافي إيراد المنصة" :value="$supermarket['platform_net']" />
            <x-filament-hub.kpi-stat label="رسوم الخدمة" :value="$supermarket['service_fees']" />
            <x-filament-hub.kpi-stat label="طلبات تاريخية بدون Snapshot" :value="$supermarket['unsnapshotted']" format-value-as-integer :tone="$supermarket['unsnapshotted'] > 0 ? 'warning' : 'success'" />
        </x-filament-hub.kpi-grid>
        @if ($supermarket['unsnapshotted'] > 0)
            <div class="mt-4">
                <x-filament-hub.workflow-link
                    :label="'طلبات تاريخية بدون financial snapshot: '.$supermarket['unsnapshotted']"
                    :url="$supermarket['url']"
                    tone="warning"
                    action-emphasis
                />
            </div>
        @endif
    </x-filament::section>

    <x-filament::section heading="التوصيل" description="القيم من Financial Ledger الحالي؛ الرصيد يمثل رصيد الحساب في دفتر التوصيل ولا يتم تفسيره كربح للمنصة.">
        <x-filament-hub.kpi-grid columns="md:grid-cols-2 xl:grid-cols-4">
            <x-filament-hub.kpi-stat label="إجمالي القيود المدينة" :value="$delivery['debits']" />
            <x-filament-hub.kpi-stat label="إجمالي التحصيلات الدائنة" :value="$delivery['credits']" />
            <x-filament-hub.kpi-stat label="أرصدة حسابات الشركات" :value="$delivery['company_balance']" />
            <x-filament-hub.kpi-stat label="حسابات موقوفة" :value="$delivery['suspended_accounts']" format-value-as-integer tone="danger" />
        </x-filament-hub.kpi-grid>
    </x-filament::section>

    <x-filament::section heading="آخر قيود التوصيل">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b">
                        <th class="p-2 text-start">الوقت</th>
                        <th class="p-2 text-start">الحساب</th>
                        <th class="p-2 text-start">النوع</th>
                        <th class="p-2 text-start">الاتجاه</th>
                        <th class="p-2 text-start">المبلغ</th>
                        <th class="p-2 text-start">الرصيد بعد القيد</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recentTransactions as $transaction)
                        <tr class="border-b">
                            <td class="p-2">{{ $transaction->created_at?->format('Y-m-d H:i') }}</td>
                            <td class="p-2">{{ $transaction->account?->owner?->name ?? ('#'.($transaction->account_id ?? '—')) }}</td>
                            <td class="p-2">{{ $transaction->transaction_type }}</td>
                            <td class="p-2">{{ $transaction->direction }}</td>
                            <td class="p-2">{{ number_format((float) $transaction->amount, 2) }}</td>
                            <td class="p-2">{{ number_format((float) $transaction->balance_after, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="p-6 text-center">لا توجد قيود في الفترة المحددة.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-hub.page-shell>
