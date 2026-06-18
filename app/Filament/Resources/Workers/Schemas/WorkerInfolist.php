<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workers\Schemas;

use App\Enums\WorkerPreferredWorkType;
use App\Models\Worker;
use BackedEnum;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\DepositService;

final class WorkerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $yesNo = fn ($state): string => $state ? __('cleaning_admin.boolean.yes') : __('cleaning_admin.boolean.no');

        return $schema
            ->components([
                Grid::make(12)
                    ->schema([
                        Section::make('ملخص العامل')
                            ->schema([
                                Grid::make(6)
                                    ->schema([
                                        ImageEntry::make('avatar_preview')
                                            ->label('الصورة')
                                            ->getStateUsing(fn (Worker $record): ?string => self::workerAvatarUrl($record))
                                            ->defaultImageUrl(fn (Worker $record): string => self::workerAvatarUrl($record) ?? self::fallbackAvatarUrl($record))
                                            ->circular()
                                            ->imageHeight(96),
                                        Group::make()
                                            ->schema([
                                                TextEntry::make('worker_display_name')
                                                    ->label(__('cleaning_admin.workers.fields.name'))
                                                    ->state(fn (Worker $record): string => $record->user?->name ?: $record->first_name)
                                                    ->weight('bold')
                                                    ->size('lg'),
                                                TextEntry::make('account_status')
                                                    ->label('حالة الحساب')
                                                    ->state(fn (Worker $record): string => self::accountStatusLabel($record))
                                                    ->badge()
                                                    ->color(fn (mixed $state): string => self::accountStatusColor($state)),
                                                TextEntry::make('verification_status')
                                                    ->label(__('cleaning_admin.workers.fields.is_verified'))
                                                    ->state(fn (Worker $record): string => $yesNo($record->is_verified))
                                                    ->badge()
                                                    ->color(fn (mixed $state): string => self::booleanColor($state)),
                                                TextEntry::make('featured_status')
                                                    ->label(__('cleaning_admin.workers.fields.is_featured'))
                                                    ->state(fn (Worker $record): string => $yesNo($record->is_featured))
                                                    ->badge()
                                                    ->color(fn (mixed $state): string => self::booleanColor($state)),
                                            ])
                                            ->columns(4)
                                            ->columnSpan(5),
                                        TextEntry::make('trust_score_stat')
                                            ->label(__('cleaning_admin.workers.fields.trust_score'))
                                            ->state(fn (Worker $record): string => self::formatTrustScore($record))
                                            ->suffix(' / 100')
                                            ->icon(Heroicon::OutlinedShieldCheck)
                                            ->weight('bold'),
                                        TextEntry::make('total_completed_jobs_stat')
                                            ->label(__('cleaning_admin.workers.fields.total_completed_jobs'))
                                            ->state(fn (Worker $record): string => self::formatInteger($record->total_completed_jobs))
                                            ->icon(Heroicon::OutlinedCheckCircle)
                                            ->weight('bold'),
                                        TextEntry::make('average_rating_stat')
                                            ->label(__('cleaning_admin.workers.fields.average_rating'))
                                            ->state(fn (Worker $record): string => self::formatDecimal($record->average_rating))
                                            ->suffix(' / 5')
                                            ->icon(Heroicon::OutlinedStar)
                                            ->weight('bold'),
                                        TextEntry::make('acceptance_rate_stat')
                                            ->label(__('cleaning_admin.workers.fields.acceptance_rate'))
                                            ->state(fn (Worker $record): string => self::formatDecimal($record->acceptance_rate))
                                            ->suffix('%')
                                            ->icon(Heroicon::OutlinedArrowTrendingUp)
                                            ->weight('bold'),
                                        TextEntry::make('cancellation_rate_stat')
                                            ->label(__('cleaning_admin.workers.fields.cancellation_rate'))
                                            ->state(fn (Worker $record): string => self::formatDecimal($record->cancellation_rate))
                                            ->suffix('%')
                                            ->icon(Heroicon::OutlinedArrowTrendingDown)
                                            ->weight('bold'),
                                        TextEntry::make('open_disputes_count_stat')
                                            ->label(__('cleaning_admin.workers.fields.open_disputes_count'))
                                            ->state(fn (Worker $record): string => self::formatInteger($record->open_disputes_count))
                                            ->icon(Heroicon::OutlinedExclamationTriangle)
                                            ->weight('bold'),
                                    ]),
                            ])
                            ->columnSpanFull(),
                        Section::make(__('cleaning_admin.workers.sections.account'))
                            ->schema([
                                TextEntry::make('user_id')
                                    ->label(__('cleaning_admin.workers.fields.user'))
                                    ->placeholder('-'),
                                TextEntry::make('user.name')
                                    ->label(__('cleaning_admin.workers.fields.user_name'))
                                    ->placeholder('-'),
                                TextEntry::make('user.email')
                                    ->label('البريد الإلكتروني')
                                    ->placeholder('-')
                                    ->copyable(),
                                TextEntry::make('user.phone')
                                    ->label(__('cleaning_admin.workers.fields.phone'))
                                    ->placeholder('-')
                                    ->copyable(),
                            ])
                            ->columns(2)
                            ->columnSpan(6),
                        Section::make(__('cleaning_admin.workers.sections.profile'))
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
                                    ->state(fn (Worker $record): string => self::formatPreferredWorkType($record->preferred_work_type ?? null))
                                    ->placeholder('-'),
                                TextEntry::make('bio')
                                    ->label(__('cleaning_admin.workers.fields.bio'))
                                    ->placeholder('-')
                                    ->columnSpanFull(),
                            ])
                            ->columns(2)
                            ->columnSpan(6),
                        Section::make('ملخص المبالغ')
                            ->schema([
                                TextEntry::make('gross_revenue')
                                    ->label(__('cleaning_admin.workers.fields.gross_revenue'))
                                    ->state(fn (Worker $record): string => self::money(self::financialSummary($record)['gross_revenue']))
                                    ->weight('bold'),
                                TextEntry::make('worker_net_earnings')
                                    ->label(__('cleaning_admin.workers.fields.worker_net_earnings'))
                                    ->state(fn (Worker $record): string => self::money(self::financialSummary($record)['worker_net_earnings']))
                                    ->weight('bold'),
                                TextEntry::make('admin_margin_total')
                                    ->label(__('cleaning_admin.workers.fields.admin_margin_total'))
                                    ->state(fn (Worker $record): string => self::money(self::financialSummary($record)['admin_margin_total']))
                                    ->weight('bold'),
                                TextEntry::make('completed_jobs_count')
                                    ->label(__('cleaning_admin.workers.fields.completed_jobs_count'))
                                    ->state(fn (Worker $record): string => self::formatInteger(self::financialSummary($record)['completed_jobs_count']))
                                    ->weight('bold'),
                            ])
                            ->columns(2)
                            ->columnSpan(6),
                        Section::make('ملخص التأمين')
                            ->schema([
                                TextEntry::make('security_deposit_status')
                                    ->label(__('cleaning_admin.workers.fields.security_deposit_status'))
                                    ->badge()
                                    ->color(fn (mixed $state): string => self::depositStatusColor($state))
                                    ->state(fn (Worker $record): string => self::depositStatusLabel($record)),
                                TextEntry::make('current_balance')
                                    ->label(__('cleaning_admin.workers.fields.current_balance'))
                                    ->state(fn (Worker $record): string => self::money(self::depositSummary($record)['current_balance']))
                                    ->weight('bold'),
                                TextEntry::make('deposited_total')
                                    ->label(__('cleaning_admin.workers.fields.deposited_total'))
                                    ->state(fn (Worker $record): string => self::money(self::depositSummary($record)['deposited_total']))
                                    ->weight('bold'),
                                TextEntry::make('withdrawn_total')
                                    ->label(__('cleaning_admin.workers.fields.withdrawn_total'))
                                    ->state(fn (Worker $record): string => self::money(self::depositSummary($record)['withdrawn_total']))
                                    ->weight('bold'),
                                TextEntry::make('minimum_required')
                                    ->label(__('cleaning_admin.workers.fields.minimum_required'))
                                    ->state(fn (Worker $record): string => self::money(self::depositSummary($record)['minimum_required']))
                                    ->weight('bold'),
                                TextEntry::make('max_negative_balance')
                                    ->label(__('cleaning_admin.workers.fields.max_negative_balance'))
                                    ->state(fn (Worker $record): string => self::money(self::depositSummary($record)['max_negative_balance']))
                                    ->weight('bold'),
                                TextEntry::make('exceedance_amount')
                                    ->label(__('cleaning_admin.workers.fields.exceedance_amount'))
                                    ->state(fn (Worker $record): string => self::formatExceedance(self::depositSummary($record)['exceedance_amount']))
                                    ->badge()
                                    ->color(fn (Worker $record): string => self::depositSummary($record)['exceedance_amount'] !== null ? 'danger' : 'success'),
                                TextEntry::make('dispatch_eligibility')
                                    ->label(__('cleaning_admin.workers.fields.dispatch_eligibility'))
                                    ->state(fn (Worker $record): string => self::depositSummary($record)['is_eligible_for_dispatch']
                                        ? __('cleaning_admin.workers.eligibility.eligible')
                                        : __('cleaning_admin.workers.eligibility.ineligible'))
                                    ->badge()
                                    ->color(fn (Worker $record): string => self::depositSummary($record)['is_eligible_for_dispatch'] ? 'success' : 'danger'),
                            ])
                            ->columns(2)
                            ->columnSpan(6),
                        Section::make(__('cleaning_admin.workers.sections.location'))
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
                                    ->state(fn (Worker $record): array => self::formatWorkingHours($record->default_working_hours))
                                    ->listWithLineBreaks()
                                    ->columnSpanFull()
                                    ->placeholder('-'),
                            ])
                            ->columns(2)
                            ->columnSpan(6),
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
                                        TextEntry::make('score_before')->label(__('cleaning_admin.workers.fields.score_before')),
                                        TextEntry::make('score_after')->label(__('cleaning_admin.workers.fields.score_after')),
                                        TextEntry::make('score_delta')->label(__('cleaning_admin.workers.fields.score_delta'))->suffix(' points'),
                                        TextEntry::make('cleaning_booking_id')->label(__('cleaning_admin.workers.fields.booking_id')),
                                        TextEntry::make('created_at')->label(__('cleaning_admin.workers.fields.date'))->dateTime('Y-m-d H:i'),
                                    ])
                                    ->columns(3),
                            ])
                            ->columnSpan(6),
                        Section::make(__('cleaning_admin.workers.sections.preferred_zones'))
                            ->schema([
                                RepeatableEntry::make('zones')
                                    ->label('')
                                    ->schema([
                                        TextEntry::make('name')->label(__('cleaning_admin.workers.fields.zone')),
                                        TextEntry::make('is_active')
                                            ->label(__('cleaning_admin.workers.fields.is_active'))
                                            ->formatStateUsing($yesNo)
                                            ->badge()
                                            ->color(fn (mixed $state): string => self::booleanColor($state)),
                                    ])
                                    ->columns(2),
                            ])
                            ->columnSpan(6),
                    ])
                    ->columnSpanFull(),
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

    /**
     * @return array<int, string>
     */
    private static function formatWorkingHours(mixed $state): array
    {
        if (! is_array($state) || $state === []) {
            return [];
        }

        $dayLabels = [
            'sunday' => 'الأحد',
            'monday' => 'الإثنين',
            'tuesday' => 'الثلاثاء',
            'wednesday' => 'الأربعاء',
            'thursday' => 'الخميس',
            'friday' => 'الجمعة',
            'saturday' => 'السبت',
        ];

        $lines = [];
        foreach ($dayLabels as $day => $label) {
            $dayHours = $state[$day] ?? null;
            $ranges = self::workingHourRanges($dayHours);

            $lines[] = $label.': '.($ranges === [] ? 'غير متاح' : implode('، ', $ranges));
        }

        return $lines;
    }

    /**
     * @return array<int, string>
     */
    private static function workingHourRanges(mixed $dayHours): array
    {
        if (! is_array($dayHours)) {
            return [];
        }

        if (isset($dayHours['available']) && ! (bool) $dayHours['available']) {
            return [];
        }

        $periods = isset($dayHours['data']) && is_array($dayHours['data'])
            ? $dayHours['data']
            : [$dayHours];

        $ranges = [];
        foreach ($periods as $period) {
            if (! is_array($period)) {
                continue;
            }

            if (isset($period['from'], $period['to'])) {
                $ranges[] = $period['from'].' - '.$period['to'];

                continue;
            }

            $from = array_key_first($period);
            $to = is_string($from) ? ($period[$from] ?? null) : null;

            if (is_string($from) && is_string($to)) {
                $ranges[] = $from.' - '.$to;
            }
        }

        return $ranges;
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
     * @return array{
     *     current_balance: float,
     *     deposited_total: float,
     *     withdrawn_total: float,
     *     minimum_required: float,
     *     max_negative_balance: float,
     *     exceedance_amount: float|null,
     *     is_eligible_for_dispatch: bool
     * }
     */
    private static function depositSummary(Worker $worker): array
    {
        static $cache = [];

        if (isset($cache[$worker->id])) {
            return $cache[$worker->id];
        }

        $worker->loadMissing('deposit');
        $payload = app(DepositService::class)->depositStatusPayload($worker);

        return $cache[$worker->id] = [
            'current_balance' => $payload['currentBalance'],
            'deposited_total' => $payload['depositedTotal'],
            'withdrawn_total' => $payload['withdrawnTotal'],
            'minimum_required' => $payload['minimumRequired'],
            'max_negative_balance' => $payload['maxNegativeBalance'],
            'exceedance_amount' => $payload['exceedanceAmount'],
            'is_eligible_for_dispatch' => $payload['isEligibleForNewRequests'],
        ];
    }

    private static function formatExceedance(?float $amount): string
    {
        if ($amount === null) {
            return '-';
        }

        return self::money($amount);
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
            'suspended' => 'معلق',
            default => $status,
        };
    }

    private static function depositStatusColor(mixed $state): string
    {
        $value = $state instanceof BackedEnum ? $state->value : (string) $state;

        return match ($value) {
            'active', 'نشط' => 'success',
            'insufficient_balance', 'رصيد غير كافٍ' => 'warning',
            'missing_deposit', 'لا يوجد تأمين', 'suspended', 'معلق' => 'danger',
            default => 'gray',
        };
    }

    private static function accountStatusLabel(Worker $worker): string
    {
        if ($worker->is_suspended) {
            return 'معلق';
        }

        return $worker->is_active ? 'نشط' : 'غير نشط';
    }

    private static function accountStatusColor(mixed $state): string
    {
        return match ((string) $state) {
            'نشط' => 'success',
            'معلق' => 'danger',
            default => 'gray',
        };
    }

    private static function booleanColor(mixed $state): string
    {
        return (string) $state === __('cleaning_admin.boolean.yes') ? 'success' : 'gray';
    }

    private static function fallbackAvatarUrl(Worker $worker): string
    {
        $name = rawurlencode($worker->user?->name ?: $worker->first_name ?: 'Worker');

        return "https://ui-avatars.com/api/?name={$name}&background=0f766e&color=ffffff";
    }

    private static function workerAvatarUrl(Worker $worker): ?string
    {
        $media = $worker->getFirstMedia('avatar');

        if ($media === null) {
            return null;
        }

        return '/storage/'.mb_ltrim($media->getPathRelativeToRoot(), '/');
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
