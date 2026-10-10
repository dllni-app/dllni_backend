<x-filament-panels::page>
    <div dir="rtl" class="space-y-6">
        <div class="flex justify-end">
            <x-filament::button wire:click="refreshReport" icon="heroicon-o-arrow-path">تحديث الأرقام</x-filament::button>
        </div>
        @php
            $financial = $report['financial'] ?? [];
            $equipment = $report['equipment'] ?? [];
            $special = $report['specialServices'] ?? [];
            $workers = $report['workerOpportunities'] ?? [];
            $cards = [
                ['label' => 'قيمة الخدمات الخاصة المسجلة', 'value' => number_format((float) ($financial['recordedSpecialServiceCharges'] ?? 0), 2) . ' SYP'],
                ['label' => 'قيمة الخدمات المكتملة', 'value' => number_format((float) ($financial['recordedCompletedServiceCharges'] ?? 0), 2) . ' SYP'],
                ['label' => 'فرق مطابقة عناصر الفواتير', 'value' => number_format((float) ($financial['lineItemReconciliationDifference'] ?? 0), 2) . ' SYP'],
                ['label' => 'المتخصصون المؤهلون', 'value' => (int) ($workers['eligibleSpecialistsWithApprovedSkill'] ?? 0)],
                ['label' => 'حجوزات المعدات', 'value' => (int) ($equipment['reservationCount'] ?? 0)],
                ['label' => 'معدات تحتاج صيانة أو معطلة', 'value' => (int) ($equipment['maintenanceOrBrokenAssets'] ?? 0)],
                ['label' => 'إرجاعات بانتظار الإدارة', 'value' => (int) ($equipment['pendingAdministrativeReturns'] ?? 0)],
            ];
        @endphp
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($cards as $card)
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                    <div class="text-sm text-gray-500 dark:text-gray-400">{{ $card['label'] }}</div>
                    <div class="mt-2 text-xl font-bold text-gray-950 dark:text-white" dir="ltr">{{ $card['value'] }}</div>
                </div>
            @endforeach
        </div>
        <p class="text-sm text-gray-600 dark:text-gray-300">
            تنبيه مالي: الإيرادات المعروضة هنا أسعار خدمات مسجلة في الطلبات، وليست مبالغ تم تحصيلها.
            تتم مطابقة الإيرادات المحصلة والأرصدة مع دفتر القيود في التقرير المالي العام.
            فرق المطابقة يقارن إجمالي خطوط الخدمات بإجمالي عناصرها المسجلة.
        </p>
        <section class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-900">
            <h3 class="mb-3 font-bold text-gray-950 dark:text-white">الخدمات الخاصة بحسب النوع</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-right text-sm">
                    <thead class="border-b dark:border-gray-700"><tr>
                        <th class="px-3 py-2">الخدمة</th><th class="px-3 py-2">عدد الأسطر</th>
                        <th class="px-3 py-2">القيمة المسجلة (SYP)</th>
                    </tr></thead>
                    <tbody>
                    @forelse ($special['byService'] ?? [] as $row)
                        <tr class="border-b dark:border-gray-800">
                            <td class="px-3 py-2">{{ $row['name'] }}</td>
                            <td class="px-3 py-2">{{ $row['lines'] }}</td>
                            <td class="px-3 py-2" dir="ltr">{{ number_format($row['recordedCharges'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-3 py-3 text-gray-500">لا توجد خدمات مسجلة.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        <section class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-900">
            <h3 class="mb-2 font-bold text-gray-950 dark:text-white">عدالة فرص المتخصصين</h3>
            <p class="mb-3 text-xs text-gray-500">
                الإشعارات التي وصلت إلى العمال للطلبات المتخصصة والتخصيصات المقبولة خلال آخر {{ $workers['lookbackDays'] ?? 30 }} يومًا. يظهر كل مؤشر مستقلاً.
            </p>
            <div class="overflow-x-auto">
                <table class="w-full text-right text-sm">
                    <thead class="border-b dark:border-gray-700"><tr>
                        <th class="px-3 py-2">العامل</th><th class="px-3 py-2">الإشعارات المسجلة</th><th class="px-3 py-2">التخصيصات المقبولة</th>
                        <th class="px-3 py-2">آخر قبول</th>
                    </tr></thead>
                    <tbody>
                    @forelse (array_slice($workers['recentAcceptedWorkBySpecialist'] ?? [], 0, 50) as $worker)
                        <tr class="border-b dark:border-gray-800">
                            <td class="px-3 py-2">{{ $worker['name'] }}</td>
                            <td class="px-3 py-2">{{ $worker['recordedSpecialistOffers'] }}</td>
                            <td class="px-3 py-2">{{ $worker['recentAcceptedSlots'] }}</td>
                            <td class="px-3 py-2">{{ $worker['latestAcceptance'] ?: 'لا يوجد' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-3 py-3 text-gray-500">لا توجد بيانات توزيع.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        <section class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-900">
            <h3 class="mb-3 font-bold text-gray-950 dark:text-white">استخدام المعدات وحالات الصيانة</h3>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <h4 class="mb-2 text-sm font-semibold">حالة الأصول</h4>
                    @forelse ($equipment['assetStatuses'] ?? [] as $status => $count)
                        <div class="flex justify-between border-b py-2 text-sm dark:border-gray-800">
                            <span>{{ $status }}</span><strong>{{ $count }}</strong>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">لا توجد معدات.</p>
                    @endforelse
                </div>
                <div>
                    <h4 class="mb-2 text-sm font-semibold">حالة حجوزات المعدات</h4>
                    @forelse ($equipment['reservationStatuses'] ?? [] as $status => $count)
                        <div class="flex justify-between border-b py-2 text-sm dark:border-gray-800">
                            <span>{{ $status }}</span><strong>{{ $count }}</strong>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">لا توجد حجوزات.</p>
                    @endforelse
                </div>
            </div>
            @if (!empty($equipment['maintenanceAssets']))
                <div class="mt-5">
                    <h4 class="mb-2 text-sm font-semibold">المعدات المطلوب متابعتها بالصيانة</h4>
                    <div class="overflow-x-auto">
                        <table class="w-full text-right text-sm">
                            <thead class="border-b dark:border-gray-700">
                                <tr><th class="px-3 py-2">المعدة</th><th class="px-3 py-2">الرمز</th><th class="px-3 py-2">الحالة</th><th class="px-3 py-2">آخر إرجاع</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($equipment['maintenanceAssets'] as $asset)
                                    <tr class="border-b dark:border-gray-800">
                                        <td class="px-3 py-2">{{ $asset['name'] }}</td>
                                        <td class="px-3 py-2">{{ $asset['assetCode'] ?: '—' }}</td>
                                        <td class="px-3 py-2">{{ $asset['status'] }}</td>
                                        <td class="px-3 py-2">{{ $asset['lastReturnedAt'] ?: '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </section>
    </div>
</x-filament-panels::page>
