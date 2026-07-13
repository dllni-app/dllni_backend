<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBookings\Tables;

use App\Filament\Resources\CleaningBookings\CleaningBookingResource;
use App\Models\Worker;
use BackedEnum;
use Carbon\Carbon;
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
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningBookingTeamService;
use Modules\User\Services\UserCleaningOrderEstimationService;

final class CleaningBookingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('booking_number')
                    ->label(self::headerLabel('رقم الحجز', 'المعرّف الفريد للحجز.'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(self::headerLabel('الحالة', 'الحالة الحالية للحجز.'))
                    ->badge()
                    ->color(fn ($state): string => self::statusColor($state))
                    ->formatStateUsing(fn ($state): string => self::statusLabel($state)),
                TextColumn::make('cancelled_by_role')
                    ->label(self::headerLabel('مصدر الإلغاء', 'يوضح الجهة التي ألغت الحجز.'))
                    ->badge()
                    ->color(fn ($state): string => self::cancellationSourceColor($state))
                    ->formatStateUsing(fn ($state): string => self::cancellationSourceLabel($state))
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('customer.name')
                    ->label(self::headerLabel('العميل', 'العميل الذي طلب الخدمة.'))
                    ->searchable(),
                TextColumn::make('worker.first_name')
                    ->label(self::headerLabel('العامل الأساسي', 'العامل الأساسي المعيّن للحجز.'))
                    ->placeholder('-'),
                TextColumn::make('preferred_workers')
                    ->label(self::headerLabel('العاملون المفضلون', 'قد يحتوي الحجز على أكثر من عامل مفضل.'))
                    ->getStateUsing(fn (CleaningBooking $record): array => self::preferredWorkerNames($record))
                    ->badge()
                    ->color('info')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('number_of_workers')
                    ->label(self::headerLabel('عدد العاملين المطلوب', 'عدد العاملين المطلوبين لتنفيذ الحجز.'))
                    ->formatStateUsing(fn ($state): string => self::integer($state))
                    ->sortable(),
                TextColumn::make('accepted_workers')
                    ->label(self::headerLabel('العاملون المقبولون', 'عدد العاملين الذين قبلوا الحجز.'))
                    ->getStateUsing(fn (CleaningBooking $record): int => $record->acceptedWorkerCount())
                    ->badge()
                    ->color(fn (CleaningBooking $record): string => $record->isTeamFulfilled() ? 'success' : ($record->acceptedWorkerCount() > 0 ? 'warning' : 'gray')),
                TextColumn::make('remaining_workers')
                    ->label(self::headerLabel('العاملون المتبقون', 'العدد المتبقي لإكمال الفريق.'))
                    ->getStateUsing(fn (CleaningBooking $record): int => $record->remainingWorkerCount())
                    ->badge()
                    ->color(fn (CleaningBooking $record): string => $record->remainingWorkerCount() > 0 ? 'warning' : 'success'),
                TextColumn::make('property_details.event_type')
                    ->label(self::headerLabel('نوع المناسبة', 'نوع المناسبة في طلبات مساعدة المناسبات.'))
                    ->formatStateUsing(fn (?string $state): string => self::eventTypeLabel($state))
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('scheduled_date')
                    ->label(self::headerLabel('التاريخ', 'تاريخ تنفيذ الخدمة.'))
                    ->date('Y-m-d')
                    ->sortable(),
                TextColumn::make('scheduled_time')
                    ->label(self::headerLabel('الوقت', 'وقت بداية الخدمة بنظام 12 ساعة.'))
                    ->formatStateUsing(fn ($state): string => self::time($state)),
                TextColumn::make('room_coverage')
                    ->label(self::headerLabel('تغطية الغرف', 'عدد الغرف المخصصة من إجمالي الغرف.'))
                    ->getStateUsing(fn (CleaningBooking $record): string => self::roomCoverageLabel($record))
                    ->badge()
                    ->color(fn (CleaningBooking $record): string => self::roomCoverageColor($record)),
                TextColumn::make('total_price')
                    ->label(self::headerLabel('الإجمالي', 'المبلغ الإجمالي بأرقام صحيحة.'))
                    ->formatStateUsing(fn ($state): string => self::money($state))
                    ->weight(FontWeight::Bold)
                    ->color('success')
                    ->tooltip(fn (CleaningBooking $record): string => self::priceFormula($record))
                    ->sortable(),
                TextColumn::make('worker_payout')
                    ->label(self::headerLabel('مستحقات العامل', 'إجمالي المستحقات المحسوبة للعاملين.'))
                    ->getStateUsing(fn (CleaningBooking $record): float => self::workerPayoutAmount($record))
                    ->formatStateUsing(fn ($state): string => self::money($state))
                    ->weight(FontWeight::Bold)
                    ->color('info')
                    ->tooltip(fn (CleaningBooking $record): string => self::workerPayoutFormula($record))
                    ->toggleable(),
                TextColumn::make('disputes_count')
                    ->getStateUsing(fn (CleaningBooking $record): int => (int) ($record->disputes_count ?? 0))
                    ->label(self::headerLabel('عدد النزاعات', 'عدد النزاعات المرتبطة بالحجز.')),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with([
                    'customer',
                    'worker.user',
                    'preferredWorker.user',
                    'rooms.assignedWorker.user',
                    'rooms.plannedPreferredWorker.user',
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
                    ->label('الحالة')
                    ->options(collect(CleaningBookingStatus::cases())->mapWithKeys(fn (CleaningBookingStatus $case): array => [$case->value => $case->label()])->all()),
                Filter::make('has_dispute')
                    ->label('يوجد نزاع')
                    ->query(fn (Builder $query): Builder => $query->whereHas('disputes')),
                Filter::make('scheduled_today')
                    ->label('مجدول اليوم')
                    ->query(fn (Builder $query): Builder => $query->whereDate('scheduled_date', today())),
                Filter::make('partial_team')
                    ->label('فريق جزئي')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('status', CleaningBookingStatus::Pending->value)
                        ->whereHas('acceptedWorkerAssignments')),
                Filter::make('fulfilled_team')
                    ->label('فريق مكتمل')
                    ->query(fn (Builder $query): Builder => $query->where('status', CleaningBookingStatus::WorkerAssigned->value)),
                Filter::make('unassigned_rooms')
                    ->label('غرف غير مخصصة')
                    ->query(fn (Builder $query): Builder => $query->whereHas('rooms', fn (Builder $roomsQuery): Builder => $roomsQuery->whereNull('assigned_worker_id'))),
                SelectFilter::make('property_type')
                    ->label('نوع العقار')
                    ->options([
                        UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE => 'مساعدة المناسبات',
                        'apartment' => 'شقة',
                        'villa' => 'فيلا',
                        'house' => 'منزل',
                        'office' => 'مكتب',
                        'studio' => 'استوديو',
                    ]),
            ])
            ->recordActions([
                Action::make('add_worker')
                    ->label('إضافة عامل')
                    ->icon('heroicon-o-user-plus')
                    ->color('success')
                    ->visible(fn (CleaningBooking $record): bool => ! in_array($record->status, [CleaningBookingStatus::InProgress, CleaningBookingStatus::Completed, CleaningBookingStatus::Cancelled], true) && $record->acceptedWorkerCount() < max(1, (int) ($record->number_of_workers ?? 1)))
                    ->modalHeading('إضافة عامل')
                    ->form([
                        Select::make('worker_id')
                            ->label('العامل')
                            ->options(fn (): array => self::activeWorkerOptions())
                            ->searchable()
                            ->required(),
                        CheckboxList::make('room_ids')
                            ->label('الغرف')
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

                        Notification::make()->title('تمت إضافة العامل')->success()->send();
                    }),
                Action::make('release_worker')
                    ->label('إلغاء تعيين العامل')
                    ->icon('heroicon-o-user-minus')
                    ->color('warning')
                    ->visible(fn (CleaningBooking $record): bool => in_array($record->status, [CleaningBookingStatus::Pending, CleaningBookingStatus::WorkerAssigned], true) && $record->acceptedWorkerCount() > 0)
                    ->modalHeading('إلغاء تعيين العامل')
                    ->form([
                        Select::make('worker_id')
                            ->label('العامل')
                            ->options(fn (?CleaningBooking $record): array => self::acceptedWorkerOptions($record))
                            ->searchable()
                            ->required(),
                        TextInput::make('reason')->label('السبب')->maxLength(255),
                    ])
                    ->action(function (CleaningBooking $record, array $data): void {
                        $worker = Worker::query()->with('user')->findOrFail((int) $data['worker_id']);
                        $updated = app(CleaningBookingTeamService::class)->rejectWorker(
                            $record->fresh(['rooms.assignedWorker.user', 'workerAssignments.worker.user']),
                            $worker,
                            filled($data['reason'] ?? null) ? (string) $data['reason'] : null,
                        );

                        $record->setRawAttributes($updated->getAttributes(), true);

                        Notification::make()->title('تم إلغاء تعيين العامل')->success()->send();
                    }),
                Action::make('assign_rooms')
                    ->label('تعيين الغرف')
                    ->icon('heroicon-o-squares-plus')
                    ->color('primary')
                    ->visible(fn (CleaningBooking $record): bool => in_array($record->status, [CleaningBookingStatus::Pending, CleaningBookingStatus::WorkerAssigned], true) && $record->acceptedWorkerCount() > 0 && (int) ($record->rooms_count ?? 0) > 0)
                    ->modalHeading('تعيين الغرف')
                    ->form([
                        Select::make('worker_id')
                            ->label('العامل المقبول')
                            ->options(fn (?CleaningBooking $record): array => self::acceptedWorkerOptions($record))
                            ->searchable()
                            ->required(),
                        CheckboxList::make('room_ids')
                            ->label('الغرف')
                            ->options(fn (?CleaningBooking $record): array => self::roomOptions($record))
                            ->columns(2)
                            ->required(),
                    ])
                    ->action(function (CleaningBooking $record, array $data): void {
                        $workerId = (int) $data['worker_id'];
                        $roomIds = array_values(array_filter(array_map('intval', (array) ($data['room_ids'] ?? []))));

                        app(CleaningBookingTeamService::class)->assignRoomsFromCustomer(
                            $record->fresh(['rooms.assignedWorker.user', 'workerAssignments.worker.user']),
                            array_map(static fn (int $roomId): array => ['roomId' => $roomId, 'workerId' => $workerId], $roomIds),
                        );

                        Notification::make()->title('تم تعيين الغرف')->success()->send();
                    }),
                EditAction::make()
                    ->label('تعديل')
                    ->visible(fn (CleaningBooking $record): bool => CleaningBookingResource::canEdit($record)
                        && $record->property_type !== UserCleaningOrderEstimationService::EVENT_ASSISTANCE_PROPERTY_TYPE),
                ViewAction::make()->label('عرض'),
            ]);
    }

    private static function preferredWorkerNames(CleaningBooking $record): array
    {
        $names = collect();

        if ($record->preferredWorker !== null) {
            $names->push($record->preferredWorker->first_name ?: $record->preferredWorker->user?->name);
        }

        $rooms = $record->relationLoaded('rooms')
            ? $record->rooms
            : $record->rooms()->with('plannedPreferredWorker.user')->get();

        foreach ($rooms as $room) {
            $worker = $room->plannedPreferredWorker;
            if ($worker !== null) {
                $names->push($worker->first_name ?: $worker->user?->name);
            }
        }

        return $names->filter(fn ($name): bool => filled($name))->unique()->values()->all();
    }

    private static function statusLabel(CleaningBookingStatus|string|null $status): string
    {
        return self::normalizeStatus($status)?->label() ?? '-';
    }

    private static function statusColor(CleaningBookingStatus|string|null $status): string
    {
        return match (self::normalizeStatus($status)) {
            CleaningBookingStatus::Pending => 'warning',
            CleaningBookingStatus::WorkerAssigned,
            CleaningBookingStatus::AwaitingStartVerification,
            CleaningBookingStatus::AwaitingWorkerStartConfirmation => 'info',
            CleaningBookingStatus::InProgress,
            CleaningBookingStatus::TimeExtensionRequested => 'primary',
            CleaningBookingStatus::AwaitingCustomerCompletion,
            CleaningBookingStatus::UnderDispute => 'gray',
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
        $value = $state instanceof BackedEnum ? $state->value : $state;

        return match ((string) $value) {
            'customer' => 'ألغاه العميل',
            'worker' => 'ألغاه العامل',
            default => '-',
        };
    }

    private static function cancellationSourceColor(mixed $state): string
    {
        $value = $state instanceof BackedEnum ? $state->value : $state;

        return match ((string) $value) {
            'customer' => 'danger',
            'worker' => 'warning',
            default => 'gray',
        };
    }

    private static function money(mixed $amount): string
    {
        return self::integer($amount).' ل.س';
    }

    private static function integer(mixed $value): string
    {
        return number_format((int) round((float) ($value ?? 0)), 0, '.', ',');
    }

    private static function time(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        try {
            return Carbon::parse((string) $value)->format('h:i A');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private static function eventTypeLabel(?string $value): string
    {
        return match ($value) {
            'family_dinner' => 'عشاء عائلي',
            'birthday' => 'عيد ميلاد',
            'large_gathering' => 'تجمع كبير',
            'funeral' => 'عزاء',
            'other' => 'أخرى',
            null, '' => '-',
            default => $value,
        };
    }

    private static function priceFormula(CleaningBooking $record): string
    {
        return sprintf(
            'الأساسي %s + الإضافات %s + التنقل %s + الإلغاء %s + هامش الإدارة %s = الإجمالي %s',
            self::money($record->base_price),
            self::money($record->addons_total),
            self::money($record->travel_fee),
            self::money($record->cancellation_fee),
            self::money($record->admin_margin_amount),
            self::money($record->total_price),
        );
    }

    private static function roomCoverageLabel(CleaningBooking $record): string
    {
        $totalRooms = max(0, (int) ($record->rooms_count ?? 0));
        if ($totalRooms === 0) {
            return '-';
        }

        $assignedRooms = max(0, (int) ($record->assigned_rooms_count ?? 0));
        $percent = (int) round(($assignedRooms / $totalRooms) * 100);

        return sprintf('%d/%d (%d%%)', $assignedRooms, $totalRooms, $percent);
    }

    private static function roomCoverageColor(CleaningBooking $record): string
    {
        if (max(0, (int) ($record->unassigned_rooms_count ?? 0)) === 0) {
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
            static fn ($assignment): bool => in_array(
                (string) ($assignment->status?->value ?? $assignment->status),
                CleaningBookingWorkerAssignmentStatus::acceptedValues(),
                true,
            ),
        );

        if ($acceptedAssignments->isNotEmpty()) {
            return (float) $acceptedAssignments->sum('worker_amount');
        }

        if ($record->worker_id !== null && max(1, (int) ($record->number_of_workers ?? 1)) <= 1) {
            return max(0.0, (float) ($record->total_price ?? 0) - (float) ($record->admin_margin_amount ?? 0));
        }

        return 0.0;
    }

    private static function workerPayoutFormula(CleaningBooking $record): string
    {
        $assignments = $record->relationLoaded('workerAssignments')
            ? $record->workerAssignments
            : $record->workerAssignments()->get();

        $acceptedAssignments = $assignments->filter(
            static fn ($assignment): bool => in_array(
                (string) ($assignment->status?->value ?? $assignment->status),
                CleaningBookingWorkerAssignmentStatus::acceptedValues(),
                true,
            ),
        );

        if ($acceptedAssignments->isNotEmpty()) {
            return sprintf(
                'الحصة %s + التنقل %s - هامش الإدارة %s = المستحق %s',
                self::money($acceptedAssignments->sum('service_share_amount')),
                self::money($acceptedAssignments->sum('travel_fee')),
                self::money($acceptedAssignments->sum('admin_margin_amount')),
                self::money($acceptedAssignments->sum('worker_amount')),
            );
        }

        return sprintf(
            'الإجمالي %s - هامش الإدارة %s = مستحق العامل %s',
            self::money($record->total_price),
            self::money($record->admin_margin_amount),
            self::money(self::workerPayoutAmount($record)),
        );
    }

    private static function activeWorkerOptions(): array
    {
        return Worker::query()
            ->with('user')
            ->where('is_active', true)
            ->orderBy('first_name')
            ->get()
            ->mapWithKeys(fn (Worker $worker): array => [$worker->id => self::workerLabel($worker)])
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

        $options = $assignments
            ->filter(static fn ($assignment): bool => in_array(
                (string) ($assignment->status?->value ?? $assignment->status),
                CleaningBookingWorkerAssignmentStatus::acceptedValues(),
                true,
            ))
            ->mapWithKeys(fn ($assignment): array => [(int) $assignment->worker_id => self::workerLabel($assignment->worker)])
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

        return $rooms->mapWithKeys(fn ($room): array => [$room->id => self::roomLabel($room)])->all();
    }

    private static function roomLabel(object $room): string
    {
        $label = (string) ($room->display_label ?? $room->room_key ?? 'غرفة غير معروفة');
        $assignedWorker = $room->assignedWorker?->first_name ?? $room->assignedWorker?->user?->name;

        return filled($assignedWorker) ? sprintf('%s - %s', $label, $assignedWorker) : $label;
    }

    private static function workerLabel(Worker $worker): string
    {
        return mb_trim(($worker->first_name ?: $worker->user?->name ?: '-').' ('.($worker->user?->phone ?: '-').')');
    }

    private static function headerLabel(string $label, string $description): HtmlString
    {
        return new HtmlString(
            '<span style="display:flex;flex-direction:column;line-height:1.2;">'
                .'<span style="display:block;font-weight:600;color:inherit;">'.e($label).'</span>'
                .'<span style="display:block;margin-top:2px;font-size:11px;font-weight:400;color:#9ca3af;">'.e($description).'</span>'
                .'</span>',
        );
    }
}
