<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBookings;

use App\Enums\DisputeStatus;
use App\Enums\SupportCaseKind;
use App\Enums\SupportCaseStatus;
use App\Filament\Resources\CleaningBookings\Pages\EditCleaningBooking;
use App\Filament\Resources\CleaningBookings\Pages\ListCleaningBookings;
use App\Filament\Resources\CleaningBookings\Pages\ViewCleaningBooking;
use App\Filament\Resources\CleaningBookings\RelationManagers\SessionsRelationManager;
use App\Filament\Resources\CleaningBookings\Schemas\CleaningBookingForm;
use App\Filament\Resources\CleaningBookings\Schemas\CleaningBookingInfolist;
use App\Filament\Resources\CleaningBookings\Tables\CleaningBookingsTable;
use App\Models\SupportCase;
use App\Models\User;
use BackedEnum;
use Carbon\Carbon;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Throwable;

final class CleaningBookingResource extends Resource
{
    protected static ?string $model = CleaningBooking::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return __('cleaning_admin.nav_groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('cleaning_admin.cleaning_bookings.nav_label');
    }

    public static function getNavigationTooltip(): ?string
    {
        return __('cleaning_admin.cleaning_bookings.tooltip');
    }

    public static function getModelLabel(): string
    {
        return __('cleaning_admin.cleaning_bookings.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cleaning_admin.cleaning_bookings.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return CleaningBookingForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        $schema = CleaningBookingInfolist::configure($schema);
        $existingComponents = $schema->getComponents(withHidden: true, withOriginalKeys: true);

        return $schema->components([
            Section::make('تتبع العاملين')
                ->description('يعرض حالة كل عامل وآخر موقع محفوظ له أثناء التوجه إلى الطلب، بنفس بيانات التتبع المستخدمة في تطبيق العميل والعامل.')
                ->schema([
                    ViewEntry::make('worker_tracking')
                        ->hiddenLabel()
                        ->getStateUsing(fn (CleaningBooking $record): array => self::workerTrackingState($record))
                        ->view('filament.resources.cleaning-bookings.infolists.worker-tracking')
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),
            ...$existingComponents,
        ]);
    }

    public static function table(Table $table): Table
    {
        return CleaningBookingsTable::configure($table)
            ->pushFilters([
                SelectFilter::make('customer')
                    ->label('العميل')
                    ->relationship(
                        name: 'customer',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query): Builder => $query
                            ->whereHas('cleaningBookings')
                            ->orderBy('name'),
                    )
                    ->getOptionLabelFromRecordUsing(
                        fn (User $record): string => sprintf('%s (%s)', $record->name, $record->phone ?: '-'),
                    )
                    ->searchable(['name', 'phone']),
            ])
            ->pushColumns([
                TextColumn::make('open_dispute_status')
                    ->label('نزاع مفتوح')
                    ->getStateUsing(function (CleaningBooking $record): string {
                        $count = (int) ($record->open_disputes_count ?? 0)
                            + (int) ($record->open_support_cases_count ?? 0);

                        return $count > 0 ? 'open' : 'none';
                    })
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'open' ? 'يوجد نزاع مفتوح' : 'لا يوجد نزاع مفتوح')
                    ->color(fn (string $state): string => $state === 'open' ? 'danger' : 'gray')
                    ->icon(fn (string $state): string => $state === 'open' ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle'),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'customer',
                'worker.user',
                'preferredWorker.user',
                'rooms.assignedWorker.user',
                'rooms.plannedPreferredWorker.user',
                'workerAssignments.worker.user',
                'acceptedWorkerAssignments.worker.user',
            ])
            ->withCount([
                'disputes as open_disputes_count' => fn (Builder $query): Builder => $query->whereIn('status', [
                    DisputeStatus::Open->value,
                    DisputeStatus::UnderReview->value,
                ]),
            ])
            ->addSelect([
                'open_support_cases_count' => SupportCase::query()
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('support_cases.booking_id', 'cleaning_bookings.id')
                    ->where('support_cases.booking_type', CleaningBooking::class)
                    ->where('support_cases.kind', SupportCaseKind::Complaint->value)
                    ->whereIn('support_cases.status', SupportCaseStatus::activeValues()),
            ]);
    }

    public static function canViewAny(): bool
    {
        return self::hasPermission('bookings.view');
    }

    public static function canView(Model $record): bool
    {
        return self::hasPermission('bookings.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        if (! self::hasPermission('bookings.update')) {
            return false;
        }

        if (! $record instanceof CleaningBooking) {
            return false;
        }

        $status = $record->status instanceof CleaningBookingStatus
            ? $record->status
            : CleaningBookingStatus::tryFrom((string) $record->status);

        return ! in_array($status, [
            CleaningBookingStatus::Completed,
            CleaningBookingStatus::Cancelled,
        ], true);
    }

    public static function canDelete(Model $record): bool
    {
        return self::hasPermission('bookings.delete');
    }

    public static function getRelations(): array
    {
        return [
            SessionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCleaningBookings::route('/'),
            'view' => ViewCleaningBooking::route('/{record}'),
            'edit' => EditCleaningBooking::route('/{record}/edit'),
        ];
    }

    /**
     * @return array{
     *     bookingStatusLabel:string,
     *     requiredWorkers:int,
     *     acceptedWorkers:int,
     *     activelyTrackedWorkers:int,
     *     workers:array<int, array<string, mixed>>
     * }
     */
    private static function workerTrackingState(CleaningBooking $record): array
    {
        $record->loadMissing([
            'worker.user',
            'acceptedWorkerAssignments.worker.user',
        ]);

        $workers = $record->acceptedWorkerAssignments
            ->map(fn (CleaningBookingWorkerAssignment $assignment): array => self::assignmentTrackingState($assignment))
            ->values()
            ->all();

        if ($workers === [] && $record->worker_id !== null) {
            $workers[] = self::legacyWorkerTrackingState($record);
        }

        $requiredWorkers = max(1, (int) ($record->number_of_workers ?? 1));
        $activelyTrackedWorkers = collect($workers)
            ->filter(fn (array $worker): bool => (bool) ($worker['isTrackingActive'] ?? false))
            ->count();

        $status = $record->status instanceof CleaningBookingStatus
            ? $record->status
            : CleaningBookingStatus::tryFrom((string) $record->status);

        return [
            'bookingStatusLabel' => $status?->label() ?? (string) ($record->status?->value ?? $record->status ?? '-'),
            'requiredWorkers' => $requiredWorkers,
            'acceptedWorkers' => count($workers),
            'activelyTrackedWorkers' => $activelyTrackedWorkers,
            'workers' => $workers,
        ];
    }

    /** @return array<string, mixed> */
    private static function assignmentTrackingState(CleaningBookingWorkerAssignment $assignment): array
    {
        $worker = $assignment->worker;
        $status = $assignment->status instanceof CleaningBookingWorkerAssignmentStatus
            ? $assignment->status->value
            : (string) $assignment->status;
        $latitude = $assignment->last_latitude !== null ? (float) $assignment->last_latitude : null;
        $longitude = $assignment->last_longitude !== null ? (float) $assignment->last_longitude : null;

        return [
            'assignmentId' => $assignment->id,
            'workerId' => $assignment->worker_id,
            'name' => $worker?->user?->name ?? $worker?->first_name ?? 'عامل غير متاح',
            'phone' => $worker?->user?->phone,
            'averageRating' => $worker?->average_rating !== null ? (float) $worker->average_rating : null,
            'status' => $status,
            'statusLabel' => self::workerAssignmentStatusLabel($status),
            'statusColor' => self::workerAssignmentStatusColor($status),
            'acceptedAt' => self::dateTime($assignment->accepted_at),
            'startedTravelAt' => self::dateTime($assignment->started_travel_at),
            'arrivedAt' => self::dateTime($assignment->arrived_at),
            'locationUpdatedAt' => self::dateTime($assignment->location_updated_at),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'hasCoordinates' => $latitude !== null && $longitude !== null,
            'trackingLabel' => self::trackingLabel($status, $assignment->started_travel_at, $assignment->arrived_at, $latitude, $longitude),
            'trackingColor' => self::trackingColor($status, $assignment->started_travel_at, $assignment->arrived_at),
            'locationEmptyLabel' => self::locationEmptyLabel($status, $assignment->started_travel_at, $assignment->arrived_at),
            'isTrackingActive' => self::isTrackingActive($status, $assignment->started_travel_at, $assignment->arrived_at),
        ];
    }

    /** @return array<string, mixed> */
    private static function legacyWorkerTrackingState(CleaningBooking $record): array
    {
        $worker = $record->worker;
        $status = $record->status instanceof CleaningBookingStatus
            ? $record->status->value
            : (string) $record->status;
        $latitudeValue = $record->getAttribute('last_worker_latitude');
        $longitudeValue = $record->getAttribute('last_worker_longitude');
        $latitude = $latitudeValue !== null ? (float) $latitudeValue : null;
        $longitude = $longitudeValue !== null ? (float) $longitudeValue : null;
        $locationUpdatedAt = $record->getAttribute('worker_location_updated_at');

        return [
            'assignmentId' => null,
            'workerId' => $record->worker_id,
            'name' => $worker?->user?->name ?? $worker?->first_name ?? 'عامل غير متاح',
            'phone' => $worker?->user?->phone,
            'averageRating' => $worker?->average_rating !== null ? (float) $worker->average_rating : null,
            'status' => $status,
            'statusLabel' => ($record->status instanceof CleaningBookingStatus) ? $record->status->label() : $status,
            'statusColor' => self::bookingStatusColor($status),
            'acceptedAt' => '-',
            'startedTravelAt' => self::dateTime($record->started_travel_at),
            'arrivedAt' => self::dateTime($record->arrived_at),
            'locationUpdatedAt' => self::dateTime($locationUpdatedAt),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'hasCoordinates' => $latitude !== null && $longitude !== null,
            'trackingLabel' => self::trackingLabel($status, $record->started_travel_at, $record->arrived_at, $latitude, $longitude),
            'trackingColor' => self::trackingColor($status, $record->started_travel_at, $record->arrived_at),
            'locationEmptyLabel' => self::locationEmptyLabel($status, $record->started_travel_at, $record->arrived_at),
            'isTrackingActive' => self::isTrackingActive($status, $record->started_travel_at, $record->arrived_at),
        ];
    }

    private static function workerAssignmentStatusLabel(string $status): string
    {
        return match ($status) {
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
            default => $status !== '' ? $status : '-',
        };
    }

    private static function workerAssignmentStatusColor(string $status): string
    {
        return match ($status) {
            'completed' => 'success',
            'rejected', 'cancelled' => 'danger',
            'withdrawn' => 'warning',
            'in_progress', 'time_extension_requested' => 'primary',
            'accepted', 'accepted_waiting_for_order_start', 'awaiting_start_verification', 'start_approved', 'awaiting_customer_completion' => 'info',
            default => 'gray',
        };
    }

    private static function bookingStatusColor(string $status): string
    {
        return match ($status) {
            'completed' => 'success',
            'cancelled' => 'danger',
            'pending', 'time_extension_requested', 'under_dispute' => 'warning',
            'in_progress' => 'primary',
            'worker_assigned', 'awaiting_start_verification', 'awaiting_worker_start_confirmation', 'awaiting_customer_completion' => 'info',
            default => 'gray',
        };
    }

    private static function trackingLabel(
        string $status,
        mixed $startedTravelAt,
        mixed $arrivedAt,
        ?float $latitude,
        ?float $longitude,
    ): string {
        if (self::isTerminalWorkerStatus($status)) {
            return 'التتبع متوقف';
        }

        if ($arrivedAt !== null) {
            return 'وصل إلى الموقع';
        }

        if ($startedTravelAt !== null && $latitude !== null && $longitude !== null) {
            return 'يتجه إلى الموقع';
        }

        if ($startedTravelAt !== null) {
            return 'بانتظار تحديث الموقع';
        }

        return 'لم يبدأ التوجه';
    }

    private static function trackingColor(string $status, mixed $startedTravelAt, mixed $arrivedAt): string
    {
        if (self::isTerminalWorkerStatus($status)) {
            return $status === 'completed' ? 'success' : 'gray';
        }

        if ($arrivedAt !== null) {
            return 'success';
        }

        if ($startedTravelAt !== null) {
            return 'primary';
        }

        return 'gray';
    }

    private static function locationEmptyLabel(string $status, mixed $startedTravelAt, mixed $arrivedAt): string
    {
        if (self::isTerminalWorkerStatus($status)) {
            return 'لا توجد إحداثيات محفوظة لهذا العامل.';
        }

        if ($arrivedAt !== null) {
            return 'تم تسجيل وصول العامل بدون إحداثيات محفوظة.';
        }

        if ($startedTravelAt !== null) {
            return 'بدأ العامل التوجه، وبانتظار أول تحديث للموقع من التطبيق.';
        }

        return 'سيظهر موقع العامل هنا بعد أن يبدأ التوجه إلى الطلب.';
    }

    private static function isTrackingActive(string $status, mixed $startedTravelAt, mixed $arrivedAt): bool
    {
        return ! self::isTerminalWorkerStatus($status)
            && $startedTravelAt !== null
            && $arrivedAt === null;
    }

    private static function isTerminalWorkerStatus(string $status): bool
    {
        return in_array($status, ['completed', 'cancelled', 'rejected', 'withdrawn'], true);
    }

    private static function dateTime(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        try {
            return Carbon::parse($value)->format('Y-m-d h:i A');
        } catch (Throwable) {
            return (string) $value;
        }
    }

    private static function hasPermission(string $permission): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->hasAnyRole(['admin', 'Super Admin'])) {
            return true;
        }

        return $user->can($permission);
    }
}
