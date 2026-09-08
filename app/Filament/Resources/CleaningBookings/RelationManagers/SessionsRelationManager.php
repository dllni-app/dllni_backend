<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBookings\RelationManagers;

use App\Enums\DisputeCategory;
use App\Enums\DisputeStatus;
use App\Models\Dispute;
use App\Models\WorkerCustomerRating;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Cleaning\Enums\CleaningBookingSessionCoverageStatus;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;

final class SessionsRelationManager extends RelationManager
{
    protected static string $relationship = 'sessions';

    protected static ?string $title = 'أيام / جلسات التنفيذ';

    protected static ?string $modelLabel = 'جلسة تنفيذ';

    protected static ?string $pluralModelLabel = 'جلسات التنفيذ';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('ملخص يوم التنفيذ')
                ->description('الحالة والتغطية والبيانات التشغيلية والمالية الخاصة بهذا اليوم.')
                ->schema([
                    TextEntry::make('sequence')
                        ->label('اليوم')
                        ->formatStateUsing(fn ($state): string => 'اليوم '.(int) $state)
                        ->badge()
                        ->color('info'),
                    TextEntry::make('scheduled_date')
                        ->label('التاريخ')
                        ->date('Y-m-d'),
                    TextEntry::make('scheduled_time')
                        ->label('وقت البدء')
                        ->formatStateUsing(fn ($state): string => self::timeLabel((string) $state)),
                    TextEntry::make('duration_hours')
                        ->label('المدة')
                        ->formatStateUsing(fn ($state): string => self::hoursLabel((float) $state)),
                    TextEntry::make('status')
                        ->label('حالة اليوم')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => self::statusLabel($state))
                        ->color(fn ($state): string => self::statusColor($state)),
                    TextEntry::make('coverage_status')
                        ->label('تغطية العمال')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => self::coverageLabel($state))
                        ->color(fn ($state): string => self::coverageColor($state)),
                    TextEntry::make('required_workers_count')
                        ->label('العمال المطلوبون')
                        ->getStateUsing(fn (CleaningBookingSession $record): int => $record->requiredWorkerCount()),
                    TextEntry::make('accepted_workers_count')
                        ->label('العمال المقبولون')
                        ->getStateUsing(fn (CleaningBookingSession $record): int => $record->acceptedWorkerCount()),
                    TextEntry::make('remaining_workers_count')
                        ->label('العمال المتبقون')
                        ->getStateUsing(fn (CleaningBookingSession $record): int => $record->remainingWorkerCount())
                        ->badge()
                        ->color(fn (CleaningBookingSession $record): string => $record->remainingWorkerCount() === 0 ? 'success' : 'warning'),
                    TextEntry::make('work_started_at')
                        ->label('بدأ العمل')
                        ->dateTime('Y-m-d H:i')
                        ->placeholder('-'),
                    TextEntry::make('work_finished_at')
                        ->label('انتهى العمل')
                        ->dateTime('Y-m-d H:i')
                        ->placeholder('-'),
                    TextEntry::make('total_price')
                        ->label('سعر اليوم')
                        ->formatStateUsing(fn ($state): string => self::money((float) $state))
                        ->weight('bold'),
                ])
                ->columns(4),
            Section::make('العمال في هذا اليوم')
                ->description('يعرض سجل كل عامل المرتبط بالجلسة، بما في ذلك العامل الذي تم استبداله أو تحريره.')
                ->schema([
                    RepeatableEntry::make('workerAssignments')
                        ->hiddenLabel()
                        ->schema([
                            TextEntry::make('worker_label')
                                ->label('العامل')
                                ->getStateUsing(fn (CleaningBookingSessionWorkerAssignment $record): string => self::workerName($record))
                                ->weight('bold'),
                            TextEntry::make('status')
                                ->label('حالة العامل')
                                ->badge()
                                ->formatStateUsing(fn ($state): string => self::assignmentStatusLabel($state))
                                ->color(fn ($state): string => self::assignmentStatusColor($state)),
                            TextEntry::make('worker_amount')
                                ->label('مستحق العامل')
                                ->formatStateUsing(fn ($state): string => self::money((float) $state))
                                ->weight('bold'),
                            TextEntry::make('travel_fee')
                                ->label('بدل النقل')
                                ->formatStateUsing(fn ($state): string => self::money((float) $state)),
                            TextEntry::make('accepted_at')
                                ->label('وقت القبول')
                                ->dateTime('Y-m-d H:i')
                                ->placeholder('-'),
                            TextEntry::make('released_at')
                                ->label('وقت التحرير / الاستبدال')
                                ->dateTime('Y-m-d H:i')
                                ->placeholder('-'),
                            TextEntry::make('released_reason')
                                ->label('سبب التحرير / الاستبدال')
                                ->placeholder('-')
                                ->columnSpanFull(),
                            TextEntry::make('started_travel_at')
                                ->label('بدأ التوجه')
                                ->dateTime('Y-m-d H:i')
                                ->placeholder('-'),
                            TextEntry::make('arrived_at')
                                ->label('وصل للموقع')
                                ->dateTime('Y-m-d H:i')
                                ->placeholder('-'),
                            TextEntry::make('start_approved_at')
                                ->label('تم اعتماد البدء')
                                ->dateTime('Y-m-d H:i')
                                ->placeholder('-'),
                            TextEntry::make('work_started_at')
                                ->label('بدأ العمل')
                                ->dateTime('Y-m-d H:i')
                                ->placeholder('-'),
                            TextEntry::make('work_finished_at')
                                ->label('أنهى العمل')
                                ->dateTime('Y-m-d H:i')
                                ->placeholder('-'),
                            TextEntry::make('attendance_action')
                                ->label('إجراء الحضور')
                                ->formatStateUsing(fn ($state): string => self::attendanceActionLabel($state))
                                ->placeholder('-'),
                            TextEntry::make('attendance_note')
                                ->label('ملاحظة الحضور')
                                ->placeholder('-')
                                ->columnSpanFull(),
                            TextEntry::make('worker_completion_message')
                                ->label('ملاحظة إكمال العامل')
                                ->placeholder('-')
                                ->columnSpanFull(),
                        ])
                        ->columns(4),
                ])
                ->columnSpanFull(),
            Section::make('التقييمات والنزاعات')
                ->description('حالة ما بعد التنفيذ الخاصة بهذا اليوم فقط.')
                ->schema([
                    TextEntry::make('review_summary')
                        ->label('ملخص التقييم')
                        ->getStateUsing(fn (CleaningBookingSession $record): string => self::reviewSummary($record)),
                    TextEntry::make('dispute_summary')
                        ->label('ملخص النزاع')
                        ->getStateUsing(fn (CleaningBookingSession $record): string => self::disputeSummary($record))
                        ->badge()
                        ->color(fn (CleaningBookingSession $record): string => self::disputeSummaryColor($record)),
                    RepeatableEntry::make('ratings')
                        ->label('التقييمات')
                        ->schema([
                            TextEntry::make('worker_label')
                                ->label('العامل')
                                ->getStateUsing(fn (WorkerCustomerRating $record): string => $record->worker?->user?->name ?? $record->worker?->first_name ?? 'عامل #'.$record->worker_id),
                            TextEntry::make('rating')
                                ->label('التقييم')
                                ->formatStateUsing(fn ($state): string => number_format((float) $state, 1).' / 5')
                                ->badge()
                                ->color('success'),
                            TextEntry::make('comment')
                                ->label('الملاحظة')
                                ->placeholder('-')
                                ->columnSpanFull(),
                        ])
                        ->columns(2)
                        ->columnSpanFull(),
                    RepeatableEntry::make('disputes')
                        ->label('النزاعات')
                        ->schema([
                            TextEntry::make('ticket_number')
                                ->label('رقم النزاع')
                                ->placeholder('-'),
                            TextEntry::make('status')
                                ->label('الحالة')
                                ->badge()
                                ->formatStateUsing(fn ($state): string => self::disputeStatusLabel($state))
                                ->color(fn ($state): string => self::disputeStatusColor($state)),
                            TextEntry::make('category')
                                ->label('التصنيف')
                                ->formatStateUsing(fn ($state): string => self::disputeCategoryLabel($state)),
                            TextEntry::make('worker_earnings_frozen')
                                ->label('مستحقات العامل مجمدة')
                                ->formatStateUsing(fn ($state): string => (bool) $state ? 'نعم' : 'لا')
                                ->badge()
                                ->color(fn ($state): string => (bool) $state ? 'danger' : 'success'),
                            TextEntry::make('description')
                                ->label('وصف النزاع')
                                ->placeholder('-')
                                ->columnSpanFull(),
                            TextEntry::make('financial_penalty_amount')
                                ->label('الغرامة المالية')
                                ->formatStateUsing(fn ($state): string => self::money((float) $state))
                                ->placeholder('-'),
                        ])
                        ->columns(4)
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with([
                    'workerAssignments.worker.user',
                    'ratings.worker.user',
                    'disputes',
                ])
                ->orderBy('sequence'))
            ->columns([
                TextColumn::make('sequence')
                    ->label('اليوم')
                    ->formatStateUsing(fn ($state): string => 'اليوم '.(int) $state)
                    ->badge()
                    ->color('info'),
                TextColumn::make('scheduled_date')
                    ->label('التاريخ')
                    ->date('Y-m-d')
                    ->sortable(),
                TextColumn::make('scheduled_time')
                    ->label('وقت البدء')
                    ->formatStateUsing(fn ($state): string => self::timeLabel((string) $state)),
                TextColumn::make('duration_hours')
                    ->label('المدة')
                    ->formatStateUsing(fn ($state): string => self::hoursLabel((float) $state)),
                TextColumn::make('status')
                    ->label('حالة اليوم')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => self::statusLabel($state))
                    ->color(fn ($state): string => self::statusColor($state)),
                TextColumn::make('coverage_status')
                    ->label('تغطية العمال')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => self::coverageLabel($state))
                    ->color(fn ($state): string => self::coverageColor($state)),
                TextColumn::make('coverage_summary')
                    ->label('التغطية العددية')
                    ->getStateUsing(fn (CleaningBookingSession $record): string => sprintf(
                        '%d مطلوب · %d مقبول · %d متبقٍ',
                        $record->requiredWorkerCount(),
                        $record->acceptedWorkerCount(),
                        $record->remainingWorkerCount(),
                    ))
                    ->badge()
                    ->color(fn (CleaningBookingSession $record): string => $record->remainingWorkerCount() === 0
                        ? 'success'
                        : ($record->acceptedWorkerCount() > 0 ? 'warning' : 'gray')),
                TextColumn::make('workers')
                    ->label('العمال وحالتهم')
                    ->getStateUsing(fn (CleaningBookingSession $record): array => $record->workerAssignments
                        ->filter(fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->isAccepted())
                        ->map(fn (CleaningBookingSessionWorkerAssignment $assignment): string => self::workerName($assignment).' — '.self::assignmentStatusLabel($assignment->status))
                        ->unique()
                        ->values()
                        ->all())
                    ->badge()
                    ->color('info')
                    ->placeholder('-'),
                TextColumn::make('worker_entitlements')
                    ->label('مستحقات العمال')
                    ->getStateUsing(fn (CleaningBookingSession $record): float => (float) $record->workerAssignments
                        ->filter(fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->isAccepted())
                        ->sum('worker_amount'))
                    ->formatStateUsing(fn ($state): string => self::money((float) $state))
                    ->weight('bold'),
                TextColumn::make('review_state')
                    ->label('التقييم')
                    ->getStateUsing(fn (CleaningBookingSession $record): string => self::reviewSummary($record))
                    ->toggleable(),
                TextColumn::make('dispute_state')
                    ->label('النزاع')
                    ->getStateUsing(fn (CleaningBookingSession $record): string => self::disputeSummary($record))
                    ->badge()
                    ->color(fn (CleaningBookingSession $record): string => self::disputeSummaryColor($record)),
                TextColumn::make('work_started_at')
                    ->label('بدأ العمل')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('work_finished_at')
                    ->label('انتهى العمل')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('total_price')
                    ->label('سعر اليوم')
                    ->formatStateUsing(fn ($state): string => self::money((float) $state))
                    ->weight('bold')
                    ->toggleable(),
                TextColumn::make('travel_fee')
                    ->label('النقل')
                    ->formatStateUsing(fn ($state): string => self::money((float) $state))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('admin_margin_amount')
                    ->label('حصة الإدارة')
                    ->formatStateUsing(fn ($state): string => self::money((float) $state))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('extension_requested_minutes')
                    ->label('التمديد')
                    ->formatStateUsing(fn ($state): string => $state !== null ? (int) $state.' دقيقة' : '-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cancellation_fee')
                    ->label('رسوم الإلغاء')
                    ->formatStateUsing(fn ($state): string => self::money((float) $state))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cancellation_reason')
                    ->label('سبب الإلغاء')
                    ->placeholder('-')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([])
            ->recordActions([
                ViewAction::make()
                    ->label('تفاصيل اليوم')
                    ->icon('heroicon-o-eye'),
            ])
            ->toolbarActions([]);
    }

    private static function statusLabel(mixed $state): string
    {
        if ($state instanceof CleaningBookingSessionStatus) {
            return $state->label();
        }

        return CleaningBookingSessionStatus::tryFrom((string) $state)?->label() ?? (string) $state;
    }

    private static function statusColor(mixed $state): string
    {
        $value = $state instanceof CleaningBookingSessionStatus ? $state->value : (string) $state;

        return match ($value) {
            'completed' => 'success',
            'cancelled', 'under_dispute' => 'danger',
            'in_progress', 'time_extension_requested' => 'primary',
            'worker_assigned', 'awaiting_start_verification', 'awaiting_worker_start_confirmation', 'awaiting_customer_completion' => 'info',
            default => 'gray',
        };
    }

    private static function coverageLabel(mixed $state): string
    {
        if ($state instanceof CleaningBookingSessionCoverageStatus) {
            return $state->label();
        }

        return CleaningBookingSessionCoverageStatus::tryFrom((string) $state)?->label() ?? (string) $state;
    }

    private static function coverageColor(mixed $state): string
    {
        $value = $state instanceof CleaningBookingSessionCoverageStatus ? $state->value : (string) $state;

        return match ($value) {
            'fully_covered' => 'success',
            'partially_covered' => 'warning',
            default => 'gray',
        };
    }

    private static function workerName(CleaningBookingSessionWorkerAssignment $assignment): string
    {
        return (string) ($assignment->worker?->user?->name
            ?? $assignment->worker?->first_name
            ?? 'عامل #'.$assignment->worker_id);
    }

    private static function assignmentStatusLabel(mixed $state): string
    {
        $value = $state instanceof CleaningBookingWorkerAssignmentStatus
            ? $state->value
            : (string) $state;

        return match ($value) {
            'pending' => 'قيد الانتظار',
            'accepted' => 'مقبول',
            'accepted_waiting_for_order_start' => 'مقبول وبانتظار بدء الطلب',
            'awaiting_start_verification' => 'بانتظار التحقق من البدء',
            'start_approved' => 'تمت الموافقة على البدء',
            'in_progress' => 'قيد التنفيذ',
            'awaiting_customer_completion' => 'بانتظار تأكيد العميل',
            'time_extension_requested' => 'تم طلب تمديد الوقت',
            'completed' => 'مكتمل',
            'rejected' => 'مرفوض',
            'withdrawn' => 'منسحب',
            'cancelled' => 'ملغى',
            default => $value !== '' ? $value : '-',
        };
    }

    private static function assignmentStatusColor(mixed $state): string
    {
        $value = $state instanceof CleaningBookingWorkerAssignmentStatus
            ? $state->value
            : (string) $state;

        return match ($value) {
            'completed' => 'success',
            'rejected', 'cancelled' => 'danger',
            'withdrawn' => 'warning',
            'in_progress', 'time_extension_requested' => 'primary',
            'accepted', 'accepted_waiting_for_order_start', 'awaiting_start_verification', 'start_approved', 'awaiting_customer_completion' => 'info',
            default => 'gray',
        };
    }

    private static function attendanceActionLabel(mixed $state): string
    {
        $value = mb_trim((string) $state);

        return match ($value) {
            'late' => 'تم تسجيل تأخر',
            'no_travel' => 'لم يبدأ التوجه',
            'replacement_requested' => 'طلب استبدال',
            'replacement_assigned' => 'تم تعيين بديل',
            'resolved' => 'تمت المعالجة',
            '' => '-',
            default => $value,
        };
    }

    private static function reviewSummary(CleaningBookingSession $record): string
    {
        $ratings = $record->ratings;
        $count = $ratings->count();

        if ($count === 0) {
            return 'لا يوجد تقييم';
        }

        $average = (float) $ratings->avg('rating');

        return number_format($average, 1).' / 5 · '.$count.' تقييم';
    }

    private static function disputeSummary(CleaningBookingSession $record): string
    {
        $disputes = $record->disputes;

        if ($disputes->isEmpty()) {
            return 'لا يوجد نزاع';
        }

        $active = $disputes->first(function (Dispute $dispute): bool {
            $status = $dispute->status instanceof DisputeStatus
                ? $dispute->status
                : DisputeStatus::tryFrom((string) $dispute->status);

            return $status !== null && ! $status->isTerminal();
        });

        if ($active instanceof Dispute) {
            $status = $active->status instanceof DisputeStatus
                ? $active->status
                : DisputeStatus::tryFrom((string) $active->status);
            $ticket = mb_trim((string) $active->ticket_number);

            return ($status?->label() ?? 'نزاع مفتوح').($ticket !== '' ? ' · '.$ticket : '');
        }

        return 'مغلق · '.$disputes->count().' نزاع';
    }

    private static function disputeSummaryColor(CleaningBookingSession $record): string
    {
        if ($record->disputes->isEmpty()) {
            return 'gray';
        }

        $hasOpen = $record->disputes->contains(function (Dispute $dispute): bool {
            $status = $dispute->status instanceof DisputeStatus
                ? $dispute->status
                : DisputeStatus::tryFrom((string) $dispute->status);

            return $status !== null && ! $status->isTerminal();
        });

        return $hasOpen ? 'danger' : 'success';
    }

    private static function disputeStatusLabel(mixed $state): string
    {
        if ($state instanceof DisputeStatus) {
            return $state->label();
        }

        return DisputeStatus::tryFrom((string) $state)?->label() ?? (string) $state;
    }

    private static function disputeStatusColor(mixed $state): string
    {
        $value = $state instanceof DisputeStatus ? $state->value : (string) $state;

        return match ($value) {
            DisputeStatus::Open->value => 'danger',
            DisputeStatus::UnderReview->value => 'warning',
            DisputeStatus::Resolved->value, DisputeStatus::Closed->value => 'success',
            DisputeStatus::Rejected->value => 'gray',
            default => 'gray',
        };
    }

    private static function disputeCategoryLabel(mixed $state): string
    {
        if ($state instanceof DisputeCategory) {
            return $state->label();
        }

        return DisputeCategory::tryFrom((string) $state)?->label() ?? (string) $state;
    }

    private static function timeLabel(string $value): string
    {
        $time = mb_trim($value);
        if ($time === '') {
            return '-';
        }

        $parts = explode(':', $time);
        $hour = (int) ($parts[0] ?? 0);
        $minute = (int) ($parts[1] ?? 0);
        $suffix = $hour >= 12 ? 'م' : 'ص';
        $displayHour = $hour % 12;
        if ($displayHour === 0) {
            $displayHour = 12;
        }

        return sprintf('%d:%02d %s', $displayHour, $minute, $suffix);
    }

    private static function hoursLabel(float $hours): string
    {
        return mb_rtrim(mb_rtrim(number_format($hours, 2, '.', ''), '0'), '.').' ساعة';
    }

    private static function money(float $amount): string
    {
        return number_format($amount, 0, '.', ',').' '.config('app.currency', 'SYP');
    }
}
