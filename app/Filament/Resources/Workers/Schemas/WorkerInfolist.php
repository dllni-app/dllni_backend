<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workers\Schemas;

use App\Models\CleaningDepositSetting;
use App\Models\CleaningWorkerDeposit;
use App\Enums\WorkerPreferredWorkType;
use App\Models\Worker;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Group;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;

final class WorkerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $yesNo = fn ($state): string => $state ? __('cleaning_admin.boolean.yes') : __('cleaning_admin.boolean.no');

        return $schema
            ->components([
                Section::make(__('cleaning_admin.workers.sections.account'))
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                ImageEntry::make('photo')
                                    ->label('')
                                    ->getStateUsing(fn ($record) => $record->getFirstMediaUrl('avatar') ?: null)
                                    ->defaultImageUrl(fn () => 'https://ui-avatars.com/api/?name=W&background=random'),
                                Group::make()
                                    ->schema([
                                        TextEntry::make('user_id')
                                            ->label(__('cleaning_admin.workers.fields.user'))
                                            ->placeholder('-'),
                                        TextEntry::make('user.name')
                                            ->label(__('cleaning_admin.workers.fields.user_name'))
                                            ->placeholder('-'),
                                        TextEntry::make('user.phone')
                                            ->label(__('cleaning_admin.workers.fields.phone'))
                                            ->placeholder('-'),
                                        TextEntry::make('user.password')
                                            ->label(__('cleaning_admin.workers.fields.password'))
                                            ->formatStateUsing(fn ($state): string => filled($state) ? '********' : '-')
                                            ->placeholder('-'),
                                    ])
                                    ->columnSpan(2),
                            ]),
                    ]),
                Section::make('الإحصائيات')
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('trust_score_stat')
                                    ->label('درجة الثقة')
                                    ->state(fn (Worker $record): string => self::formatTrustScore($record))
                                    ->suffix('/ 100')
                                    ->weight('bold'),
                                TextEntry::make('total_completed_jobs_stat')
                                    ->label('المهام المنجزة')
                                    ->state(fn (Worker $record): string => self::formatInteger($record->total_completed_jobs))
                                    ->weight('bold'),
                                TextEntry::make('average_rating_stat')
                                    ->label('متوسط التقييم')
                                    ->state(fn (Worker $record): string => self::formatDecimal($record->average_rating))
                                    ->suffix('/ 5'),
                                TextEntry::make('open_disputes_count_stat')
                                    ->label('النزاعات المفتوحة')
                                    ->state(fn (Worker $record): string => self::formatInteger($record->open_disputes_count)),
                            ]),
                    ]),
                Section::make(__('cleaning_admin.workers.sections.profile'))
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('first_name')
                                    ->label(__('cleaning_admin.workers.fields.first_name'))
                                    ->placeholder('-'),
                                TextEntry::make('gender')
                                    ->label(__('cleaning_admin.workers.fields.gender'))
                                    ->formatStateUsing(fn ($state): string => self::formatGender($state))
                                    ->placeholder('-'),
                                TextEntry::make('birthday')
                                    ->label(__('cleaning_admin.workers.fields.birthday'))
                                    ->date('Y-m-d')
                                    ->placeholder('-'),
                                TextEntry::make('preferred_work_type')
                                    ->label(__('cleaning_admin.workers.fields.preferred_work_type'))
                                    ->formatStateUsing(fn ($state): string => self::formatPreferredWorkType($state))
                                    ->placeholder('-'),
                                TextEntry::make('bio')
                                    ->label(__('cleaning_admin.workers.fields.bio'))
                                    ->placeholder('-')
                                    ->columnSpanFull(),
                                Group::make()
                                    ->schema([
                                        TextEntry::make('is_active')
                                            ->label(__('cleaning_admin.workers.fields.is_active'))
                                            ->formatStateUsing($yesNo),
                                        TextEntry::make('is_suspended')
                                            ->label(__('cleaning_admin.workers.fields.suspended'))
                                            ->formatStateUsing($yesNo),
                                        TextEntry::make('is_verified')
                                            ->label(__('cleaning_admin.workers.fields.is_verified'))
                                            ->formatStateUsing($yesNo),
                                        TextEntry::make('is_featured')
                                            ->label(__('cleaning_admin.workers.fields.is_featured'))
                                            ->formatStateUsing($yesNo),
                                    ])
                                    ->columns(2)
                                    ->columnSpanFull(),
                            ]),
                    ]),
                Section::make(__('cleaning_admin.workers.sections.metrics'))
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('average_rating')
                                    ->label(__('cleaning_admin.workers.fields.average_rating'))
                                    ->suffix(' / 5')
                                    ->placeholder('-'),
                                TextEntry::make('acceptance_rate')
                                    ->label(__('cleaning_admin.workers.fields.acceptance_rate'))
                                    ->suffix('%')
                                    ->placeholder('-'),
                                TextEntry::make('cancellation_rate')
                                    ->label(__('cleaning_admin.workers.fields.cancellation_rate'))
                                    ->suffix('%')
                                    ->placeholder('-'),
                            ]),
                    ]),
                Section::make('ملخص المبالغ')
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('gross_revenue')
                                    ->label('إجمالي الإيرادات')
                                    ->state(fn (Worker $record): string => self::money(self::financialSummary($record)['gross_revenue']))
                                    ->weight('bold'),
                                TextEntry::make('worker_net_earnings')
                                    ->label('صافي مستحقات العامل')
                                    ->state(fn (Worker $record): string => self::money(self::financialSummary($record)['worker_net_earnings']))
                                    ->weight('bold'),
                                TextEntry::make('admin_margin_total')
                                    ->label('هامش الإدارة')
                                    ->state(fn (Worker $record): string => self::money(self::financialSummary($record)['admin_margin_total']))
                                    ->weight('bold'),
                                TextEntry::make('completed_jobs_count')
                                    ->label('عدد المهام المكتملة')
                                    ->state(fn (Worker $record): string => self::formatInteger(self::financialSummary($record)['completed_jobs_count']))
                                    ->weight('bold'),
                            ]),
                    ]),
                Section::make('ملخص التأمين')
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('security_deposit_status')
                                    ->label('حالة التأمين')
                                    ->badge()
                                    ->color(fn (mixed $state): string => self::depositStatusColor($state))
                                    ->columnSpanFull()
                                    ->state(fn (Worker $record): string => self::depositStatusLabel($record)),
                                TextEntry::make('current_balance')
                                    ->label('الرصيد الحالي')
                                    ->state(fn (Worker $record): string => self::money(self::depositSummary($record)['current_balance']))
                                    ->weight('bold'),
                                TextEntry::make('deposited_total')
                                    ->label('إجمالي الإيداع')
                                    ->state(fn (Worker $record): string => self::money(self::depositSummary($record)['deposited_total']))
                                    ->weight('bold'),
                                TextEntry::make('withdrawn_total')
                                    ->label('إجمالي السحب')
                                    ->state(fn (Worker $record): string => self::money(self::depositSummary($record)['withdrawn_total']))
                                    ->weight('bold'),
                                TextEntry::make('minimum_required')
                                    ->label('الحد الأدنى المطلوب')
                                    ->state(fn (Worker $record): string => self::money(self::depositSummary($record)['minimum_required']))
                                    ->weight('bold'),
                            ]),
                    ]),
                Section::make(__('cleaning_admin.workers.sections.location'))
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextEntry::make('home_address')
                                    ->label(__('cleaning_admin.workers.fields.home_address'))
                                    ->placeholder('-')
                                    ->columnSpanFull(),
                                TextEntry::make('home_latitude')
                                    ->label(__('cleaning_admin.workers.fields.home_latitude'))
                                    ->placeholder('-'),
                                TextEntry::make('home_longitude')
                                    ->label(__('cleaning_admin.workers.fields.home_longitude'))
                                    ->placeholder('-'),
                                TextEntry::make('featured_until')
                                    ->label(__('cleaning_admin.workers.fields.featured_until'))
                                    ->dateTime('Y-m-d H:i')
                                    ->placeholder('-'),
                                TextEntry::make('suspended_until')
                                    ->label(__('cleaning_admin.workers.fields.suspended_until'))
                                    ->dateTime('Y-m-d H:i')
                                    ->placeholder('-'),
                                TextEntry::make('default_working_hours')
                                    ->label(__('cleaning_admin.workers.fields.default_working_hours'))
                                    ->formatStateUsing(fn ($state): string => self::formatWorkingHours($state))
                                    ->columnSpanFull()
                                    ->placeholder('-'),
                            ]),
                    ]),
                Section::make(__('cleaning_admin.workers.sections.trust_card'))
                    ->schema([
                        TextEntry::make('trust_score')
                            ->label(__('cleaning_admin.workers.fields.trust_score'))
                            ->suffix(' / 100')
                            ->weight('bold'),
                        RepeatableEntry::make('trustLogs')
                            ->label(__('cleaning_admin.workers.fields.trust_log'))
                            ->schema([
                                TextEntry::make('reason')->label(__('cleaning_admin.workers.fields.reason')),
                                TextEntry::make('score_delta')->label(__('cleaning_admin.workers.fields.score_delta'))->suffix(' points'),
                                TextEntry::make('created_at')->label(__('cleaning_admin.workers.fields.date'))->dateTime('Y-m-d H:i'),
                            ])
                            ->columns(3),
                    ]),
                Section::make(__('cleaning_admin.workers.sections.preferred_zones'))
                    ->schema([
                        RepeatableEntry::make('zones')
                            ->label('')
                            ->schema([
                                TextEntry::make('name')->label(__('cleaning_admin.workers.fields.zone')),
                                TextEntry::make('is_active')->label(__('cleaning_admin.workers.fields.is_active'))->formatStateUsing($yesNo),
                            ])
                            ->columns(2),
                    ]),
            ]);
    }

    private static function formatGender(mixed $state): string
    {
        $value = is_string($state) ? $state : null;

        if ($value === null || $value === '') {
            return '-';
        }

        return __('cleaning_admin.workers.gender_options.'.$value) ?: $value;
    }

    private static function formatPreferredWorkType(mixed $state): string
    {
        $value = $state instanceof WorkerPreferredWorkType
            ? $state->value
            : (is_string($state) ? $state : WorkerPreferredWorkType::Both->value);

        return WorkerPreferredWorkType::options()[$value] ?? $value;
    }

    private static function formatWorkingHours(mixed $state): string
    {
        if (! is_array($state) || $state === []) {
            return '-';
        }

        return json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '-';
    }

    /**
     * @return array{completed_jobs_count:int,gross_revenue:float,worker_net_earnings:float,admin_margin_total:float}
     */
    private static function financialSummary(Worker $worker): array
    {
        static $cache = [];

        if (isset($cache[$worker->id])) {
            return $cache[$worker->id];
        }

        $bookings = CleaningBooking::query()
            ->where(function (Builder $query) use ($worker): void {
                $query->where('worker_id', $worker->id)
                    ->orWhereHas('workerAssignments', function (Builder $assignments) use ($worker): void {
                        $assignments
                            ->where('worker_id', $worker->id)
                            ->where('status', CleaningBookingWorkerAssignmentStatus::Accepted->value);
                    });
            })
            ->where('status', CleaningBookingStatus::Completed->value)
            ->with(['workerAssignments' => function (HasMany $assignments) use ($worker): void {
                $assignments->where('worker_id', $worker->id);
            }])
            ->get();

        $grossRevenue = (float) $bookings->sum(fn (CleaningBooking $booking): float => self::bookingGrossAmount($booking));
        $adminMarginTotal = (float) $bookings->sum(fn (CleaningBooking $booking): float => self::bookingAdminAmount($booking, $worker->id));
        $workerNetEarnings = max(0.0, round($grossRevenue - $adminMarginTotal, 2));

        return $cache[$worker->id] = [
            'completed_jobs_count' => $bookings->count(),
            'gross_revenue' => round($grossRevenue, 2),
            'worker_net_earnings' => round($workerNetEarnings, 2),
            'admin_margin_total' => round($adminMarginTotal, 2),
        ];
    }

    /**
     * @return array{current_balance:float,deposited_total:float,withdrawn_total:float,minimum_required:float}
     */
    private static function depositSummary(Worker $worker): array
    {
        static $cache = [];

        if (isset($cache[$worker->id])) {
            return $cache[$worker->id];
        }

        $deposit = $worker->deposit instanceof CleaningWorkerDeposit ? $worker->deposit : null;
        $setting = CleaningDepositSetting::query()->first();

        return $cache[$worker->id] = [
            'current_balance' => (float) ($deposit?->current_balance ?? 0),
            'deposited_total' => (float) ($deposit?->deposited_total ?? 0),
            'withdrawn_total' => (float) ($deposit?->withdrawn_total ?? 0),
            'minimum_required' => (float) ($setting?->minimum_deposit_amount ?? 0),
        ];
    }

    private static function depositStatusLabel(Worker $worker): string
    {
        $status = is_string($worker->security_deposit_status) && $worker->security_deposit_status !== ''
            ? $worker->security_deposit_status
            : 'active';

        return match ($status) {
            'active' => 'نشط',
            'insufficient_balance' => 'رصيد غير كافٍ',
            'missing_deposit' => 'لا يوجد تأمين',
            default => $status,
        };
    }

    private static function depositStatusColor(mixed $state): string
    {
        $value = $state instanceof \BackedEnum ? $state->value : (string) $state;

        return match ($value) {
            'active' => 'success',
            'insufficient_balance' => 'warning',
            'missing_deposit' => 'danger',
            default => 'gray',
        };
    }

    private static function formatTrustScore(Worker $worker): string
    {
        return self::formatInteger($worker->trust_score);
    }

    private static function formatInteger(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return number_format((float) $value, 0, '.', ',');
    }

    private static function formatDecimal(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return number_format((float) $value, 1, '.', ',');
    }

    private static function money(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', ',').' '.config('app.currency', 'SYP');
    }

    private static function bookingAdminAmount(CleaningBooking $booking, int $workerId): float
    {
        $assignment = $booking->relationLoaded('workerAssignments')
            ? $booking->workerAssignments->firstWhere('worker_id', $workerId)
            : null;

        if ($assignment instanceof \Modules\Cleaning\Models\CleaningBookingWorkerAssignment) {
            return (float) $assignment->admin_margin_amount;
        }

        return (float) ($booking->admin_margin_amount ?? 0);
    }

    private static function bookingGrossAmount(CleaningBooking $booking): float
    {
        return (float) ($booking->total_price ?? 0);
    }
}
