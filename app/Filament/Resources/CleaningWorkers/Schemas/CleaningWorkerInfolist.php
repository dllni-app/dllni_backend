<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkers\Schemas;

use App\Models\CleaningDepositTransaction;
use App\Models\Worker;
use BackedEnum;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Modules\Cleaning\Services\DepositService;
use Modules\Cleaning\Services\WorkerOrderSolvencyService;

final class CleaningWorkerInfolist
{
    /** @var array<int, array<string, mixed>> */
    private static array $summaryCache = [];

    /** @var array<int, array<string, mixed>> */
    private static array $capacityCache = [];

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('ملخص العامل')
                ->description('نظرة سريعة على حالة العامل ومؤشرات الأداء الأساسية.')
                ->columns(4)
                ->schema([
                    ImageEntry::make('avatar_preview')
                        ->label('الصورة')
                        ->getStateUsing(fn (Worker $record): ?string => $record->getFirstMediaUrl('avatar') ?: null)
                        ->defaultImageUrl(fn (Worker $record): string => self::fallbackAvatarUrl($record))
                        ->circular()
                        ->imageHeight(88),
                    TextEntry::make('display_name')
                        ->label('الاسم')
                        ->state(fn (Worker $record): string => $record->user?->name ?: $record->first_name ?: '-')
                        ->weight('bold'),
                    TextEntry::make('account_status')
                        ->label('حالة الحساب')
                        ->state(fn (Worker $record): string => $record->is_suspended ? 'موقوف' : ($record->is_active ? 'نشط' : 'غير نشط'))
                        ->badge()
                        ->color(fn (string $state): string => $state === 'موقوف' ? 'danger' : 'gray'),
                    TextEntry::make('security_deposit_status')
                        ->label('حالة الحساب المالي')
                        ->formatStateUsing(fn (?string $state): string => self::depositStatusLabel($state))
                        ->badge()
                        ->color(fn (?string $state): string => self::depositStatusColor($state)),
                    TextEntry::make('trust_score')->label('درجة الثقة')->formatStateUsing(fn ($state): string => self::number($state).' / 100'),
                    TextEntry::make('average_rating')->label('متوسط التقييم')->formatStateUsing(fn ($state): string => self::decimal($state).' / 5'),
                    TextEntry::make('acceptance_rate')->label('معدل القبول')->formatStateUsing(fn ($state): string => self::decimal($state).'%'),
                    TextEntry::make('open_disputes_count')->label('النزاعات المفتوحة')->formatStateUsing(fn ($state): string => self::number($state)),
                ]),
            Section::make('بيانات الحساب والملف')
                ->description('معلومات التواصل والبيانات الشخصية المستخدمة في الدعم والتشغيل.')
                ->columns(3)
                ->schema([
                    TextEntry::make('user.email')->label('البريد الإلكتروني')->placeholder('-')->copyable(),
                    TextEntry::make('user.phone')->label('رقم الهاتف')->placeholder('-')->copyable(),
                    TextEntry::make('gender')->label('الجنس')->formatStateUsing(fn ($state): string => self::genderLabel($state))->placeholder('-'),
                    TextEntry::make('preferred_work_type')->label('نوع العمل المفضل')->formatStateUsing(fn ($state): string => self::preferredWorkTypeLabel($state))->placeholder('-'),
                    TextEntry::make('bio')->label('نبذة')->placeholder('-')->columnSpanFull(),
                ]),
            Section::make('الأداء والمالية')
                ->description('مؤشرات الإيرادات والعمولات الخاصة بالعامل.')
                ->columns(4)
                ->schema([
                    TextEntry::make('total_completed_jobs')->label('المهام المنجزة')->formatStateUsing(fn ($state): string => self::number($state)),
                    TextEntry::make('gross_revenue')->label('إجمالي الإيرادات')->state(fn (Worker $record): string => self::money(self::summary($record)['totalRevenue'])),
                    TextEntry::make('worker_net_earnings')->label('صافي مستحقات العامل')->state(fn (Worker $record): string => self::money(self::workerNetEarnings($record))),
                    TextEntry::make('admin_margin_total')->label('إجمالي عمولة المنصة')->state(fn (Worker $record): string => self::money(self::summary($record)['totalCommission'])),
                ]),
            Section::make('الإيداع والمديونية والأهلية')
                ->description('الإيداع والمديونية رصيدان منفصلان. تستخدم عمولات الطلبات الإيداع أولاً ثم سعة المديونية المتبقية.')
                ->columns(4)
                ->schema([
                    TextEntry::make('deposit.current_balance')->label('رصيد الإيداع')->money('SYP')->placeholder('SYP 0.00'),
                    TextEntry::make('deposit.debt_balance')->label('المديونية الحالية')->money('SYP')->placeholder('SYP 0.00'),
                    TextEntry::make('deposit.max_negative_balance')->label('حد المديونية المسموح')->money('SYP')->placeholder('SYP 0.00'),
                    TextEntry::make('remaining_debt_capacity')->label('سعة المديونية المتبقية')->state(fn (Worker $record): string => self::money(self::capacity($record)['remainingDebtCapacity'])),
                    TextEntry::make('active_reserved_commission')->label('العمولات المحجوزة')->state(fn (Worker $record): string => self::money(self::capacity($record)['activeReservedCommission'])),
                    TextEntry::make('available_commission_capacity')->label('السعة المالية للطلبات')->state(fn (Worker $record): string => self::money(self::capacity($record)['availableCommissionCapacity'])),
                    TextEntry::make('deposit.deposited_total')->label('إجمالي المبالغ المستلمة')->money('SYP')->placeholder('SYP 0.00'),
                    TextEntry::make('deposit.withdrawn_total')->label('إجمالي الاسترداد')->money('SYP')->placeholder('SYP 0.00'),
                    TextEntry::make('dispatch_eligibility')
                        ->label('أهلية استقبال الطلبات')
                        ->state(fn (Worker $record): string => app(DepositService::class)->isWorkerEligibleForNewRequests($record) ? 'مؤهل' : 'غير مؤهل')
                        ->badge()
                        ->color(fn (Worker $record): string => app(DepositService::class)->isWorkerEligibleForNewRequests($record) ? 'success' : 'danger'),
                ]),
            Section::make('الموقع والتوفر')
                ->description('بيانات الموقع وساعات العمل الأساسية.')
                ->columns(2)
                ->schema([
                    TextEntry::make('home_address')->label('عنوان المنزل')->placeholder('-')->columnSpanFull(),
                    TextEntry::make('home_latitude')->label('خط العرض')->placeholder('-'),
                    TextEntry::make('home_longitude')->label('خط الطول')->placeholder('-'),
                    TextEntry::make('default_working_hours')->label('ساعات العمل الافتراضية')->state(fn (Worker $record): array => self::workingHours($record->default_working_hours))->listWithLineBreaks()->columnSpanFull(),
                ]),
            Section::make('الملخص المالي')
                ->description('القيم الحالية والتاريخية للإيداع والمديونية والعمولات.')
                ->columns(4)
                ->schema([
                    TextEntry::make('fin_current_deposit')->label('رصيد الإيداع الحالي')->state(fn (Worker $record): string => self::money(self::summary($record)['currentDeposit'])),
                    TextEntry::make('fin_current_debt')->label('المديونية الحالية')->state(fn (Worker $record): string => self::money(self::summary($record)['debtBalance'])),
                    TextEntry::make('fin_completed_jobs')->label('إجمالي المهام المنجزة')->state(fn (Worker $record): string => self::number(self::summary($record)['completedJobs'])),
                    TextEntry::make('fin_total_revenue')->label('إجمالي الإيرادات')->state(fn (Worker $record): string => self::money(self::summary($record)['totalRevenue'])),
                    TextEntry::make('fin_total_commission')->label('إجمالي عمولة المنصة')->state(fn (Worker $record): string => self::money(self::summary($record)['totalCommission'])),
                    TextEntry::make('fin_total_settled')->label('إجمالي التسويات')->state(fn (Worker $record): string => self::money(self::summary($record)['totalSettled'])),
                    TextEntry::make('fin_total_refunded')->label('إجمالي الاسترداد')->state(fn (Worker $record): string => self::money(self::summary($record)['totalRefunded'])),
                    TextEntry::make('fin_status')->label('حالة الحساب المالي')
                        ->state(fn (Worker $record): string => self::accountStatusLabel(self::summary($record)['status']))
                        ->badge()
                        ->color(fn (Worker $record): string => self::accountStatusColor(self::summary($record)['status'])),
                ]),
            Section::make('سجل المعاملات المالية')
                ->description('عمليات الإيداع وعمولة المنصة والمديونية والتسوية والاسترداد مع رصيدي الإيداع والمديونية بعد كل عملية.')
                ->schema([
                    RepeatableEntry::make('depositTransactions')
                        ->hiddenLabel()
                        ->state(fn (Worker $record) => $record->depositTransactions()->publiclyVisible()->with('createdByAdmin')->latest()->limit(100)->get())
                        ->schema([
                            TextEntry::make('type')->label('النوع')->badge()
                                ->color(fn (CleaningDepositTransaction $record): string => self::txTypeColor($record->publicType()))
                                ->formatStateUsing(fn (CleaningDepositTransaction $record): string => self::txTypeLabel($record->publicType())),
                            TextEntry::make('amount')->label('المبلغ')->money('SYP'),
                            TextEntry::make('balance_after')->label('رصيد الإيداع بعد العملية')->money('SYP'),
                            TextEntry::make('debt_balance_after')->label('المديونية بعد العملية')->money('SYP'),
                            TextEntry::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i'),
                            TextEntry::make('notes')->label('ملاحظات')->placeholder('-'),
                            TextEntry::make('createdByAdmin.name')->label('بواسطة')->placeholder('—'),
                        ])
                        ->columns(4),
                ]),
        ]);
    }

    /** @return array<string, mixed> */
    private static function summary(Worker $worker): array
    {
        return self::$summaryCache[$worker->id] ??= app(DepositService::class)->financialSummary($worker);
    }

    /** @return array<string, mixed> */
    private static function capacity(Worker $worker): array
    {
        return self::$capacityCache[$worker->id] ??= app(WorkerOrderSolvencyService::class)->workerCapacitySummary($worker);
    }

    private static function accountStatusLabel(string $status): string
    {
        return match ($status) {
            'active' => 'نشط',
            'restricted' => 'السعة المالية غير كافية',
            'suspended' => 'موقوف',
            'inactive' => 'غير نشط',
            default => $status,
        };
    }

    private static function accountStatusColor(string $status): string
    {
        return match ($status) {
            'active' => 'success',
            'restricted' => 'danger',
            'suspended' => 'warning',
            default => 'gray',
        };
    }

    private static function txTypeLabel(string $type): string
    {
        return match ($type) {
            'deposit' => 'إيداع',
            'commission' => 'عمولة المنصة',
            'debt' => 'مديونية يدوية',
            'settlement' => 'تسوية مديونية',
            'refund' => 'استرداد من الإيداع',
            default => $type,
        };
    }

    private static function txTypeColor(string $type): string
    {
        return match ($type) {
            'deposit' => 'success',
            'commission' => 'info',
            'debt' => 'danger',
            'settlement' => 'primary',
            'refund' => 'warning',
            default => 'gray',
        };
    }

    private static function fallbackAvatarUrl(Worker $worker): string
    {
        $name = rawurlencode($worker->user?->name ?: $worker->first_name ?: 'Worker');

        return "https://ui-avatars.com/api/?name={$name}&background=f3f4f6&color=111827";
    }

    private static function number(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 0);
    }

    private static function decimal(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 1);
    }

    private static function money(mixed $value): string
    {
        return 'SYP '.number_format((float) ($value ?? 0), 2);
    }

    private static function depositStatusLabel(?string $status): string
    {
        return match ($status) {
            'active' => 'نشط',
            'insufficient_balance' => 'السعة المالية غير كافية',
            'suspended' => 'موقوف',
            default => 'غير محدد',
        };
    }

    private static function depositStatusColor(?string $status): string
    {
        return match ($status) {
            'active' => 'success',
            'insufficient_balance' => 'danger',
            'suspended' => 'warning',
            default => 'gray',
        };
    }

    private static function genderLabel(mixed $state): string
    {
        return match ((string) $state) {
            'male' => 'ذكر',
            'female' => 'أنثى',
            default => '-',
        };
    }

    private static function preferredWorkTypeLabel(mixed $state): string
    {
        $value = $state instanceof BackedEnum ? $state->value : (string) $state;

        return match ($value) {
            'cleaning' => 'تنظيف',
            'events' => 'مناسبات',
            'both' => 'كلاهما',
            default => '-',
        };
    }

    private static function workerNetEarnings(Worker $worker): float
    {
        $summary = self::summary($worker);

        return max(0.0, (float) $summary['totalRevenue'] - (float) $summary['totalCommission']);
    }

    /** @return array<int, string> */
    private static function workingHours(mixed $state): array
    {
        if (! is_array($state) || $state === []) {
            return ['غير محدد'];
        }

        $days = [
            'sunday' => 'الأحد', 'monday' => 'الإثنين', 'tuesday' => 'الثلاثاء',
            'wednesday' => 'الأربعاء', 'thursday' => 'الخميس', 'friday' => 'الجمعة', 'saturday' => 'السبت',
        ];

        $lines = [];
        foreach ($days as $key => $label) {
            $day = $state[$key] ?? null;
            if (! is_array($day) || (isset($day['available']) && ! $day['available'])) {
                $lines[] = $label.': غير متاح';

                continue;
            }

            $periods = is_array($day['data'] ?? null) ? $day['data'] : [$day];
            $ranges = [];
            foreach ($periods as $period) {
                if (is_array($period) && isset($period['from'], $period['to'])) {
                    $ranges[] = $period['from'].' - '.$period['to'];
                }
            }
            $lines[] = $label.': '.($ranges === [] ? 'غير متاح' : implode('، ', $ranges));
        }

        return $lines;
    }
}
