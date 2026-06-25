<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBookings\Tables;

use App\Filament\Resources\CleaningPriceAdjustmentRequests\CleaningPriceAdjustmentRequestResource;
use App\Models\Worker;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Modules\Cleaning\Enums\CleaningAssignmentMode;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Enums\CleaningPriceAdjustmentRequestStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingPriceAdjustmentRequest;
use Modules\Cleaning\Services\CleaningBookingTeamService;
use Modules\User\Services\UserCleaningOrderEstimationService;

final class CleaningBookingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('booking_number')
                    ->label(self::headerLabel(
                        __('cleaning_admin.booking.fields.booking_number'),
                        __('cleaning_admin.column_descriptions.booking_number'),
                    ))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(self::headerLabel(
                        __('cleaning_admin.booking.fields.status'),
                        __('cleaning_admin.column_descriptions.status'),
                    ))
                    ->badge()
                    ->color(fn ($state): string => self::statusColor($state))
                    ->formatStateUsing(fn ($state): string => self::statusLabel($state)),
                TextColumn::make('cancelled_by_role')
                    ->label(self::headerLabel('مصدر الإلغاء', 'يوضح إذا كان العميل هو من ألغى الطلب.'))
                    ->badge()
                    ->color(fn ($state): string => self::cancellationSourceColor($state))
                    ->formatStateUsing(fn ($state): string => self::cancellationSourceLabel($state))
                    ->placeholder('-'),
                TextColumn::make('assignment_mode')
                    ->label(self::headerLabel(
                        __('cleaning_admin.booking.fields.assignment_mode'),
                        __('cleaning_admin.column_descriptions.assignment_mode'),
                    ))
                    ->badge()
                    ->color(fn ($state, CleaningBooking $record): string => self::assignmentModeColor($record))
                    ->getStateUsing(fn (CleaningBooking $record): string => self::assignmentModeLabel($record)),
                TextColumn::make('customer.name')
                    ->label(self::headerLabel(
                        __('cleaning_admin.booking.fields.customer'),
                        __('cleaning_admin.column_descriptions.customer'),
                    ))
                    ->searchable(),
                TextColumn::make('worker.first_name')
                    ->label(self::headerLabel(
                        __('cleaning_admin.booking.fields.primary_worker'),
                        __('cleaning_admin.column_descriptions.primary_worker'),
                    ))
                    ->placeholder('-'),
                TextColumn::make('preferredWorker.first_name')
                    ->label(self::headerLabel(
                        __('cleaning_admin.booking.fields.preferred_worker'),
                        __('cleaning_admin.column_descriptions.preferred_worker'),
                    ))
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('number_of_workers')
                    ->label(self::headerLabel(
                        __('cleaning_admin.booking.fields.required_workers'),
                        __('cleaning_admin.column_descriptions.required_workers'),
                    ))
                    ->sortable(),
                TextColumn::make('accepted_workers')
                    ->label(self::headerLabel(
                        __('cleaning_admin.booking.fields.accepted_workers'),
                        __('cleaning_admin.column_descriptions.accepted_workers'),
                    ))
                    ->getStateUsing(fn (CleaningBooking $record): int => $record->acceptedWorkerCount())
                    ->sortable(false)
                    ->badge()
                    ->color(fn (CleaningBooking $record): string => $record->isTeamFulfilled() ? 'success' : ($record->acceptedWorkerCount() > 0 ? 'warning' : 'gray')),
                TextColumn::make('remaining_workers')
                    ->label(self::headerLabel(
                        __('cleaning_admin.booking.fields.remaining_workers'),
                        __('cleaning_admin.column_descriptions.remaining_workers'),
                    ))
                    ->getStateUsing(fn (CleaningBooking $record): int => $record->remainingWorkerCount())
                    ->badge()
                    ->color(fn (CleaningBooking $record): string => $record->remainingWorkerCount() > 0 ? 'warning' : 'success'),
                TextColumn::make('property_details.event_type')
                    ->label(self::headerLabel(
                        __('cleaning_admin.booking.fields.event_type'),
                        __('cleaning_admin.column_descriptions.event_type'),
                    ))
                    ->formatStateUsing(fn (?string $state): string => self::translatedValue('cleaning_admin.enums.event_type.', $state))
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('scheduled_date')
                    ->label(self::headerLabel(
                        __('cleaning_admin.booking.fields.scheduled_date'),
                        __('cleaning_admin.column_descriptions.scheduled_date'),
                    ))
                    ->date()
                    ->sortable(),
                TextColumn::make('scheduled_time')
                    ->label(self::headerLabel(
                        __('cleaning_admin.booking.fields.scheduled_time'),
                        __('cleaning_admin.column_descriptions.scheduled_time'),
                    )),
                TextColumn::make('room_coverage')
                    ->label(self::headerLabel(
                        __('cleaning_admin.booking.fields.room_coverage'),
                        __('cleaning_admin.column_descriptions.room_coverage'),
                    ))
                    ->getStateUsing(fn (CleaningBooking $record): string => self::roomCoverageLabel($record))
                    ->badge()
                    ->color(fn (CleaningBooking $record): string => self::roomCoverageColor($record)),
                TextColumn::make('total_price')
                    ->label(self::headerLabel(
                        __('cleaning_admin.booking.fields.total_price'),
                        __('cleaning_admin.column_descriptions.total_price'),
                    ))
                    ->formatStateUsing(fn ($state): string => self::money($state))
                    ->weight(FontWeight::Bold)
                    ->color('success')
                    ->tooltip(fn (CleaningBooking $record): string => self::priceFormula($record))
                    ->extraAttributes(fn (CleaningBooking $record): array => [
                        'title' => self::priceFormula($record),
                    ])
                    ->sortable(),
                TextColumn::make('worker_payout')
                    ->label(self::headerLabel(
                        __('cleaning_admin.booking.fields.worker_payout'),
                        __('cleaning_admin.column_descriptions.worker_payout'),
                    ))
                    ->getStateUsing(fn (CleaningBooking $record): float => self::workerPayoutAmount($record))
                    ->formatStateUsing(fn ($state): string => self::money($state))
                    ->weight(FontWeight::Bold)
                    ->color('info')
                    ->tooltip(fn (CleaningBooking $record): string => self::workerPayoutFormula($record))
                    ->extraAttributes(fn (CleaningBooking $record): array => [
                        'title' => self::workerPayoutFormula($record),
                    ])
                    ->toggleable(),
                TextColumn::make('is_pricing_final')
                    ->label(self::headerLabel(
                        __('cleaning_admin.booking.fields.pricing_status'),
                        __('cleaning_admin.column_descriptions.pricing_status'),
                    ))
                    ->badge()
                    ->color(fn ($state): string => $state ? 'success' : 'warning')
                    ->formatStateUsing(fn ($state): string => $state ? __('cleaning_admin.booking.pricing.final') : __('cleaning_admin.booking.pricing.provisional')),
                TextColumn::make('price_adjustment_review')
                    ->label(self::headerLabel('تعديل السعر', 'حالة طلب تعديل السعر المرتبط بالحجز قبل بدء العمل.'))
                    ->getStateUsing(fn (CleaningBooking $record): string => self::priceAdjustmentState($record))
                    ->badge()
                    ->color(fn (CleaningBooking $record): string => self::priceAdjustmentColor($record))
                    ->toggleable(),
                TextColumn::make('disputes_count')
                    ->getStateUsing(fn (CleaningBooking $record): int => (int) ($record->disputes_count ?? 0))
                    ->label(self::headerLabel(
                        __('cleaning_admin.booking.fields.disputes_count'),
                        __('cleaning_admin.column_descriptions.disputes_count'),
                    )),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with([
                    'customer',
                    'worker.user',
                    'preferredWorker.user',
                    'rooms.assignedWorker.user',
                    'workerAssignments.worker.user',
                ])
                ->withCount([
                    'disputes',
                    'rooms',
                    'acceptedWorkerAssignments',
                    'rooms as assigned_rooms_count' => fn (Builder $roomsQuery): Builder => $roomsQuery->whereNotNull('assigned_worker_id'),
                    'rooms as unassigned_rooms_count' => fn (Builder $roomsQuery): Builder => $roomsQuery->whereNull('assigned_worker_id'),
                ]))
            ->filters([
                SelectFilter::make('status')
                    ->label(__('cleaning_admin.booking.filters.status'))
                    ->options(collect(CleaningBookingStatus::cases())->mapWithKeys(fn (CleaningBookingStatus $case): array => [$case->value => $case->label()])->all()),
                SelectFilter::make('assignment_mode')
                    ->label(__('cleaning_admin.booking.filters.assignment_mode'))
                    ->options([
                        CleaningAssignmentMode::PreferredWorker->value => __('cleaning_admin.enums.assignment_mode.preferred_worker'),
                        CleaningAssignmentMode::OpenCount->value => __('cleaning_admin.enums.assignment_mode.open_count'),
                    ]),
                Filter::make('has_dispute')
                    ->label(__('cleaning_admin.booking.filters.has_dispute'))
                    ->query(fn (Builder $query): Builder => $query->whereHas('disputes')),
                Filter::make('scheduled_today')
                    ->label(__('cleaning_admin.booking.filters.scheduled_today'))
                    ->query(fn (Builder $query): Builder => $query->whereDate('scheduled_date', today())),
                Filter::make('pending_price_adjustment')
                    ->label('يوجد طلب تعديل سعر قيد المراجعة')
                    ->query(fn (Builder $query): Builder => self::whereHasPendingPriceAdjustment($query)),
                Filter::make('partial_team')
                    ->label(__('cleaning_admin.booking.filters.partial_team'))
                    ->query(fn (Builder $query): Builder => $query
                        ->where('status', CleaningBookingStatus::Pending->value)
                        ->whereHas('acceptedWorkerAssignments')),
                Filter::make('fulfilled_team')
                    ->label(__('cleaning_admin.booking.filters.fulfilled_team'))
                    ->query(fn (Builder $query): Builder => $query->where('status', CleaningBookingStatus::WorkerAssigned->value)),
                Filter::make('unassigned_rooms')
                    ->label(__('cleaning_admin.booking.filters.unassigned_rooms'))
                    ->query(fn (Builder $query): Builder => $query->whereHas('rooms', fn (Builder $roomsQuery): Builder => $roomsQuery->whereNull('assigned_worker_id'))),
                SelectFilter::make('property_type')
                    ->label(__('cleaning_admin.booking.filters.property_type'))
                    ->options([
                        UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE => __('cleaning_admin.booking.property_types.event_assistance'),
                        'apartment' => __('cleaning_admin.booking.property_types.apartment'),
                        'villa' => __('cleaning_admin.booking.property_types.villa'),
                        'house' => __('cleaning_admin.booking.property_types.house'),
                        'office' => __('cleaning_admin.booking.property_types.office'),
                        'studio' => __('cleaning_admin.booking.property_types.studio'),
                    ]),
            ])
            ->recordActions([
                Action::make('add_worker')
                    ->label(__('cleaning_admin.booking.actions.add_worker'))
                    ->icon('heroicon-o-user-plus')
                    ->color('success')
                    ->visible(fn (CleaningBooking $record): bool => ! in_array($record->status, [CleaningBookingStatus::InProgress, CleaningBookingStatus::Completed, CleaningBookingStatus::Cancelled], true) && $record->acceptedWorkerCount() < max(1, (int) ($record->number_of_workers ?? 1)))
                    ->modalHeading(__('cleaning_admin.booking.actions.add_worker'))
                    ->form([
                        Select::make('worker_id')
                            ->label(__('cleaning_admin.booking.actions.worker'))
                            ->options(fn (): array => self::activeWorkerOptions())
                            ->searchable()
                            ->required(),
                        CheckboxList::make('room_ids')
                            ->label(__('cleaning_admin.booking.actions.room_ids'))
                            ->options(fn (?CleaningBooking $record): array => self::roomOptions($record))
                            ->columns(2),
                    ])
                    ->action(function (CleaningBooking $record, array $data): void {
                        $worker = Worker::query()->with('user')->findOrFail((int) $data['worker_id']);
                        $roomIds = array_values(array_filter(array_map('intval', (array) ($data['room_ids'] ?? []))));
                        $updated = app(CleaningBookingTeamService::class)->acceptWorker(
                            $record->fresh(['rooms.assignedWorker.user', 'workerAssignments.worker.user']),
                            $worker,
                            $roomIds !== [] ? $roomIds : null,
                        );

                        $record->setRawAttributes($updated->getAttributes(), true);

                        Notification::make()
                            ->title(__('cleaning_admin.booking.actions.worker_added'))
                            ->success()
                            ->send();
                    }),
                Action::make('release_worker')
                    ->label(__('cleaning_admin.booking.actions.release_worker'))
                    ->icon('heroicon-o-user-minus')
                    ->color('warning')
                    ->visible(fn (CleaningBooking $record): bool => in_array($record->status, [CleaningBookingStatus::Pending, CleaningBookingStatus::WorkerAssigned], true) && $record->acceptedWorkerCount() > 0)
                    ->modalHeading(__('cleaning_admin.booking.actions.release_worker'))
                    ->form([
                        Select::make('worker_id')
                            ->label(__('cleaning_admin.booking.actions.worker'))
                            ->options(fn (?CleaningBooking $record): array => self::acceptedWorkerOptions($record))
                            ->searchable()
                            ->required(),
                        TextInput::make('reason')
                            ->label(__('cleaning_admin.booking.actions.reason'))
                            ->maxLength(255),
                    ])
                    ->action(function (CleaningBooking $record, array $data): void {
                        $worker = Worker::query()->with('user')->findOrFail((int) $data['worker_id']);
                        $updated = app(CleaningBookingTeamService::class)->rejectWorker(
                            $record->fresh(['rooms.assignedWorker.user', 'workerAssignments.worker.user']),
                            $worker,
                            filled($data['reason'] ?? null) ? (string) $data['reason'] : null,
                        );

                        $record->setRawAttributes($updated->getAttributes(), true);

                        Notification::make()
                            ->title(__('cleaning_admin.booking.actions.worker_released'))
                            ->success()
                            ->send();
                    }),
                Action::make('assign_rooms')
                    ->label(__('cleaning_admin.booking.actions.assign_rooms'))
                    ->icon('heroicon-o-squares-plus')
                    ->color('primary')
                    ->visible(fn (CleaningBooking $record): bool => in_array($record->status, [CleaningBookingStatus::Pending, CleaningBookingStatus::WorkerAssigned], true) && $record->acceptedWorkerCount() > 0 && (int) ($record->rooms_count ?? 0) > 0)
                    ->modalHeading(__('cleaning_admin.booking.actions.assign_rooms'))
                    ->form([
                        Select::make('worker_id')
                            ->label(__('cleaning_admin.booking.actions.accepted_worker'))
                            ->options(fn (?CleaningBooking $record): array => self::acceptedWorkerOptions($record))
                            ->searchable()
                            ->required(),
                        CheckboxList::make('room_ids')
                            ->label(__('cleaning_admin.booking.actions.room_ids'))
                            ->options(fn (?CleaningBooking $record): array => self::roomOptions($record))
                            ->columns(2)
                            ->required(),
                    ])
                    ->action(function (CleaningBooking $record, array $data): void {
                        $workerId = (int) $data['worker_id'];
                        $roomIds = array_values(array_filter(array_map('intval', (array) ($data['room_ids'] ?? []))));

                        app(CleaningBookingTeamService::class)->assignRoomsFromCustomer(
                            $record->fresh(['rooms.assignedWorker.user', 'workerAssignments.worker.user']),
                            array_map(
                                static fn (int $roomId): array => ['roomId' => $roomId, 'workerId' => $workerId],
                                $roomIds,
                            ),
                        );

                        Notification::make()
                            ->title(__('cleaning_admin.booking.actions.rooms_assigned'))
                            ->success()
                            ->send();
                    }),
                Action::make('review_price_adjustment')
                    ->label('مراجعة تعديل السعر')
                    ->icon('heroicon-o-currency-dollar')
                    ->color('warning')
                    ->visible(fn (CleaningBooking $record): bool => self::pendingPriceAdjustmentRequestId($record) !== null)
                    ->url(fn (CleaningBooking $record): string => CleaningPriceAdjustmentRequestResource::getUrl('view', [
                        'record' => self::pendingPriceAdjustmentRequestId($record),
                    ])),
                EditAction::make()
                    ->label(__('filament-actions::edit.single.label'))
                    ->visible(fn (CleaningBooking $record): bool => $record->property_type !== UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE),
                ViewAction::make()
                    ->label(__('filament-actions::view.single.label')),
            ]);
    }

    private static function statusLabel(CleaningBookingStatus|string|null $status): string
    {
        $status = self::normalizeStatus($status);

        return $status?->label() ?? '-';
    }

    private static function statusColor(CleaningBookingStatus|string|null $status): string
    {
        $status = self::normalizeStatus($status);

        return match ($status) {
            CleaningBookingStatus::Pending => 'warning',
            CleaningBookingStatus::WorkerAssigned,
            CleaningBookingStatus::AwaitingStartVerification => 'info',
            CleaningBookingStatus::InProgress,
            CleaningBookingStatus::TimeExtensionRequested => 'primary',
            CleaningBookingStatus::AwaitingCustomerCompletion => 'gray',
            CleaningBookingStatus::Completed => 'success',
            CleaningBookingStatus::Cancelled => 'danger',
            default => 'gray',
        };
    }

    private static function normalizeStatus(CleaningBookingStatus|string|null $status): ?CleaningBookingStatus
    {
        if ($status instanceof CleaningBookingStatus || $status === null) {
            return $status;
        }

        return CleaningBookingStatus::tryFrom($status);
    }

    private static function cancellationSourceLabel(mixed $state): string
    {
        $value = $state instanceof \BackedEnum ? $state->value : $state;

        return match ((string) $value) {
            'customer' => 'ألغاه العميل',
            'worker' => 'ألغاه العامل',
            default => '-',
        };
    }

    private static function cancellationSourceColor(mixed $state): string
    {
        $value = $state instanceof \BackedEnum ? $state->value : $state;

        return match ((string) $value) {
            'customer' => 'danger',
            'worker' => 'warning',
            default => 'gray',
        };
    }

    private static function money(mixed $amount): string
    {
        return number_format((float) $amount, 2).' '.config('app.currency', 'SYP');
    }

    private static function priceFormula(CleaningBooking $record): string
    {
        return __('cleaning_admin.booking.pricing.formula', [
            'base' => self::money($record->base_price),
            'addons' => self::money($record->addons_total),
            'travel' => self::money($record->travel_fee),
            'cancellation' => self::money($record->cancellation_fee),
            'total' => self::money($record->total_price),
        ]);
    }

    private static function translatedValue(string $prefix, ?string $value): string
    {
        if (! $value) {
            return '-';
        }

        $key = $prefix.$value;

        return __($key) === $key ? $value : __($key);
    }

    private static function assignmentModeLabel(CleaningBooking $record): string
    {
        $mode = $record->resolvedAssignmentMode();

        return self::translatedValue('cleaning_admin.enums.assignment_mode.', $mode);
    }

    private static function assignmentModeColor(CleaningBooking $record): string
    {
        return match ($record->resolvedAssignmentMode()) {
            CleaningAssignmentMode::PreferredWorker->value => 'info',
            CleaningAssignmentMode::OpenCount->value => 'primary',
            default => 'gray',
        };
    }

    private static function roomCoverageLabel(CleaningBooking $record): string
    {
        $totalRooms = max(0, (int) ($record->rooms_count ?? 0));
        if ($totalRooms <= 0) {
            return '-';
        }

        $assignedRooms = max(0, (int) ($record->assigned_rooms_count ?? 0));
        $percent = (int) round(($assignedRooms / max(1, $totalRooms)) * 100);

        return sprintf('%d/%d (%d%%)', $assignedRooms, $totalRooms, $percent);
    }

    private static function roomCoverageColor(CleaningBooking $record): string
    {
        $remaining = max(0, (int) ($record->unassigned_rooms_count ?? 0));

        if ($remaining === 0) {
            return 'success';
        }

        return $record->acceptedWorkerCount() > 0 ? 'warning' : 'gray';
    }

    private static function workerPayoutAmount(CleaningBooking $record): float
    {
        $assignments = $record->relationLoaded('workerAssignments')
            ? $record->workerAssignments
            : $record->workerAssignments()->get();

        $acceptedAssignments = $assignments->filter(
            static fn ($assignment): bool => (string) ($assignment->status?->value ?? $assignment->status) === CleaningBookingWorkerAssignmentStatus::Accepted->value
        );

        if ($acceptedAssignments->isNotEmpty()) {
            return round((float) $acceptedAssignments->sum('worker_amount'), 2);
        }

        if ($record->worker_id !== null && max(1, (int) ($record->number_of_workers ?? 1)) <= 1) {
            return max(0.0, round((float) ($record->total_price ?? 0) - (float) ($record->admin_margin_amount ?? 0), 2));
        }

        return 0.0;
    }

    private static function workerPayoutFormula(CleaningBooking $record): string
    {
        $assignments = $record->relationLoaded('workerAssignments')
            ? $record->workerAssignments
            : $record->workerAssignments()->get();

        $acceptedAssignments = $assignments->filter(
            static fn ($assignment): bool => (string) ($assignment->status?->value ?? $assignment->status) === CleaningBookingWorkerAssignmentStatus::Accepted->value
        );

        if ($acceptedAssignments->isNotEmpty()) {
            return __('cleaning_admin.booking.pricing.worker_payout_formula', [
                'share' => self::money($acceptedAssignments->sum('service_share_amount')),
                'travel' => self::money($acceptedAssignments->sum('travel_fee')),
                'admin' => self::money($acceptedAssignments->sum('admin_margin_amount')),
                'total' => self::money($acceptedAssignments->sum('worker_amount')),
            ]);
        }

        return __('cleaning_admin.booking.pricing.worker_payout_formula_legacy', [
            'total' => self::money($record->total_price),
            'admin' => self::money($record->admin_margin_amount),
            'worker' => self::money(self::workerPayoutAmount($record)),
        ]);
    }

    private static function priceAdjustmentState(CleaningBooking $record): string
    {
        $request = self::latestPriceAdjustmentRequest($record);

        if (! $request instanceof CleaningBookingPriceAdjustmentRequest) {
            return '-';
        }

        $status = $request->status instanceof CleaningPriceAdjustmentRequestStatus
            ? $request->status
            : CleaningPriceAdjustmentRequestStatus::tryFrom((string) $request->status);

        return $status?->label() ?? '-';
    }

    private static function priceAdjustmentColor(CleaningBooking $record): string
    {
        $request = self::latestPriceAdjustmentRequest($record);

        if (! $request instanceof CleaningBookingPriceAdjustmentRequest) {
            return 'gray';
        }

        $status = $request->status instanceof CleaningPriceAdjustmentRequestStatus
            ? $request->status
            : CleaningPriceAdjustmentRequestStatus::tryFrom((string) $request->status);

        return match ($status) {
            CleaningPriceAdjustmentRequestStatus::Pending => 'warning',
            CleaningPriceAdjustmentRequestStatus::Approved => 'success',
            CleaningPriceAdjustmentRequestStatus::Rejected => 'danger',
            CleaningPriceAdjustmentRequestStatus::ResolvedWithoutChange => 'info',
            default => 'gray',
        };
    }

    private static function latestPriceAdjustmentRequest(CleaningBooking $record): ?CleaningBookingPriceAdjustmentRequest
    {
        return CleaningBookingPriceAdjustmentRequest::query()
            ->where('cleaning_booking_id', $record->id)
            ->latest()
            ->first();
    }

    private static function pendingPriceAdjustmentRequestId(CleaningBooking $record): ?int
    {
        $id = CleaningBookingPriceAdjustmentRequest::query()
            ->where('cleaning_booking_id', $record->id)
            ->where('status', CleaningPriceAdjustmentRequestStatus::Pending->value)
            ->latest()
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    private static function whereHasPendingPriceAdjustment(Builder $query): Builder
    {
        return $query->whereExists(function ($subQuery): void {
            $subQuery->selectRaw('1')
                ->from('cleaning_booking_price_adjustment_requests')
                ->whereColumn('cleaning_booking_price_adjustment_requests.cleaning_booking_id', 'cleaning_bookings.id')
                ->where('cleaning_booking_price_adjustment_requests.status', CleaningPriceAdjustmentRequestStatus::Pending->value);
        });
    }

    private static function activeWorkerOptions(): array
    {
        return Worker::query()
            ->with('user')
            ->where('is_active', true)
            ->orderBy('first_name')
            ->get()
            ->mapWithKeys(fn (Worker $worker): array => [
                $worker->id => self::workerLabel($worker),
            ])
            ->all();
    }

    private static function acceptedWorkerOptions(?CleaningBooking $record): array
    {
        if ($record === null) {
            return [];
        }

        $assignments = $record->relationLoaded('workerAssignments')
            ? $record->workerAssignments
            : $record->workerAssignments()->with('worker.user')->get();

        $acceptedAssignments = $assignments->filter(
            static fn ($assignment): bool => (string) ($assignment->status?->value ?? $assignment->status) === CleaningBookingWorkerAssignmentStatus::Accepted->value
        );

        $options = $acceptedAssignments
            ->mapWithKeys(fn ($assignment): array => [
                (int) $assignment->worker_id => self::workerLabel($assignment->worker),
            ])
            ->all();

        if ($options === [] && $record->worker_id !== null && max(1, (int) ($record->number_of_workers ?? 1)) <= 1) {
            $worker = Worker::query()->with('user')->find($record->worker_id);
            if ($worker instanceof Worker) {
                $options[$worker->id] = self::workerLabel($worker);
            }
        }

        return $options;
    }

    private static function roomOptions(?CleaningBooking $record): array
    {
        if ($record === null) {
            return [];
        }

        $rooms = $record->relationLoaded('rooms')
            ? $record->rooms
            : $record->rooms()->with('assignedWorker.user')->orderBy('id')->get();

        return $rooms
            ->mapWithKeys(fn ($room): array => [
                $room->id => self::roomLabel($room),
            ])
            ->all();
    }

    private static function roomLabel(object $room): string
    {
        $label = (string) ($room->display_label ?? $room->room_key ?? __('cleaning_admin.booking.rooms.unknown'));
        $assignedWorker = $room->assignedWorker?->first_name ?? $room->assignedWorker?->user?->name;

        if (filled($assignedWorker)) {
            return sprintf('%s - %s', $label, $assignedWorker);
        }

        return $label;
    }

    private static function workerLabel(Worker $worker): string
    {
        return trim(($worker->first_name ?: $worker->user?->name ?: '-').' ('.($worker->user?->phone ?: '-').')');
    }

    private static function headerLabel(string $label, string $description): HtmlString
    {
        return new HtmlString(
            '<span style="display:flex;flex-direction:column;line-height:1.2;">'
                . '<span style="display:block;font-weight:600;color:inherit;">'.e($label).'</span>'
                . '<span style="display:block;margin-top:2px;font-size:11px;font-weight:400;color:#9ca3af;">'.e($description).'</span>'
                . '</span>',
        );
    }
}
