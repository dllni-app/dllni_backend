<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningWorkers\Schemas;

use App\Models\CleaningDepositTransaction;
use App\Models\Worker;
use BackedEnum;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Modules\Cleaning\Services\AdminCleaningTransactionService;
use Modules\Cleaning\Services\DepositService;

final class CleaningWorkerInfolist
{
    /** @var array<int, array<string, mixed>> */
    private static array $financialSnapshotCache = [];

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
            Section::make('الأداء المالي')
                ->description('الإيرادات والعمولات المرتبطة بالطلبات المنجزة للعامل.')
                ->columns(4)
                ->schema([
                    TextEntry::make('completed_jobs_financial')
                        ->label('المهام المنجزة')
                        ->state(fn (Worker $record): string => self::number(self::financialSnapshot($record)['completedJobs'])),
                    TextEntry::make('total_revenue_financial')
                        ->label('إجمالي الإيرادات')
                        ->state(fn (Worker $record): string => self::money(self::financialSnapshot($record)['totalRevenue'])),
                    TextEntry::make('current_admin_commission')
                        ->label('رصيد عمولة الإدارة الحالي')
                        ->state(fn (Worker $record): string => self::money(self::financialSnapshot($record)['adminCommissionBalance'])),
                    TextEntry::make('withdrawn_admin_revenue')
                        ->label('إيرادات الإدارة المسحوبة')
                        ->state(fn (Worker $record): string => self::money(self::financialSnapshot($record)['withdrawnAdminRevenueTotal'])),
                ]),
            Section::make('الحساب المالي والأهلية')
                ->description('الأرصدة الحالية والحد المسموح والعمولات المحجوزة التي تؤثر في استقبال الطلبات.')
                ->columns(3)
                ->schema([
                    TextEntry::make('current_deposit_balance')
                        ->label('رصيد الإيداع الحالي')
                        ->state(fn (Worker $record): string => self::money(self::financialSnapshot($record)['depositBalance'])),
                    TextEntry::make('current_debt_balance')
                        ->label('المديونية الحالية')
                        ->state(fn (Worker $record): string => self::money(self::financialSnapshot($record)['debtBalance'])),
                    TextEntry::make('allowed_debt_limit')
                        ->label('حد المديونية المسموح')
                        ->state(fn (Worker $record): string => self::money(self::financialSnapshot($record)['allowedDebtLimit'])),
                    TextEntry::make('reserved_active_commission')
                        ->label('العمولات المحجوزة للطلبات النشطة')
                        ->state(fn (Worker $record): string => self::money(self::financialSnapshot($record)['activeReservedCommission'])),
                    TextEntry::make('dispatch_eligibility')
                        ->label('أهلية استقبال الطلبات')
                        ->state(fn (Worker $record): string => app(DepositService::class)->isWorkerEligibleForNewRequests($record) ? 'مؤهل' : 'غير مؤهل')
                        ->badge()
                        ->color(fn (Worker $record): string => app(DepositService::class)->isWorkerEligibleForNewRequests($record) ? 'success' : 'danger'),
                ]),
            Section::make('الموقع والتوفر')
                ->description('بيانات الموقع وجدول العمل الأسبوعي للعامل.')
                ->columns(2)
                ->schema([
                    TextEntry::make('home_address')->label('عنوان المنزل')->placeholder('-')->columnSpanFull(),
                    TextEntry::make('home_latitude')->label('خط العرض')->placeholder('-'),
                    TextEntry::make('home_longitude')->label('خط الطول')->placeholder('-'),
                    ViewEntry::make('default_working_hours')
                        ->label('ساعات العمل الافتراضية')
                        ->getStateUsing(fn (Worker $record): array => $record->getNormalizedDefaultWorkingHours())
                        ->view('filament.resources.cleaning-workers.infolists.working-hours')
                        ->columnSpanFull(),
                ]),
            Section::make('سجل المعاملات المالية')
                ->description('يعرض أثر كل عملية على رصيد الإيداع والمديونية، وقيمة إيرادات الإدارة المرحلة عند تصفير الحساب.')
                ->schema([
                    RepeatableEntry::make('depositTransactions')
                        ->hiddenLabel()
                        ->state(fn (Worker $record) => $record->depositTransactions()->publiclyVisible()->with('createdByAdmin')->latest()->limit(100)->get())
                        ->schema([
                            TextEntry::make('type')->label('النوع')->badge()
                                ->color(fn (CleaningDepositTransaction $record): string => self::txTypeColor($record->publicType()))
                                ->formatStateUsing(fn (CleaningDepositTransaction $record): string => self::txTypeLabel($record->publicType())),
                            TextEntry::make('amount')->label('المبلغ')->money('SYP'),
                            TextEntry::make('admin_revenue_withdrawn_amount')
                                ->label('إيرادات الإدارة المسحوبة')
                                ->money('SYP')
                                ->visible(fn (CleaningDepositTransaction $record): bool => (float) ($record->admin_revenue_withdrawn_amount ?? 0) > 0),
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
    private static function financialSnapshot(Worker $worker): array
    {
        return self::$financialSnapshotCache[$worker->id] ??= app(AdminCleaningTransactionService::class)->snapshot($worker);
    }

    private static function txTypeLabel(string $type): string
    {
        return match ($type) {
            'deposit' => 'إيداع',
            'commission' => 'عمولة المنصة',
            'debt' => 'مديونية يدوية',
            'settlement' => 'تسوية مديونية',
            'refund' => 'تصفير الحساب المالي',
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
}
