<x-filament-panels::page>
    <x-filament::section heading="المبالغ المستحقة على المشرفين" description="الأرقام من التصاريح النقدية للزيارات المكتملة فقط. لا يتم إجراء أي تحويل إلكتروني. يتم تسجيل الاستلام من تفاصيل الطلب بعد تسليم المبلغ للشركة.">
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-start">
                <thead>
                    <tr class="border-b dark:border-gray-700">
                        <th class="p-3 text-start">المشرف</th>
                        <th class="p-3 text-start">المبالغ المبلّغ عنها (ل.س)</th>
                        <th class="p-3 text-start">أجور العمال (ل.س)</th>
                        <th class="p-3 text-start">المستحق للشركة (ل.س)</th>
                        <th class="p-3 text-start">المسلّم للشركة (ل.س)</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($this->accounts() as $account)
                        <tr class="border-b dark:border-gray-700">
                            <td class="p-3">{{ $account['name'] }}</td>
                            <td class="p-3">{{ $account['reportedCollectedSyp'] }}</td>
                            <td class="p-3">{{ $account['reportedWorkerPaymentsSyp'] }}</td>
                            <td class="p-3 font-semibold">{{ $account['companyOutstandingSyp'] }}</td>
                            <td class="p-3">{{ $account['companyDeliveredSyp'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="p-6 text-center">لا توجد سجلات محاسبة نقدية بعد.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
