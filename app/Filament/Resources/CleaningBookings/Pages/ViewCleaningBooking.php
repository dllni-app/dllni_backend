<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBookings\Pages;

use App\Enums\WorkerCustomerRatingType;
use App\Filament\Resources\CleaningBookings\CleaningBookingResource;
use App\Filament\Resources\Disputes\DisputeResource;
use App\Models\User;
use App\Models\WorkerCustomerRating;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Enums\CleaningTimeWarningResponse;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;
use Modules\Cleaning\Models\CleaningTimeWarning;
use Modules\Cleaning\Services\CleaningAdministrativeOperationsService;
use Modules\Cleaning\Services\CleaningLifecycleNotificationService;
use Modules\Cleaning\Services\CleaningMaterialInventoryService;
use Modules\Cleaning\Services\CleaningSupervisorAssignmentService;
use Modules\Cleaning\Services\CleaningSettledVisitFundingAuditService;
use Modules\Cleaning\Services\SupervisedTeamService;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ViewCleaningBooking extends ViewRecord
{
    protected static string $resource = CleaningBookingResource::class;

    public function getTitle(): string
    {
        return 'عرض حجز تنظيف';
    }

    public function infolist(Schema $schema): Schema
    {
        $this->record->loadMissing([
            'ratings.customer',
            'ratings.worker.user',
            'timeWarnings.booking',
            'timeWarnings.worker.user',
            'sessions.workerAssignments.worker.user',
        ]);

        // ViewRecord schemas use a multi-column root layout by default. The
        // existing cleaning booking infolist already owns its responsive grid,
        // so keeping the root at one column prevents that grid from occupying
        // only half of the available page and leaving a large empty area.
        $schema = parent::infolist($schema)->columns(1);
        $existingComponents = $schema->getComponents(withHidden: true, withOriginalKeys: true);

        return $schema->components([
            ...$existingComponents,
            Section::make('مراجعة تسويات الزيارات')
                ->description('تنبيه تدقيقي: قد تكون الزيارة مسجلة كمسددة رغم عدم إثبات تغطية كامل مستحقات العامل أو المشرف. لا تُعدّل هذه المراجعة أي مبالغ.')
                ->schema([
                    RepeatableEntry::make('settled_visit_financial_exceptions')
                        ->hiddenLabel()
                        ->getStateUsing(static fn (CleaningBooking $record): array =>
                            app(CleaningSettledVisitFundingAuditService::class)->unresolved($record))
                        ->schema([
                            TextEntry::make('visit')->label('الزيارة'),
                            TextEntry::make('status')->label('حالة التسوية')->badge()->color('danger'),
                            TextEntry::make('quote')->label('السعر المسجل (ل.س)'),
                            TextEntry::make('worker_amount')->label('مستحقات العمال (ل.س)'),
                            TextEntry::make('travel')->label('التنقل المشمول بالمستحقات (ل.س)'),
                            TextEntry::make('gap')->label('الحد الأدنى للعجز (ل.س)')->color('danger'),
                            TextEntry::make('reason')->label('أسباب عدم التحقق')->columnSpanFull(),
                        ])->columns(['default' => 1, 'md' => 2, 'xl' => 3]),
                ])
                ->visible(static fn (CleaningBooking $record): bool =>
                    app(CleaningSettledVisitFundingAuditService::class)->unresolved($record) !== [])
                ->columnSpanFull(),
            Section::make('الزيارات المستقلة')
                ->description('حالة كل زيارة وقرارات العمال والمشرف بشكل منفصل؛ قبول زيارة لا يعني قبول باقي الزيارات.')
                ->schema([
                    RepeatableEntry::make('independent_visit_overview')
                        ->hiddenLabel()
                        ->getStateUsing(static fn (CleaningBooking $record): array => $record->sessions()
                            ->with(['workerAssignments.worker.user', 'activeSupervisorAssignment.supervisor'])
                            ->orderBy('sequence')
                            ->get()
                            ->map(static function (CleaningBookingSession $session): array {
                                // The independent-visit summary is historical
                                // after completion; accepted-only scopes omit
                                // completed workers and incorrectly show 0/N.
                                $completed = $session->status === \Modules\Cleaning\Enums\CleaningBookingSessionStatus::Completed;
                                $accepted = $session->workerAssignments
                                    ->filter(static fn ($assignment): bool => $assignment->released_at === null && (
                                        $completed
                                            ? ($assignment->status?->value ?? $assignment->status) === \Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus::Completed->value
                                            : in_array(
                                                (string) ($assignment->status?->value ?? $assignment->status),
                                                \Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus::acceptedValues(),
                                                true,
                                            )
                                    ));

                                return [
                                    'visit_number' => 'الزيارة '.$session->sequence,
                                    'scheduled_at' => ($session->scheduled_date?->format('Y-m-d') ?? '-')
                                        .' '.mb_substr((string) $session->scheduled_time, 0, 5),
                                    'status_label' => $session->status?->label() ?? 'غير محددة',
                                    'worker_coverage' => $accepted->count().' / '.$session->requiredWorkerCount(),
                                    'visit_supervisor' => $session->activeSupervisorAssignment?->supervisor?->name ?? 'لم يتم تعيين مشرف',
                                    'accepted_workers' => $accepted->pluck('worker.user.name')->filter()->join('، ') ?: ($completed ? 'لا توجد سجلات إكمال للعمال' : 'لم يقبل أي عامل بعد'),
                                ];
                            })->all())
                        ->schema([
                            TextEntry::make('visit_number')->label('الزيارة')->weight('bold'),
                            TextEntry::make('scheduled_at')->label('الموعد'),
                            TextEntry::make('status_label')->label('حالة الزيارة')->badge(),
                            TextEntry::make('worker_coverage')->label('العمال المقبولون / المطلوبون'),
                            TextEntry::make('visit_supervisor')->label('مشرف الزيارة'),
                            TextEntry::make('accepted_workers')->label('العاملون المقبولون'),
                        ])
                        ->columns(['default' => 1, 'md' => 2, 'xl' => 3]),
                ])
                ->visible(static fn (CleaningBooking $record): bool => $record->sessions()->exists())
                ->columnSpanFull(),
            Section::make('تقارير التأخر وعدم التنقل')
                ->description('سجل بلاغات العميل لكل زيارة دورية وعامل، مع الإجراء الذي اختاره ووقت إغلاق الحالة.')
                ->schema([
                    RepeatableEntry::make('attendance_incidents')
                        ->hiddenLabel()
                        ->getStateUsing(fn (CleaningBooking $record): array => self::attendanceIncidents($record))
                        ->schema([
                            TextEntry::make('session')
                                ->label('الزيارة'),
                            TextEntry::make('worker')
                                ->label('العامل')
                                ->placeholder('-'),
                            TextEntry::make('issue')
                                ->label('البلاغ')
                                ->badge()
                                ->color(fn (string $state): string => $state === 'عدم بدء التنقل' ? 'danger' : 'warning'),
                            TextEntry::make('action')
                                ->label('قرار العميل')
                                ->badge(),
                            TextEntry::make('reported_at')
                                ->label('وقت البلاغ')
                                ->placeholder('-'),
                            TextEntry::make('resolved_at')
                                ->label('وقت الإغلاق')
                                ->placeholder('مفتوح'),
                            TextEntry::make('note')
                                ->label('ملاحظة العميل')
                                ->placeholder('لا توجد ملاحظة')
                                ->columnSpanFull(),
                        ])
                        ->columns([
                            'default' => 1,
                            'md' => 2,
                            'xl' => 3,
                        ]),
                ])
                ->visible(fn (CleaningBooking $record): bool => self::attendanceIncidents($record) !== [])
                ->columnSpanFull(),
            Section::make('تقييم العميل ومراجعته')
                ->description('يعرض تقييم العميل المرتبط بهذا الحجز وتعليقه لكل عامل تم تقييمه من تطبيق العميل.')
                ->schema([
                    RepeatableEntry::make('customer_worker_ratings')
                        ->hiddenLabel()
                        ->getStateUsing(fn (CleaningBooking $record): array => self::customerWorkerRatings($record))
                        ->schema([
                            TextEntry::make('customer_name')
                                ->label('العميل')
                                ->placeholder('-'),
                            TextEntry::make('worker_name')
                                ->label('العامل الذي تم تقييمه')
                                ->placeholder('-'),
                            TextEntry::make('rating')
                                ->label('التقييم')
                                ->formatStateUsing(fn (mixed $state): string => self::formatRating((int) $state))
                                ->badge()
                                ->color(fn (mixed $state): string => self::reviewColor((int) $state)),
                            TextEntry::make('created_at')
                                ->label('وقت التقييم')
                                ->placeholder('-'),
                            TextEntry::make('comment')
                                ->label('مراجعة العميل')
                                ->placeholder('لم يكتب العميل تعليقاً.')
                                ->columnSpanFull(),
                        ])
                        ->columns([
                            'default' => 1,
                            'md' => 2,
                            'xl' => 4,
                        ]),
                ])
                ->visible(fn (CleaningBooking $record): bool => self::customerWorkerRatings($record) !== [])
                ->columnSpanFull(),
            Section::make('تمديدات الوقت')
                ->description('سجل طلبات تمديد وقت العمل والمدة المطلوبة والمبلغ ورد العامل، بنفس بيانات تطبيق العميل والعامل.')
                ->schema([
                    TextEntry::make('extension_fee_total')
                        ->label('إجمالي رسوم التمديد المضافة')
                        ->state(fn (CleaningBooking $record): string => self::money(
                            $record->extension_fee_total,
                            (string) config('app.currency', 'SYP'),
                        ))
                        ->weight('bold'),
                    RepeatableEntry::make('timeWarnings')
                        ->label('طلبات التمديد')
                        ->schema([
                            TextEntry::make('extension_status')
                                ->label('الحالة')
                                ->state(fn (CleaningTimeWarning $record): string => self::extensionStatusLabel($record))
                                ->badge()
                                ->color(fn (CleaningTimeWarning $record): string => self::extensionStatusColor($record)),
                            TextEntry::make('additional_minutes')
                                ->label('المدة المطلوبة')
                                ->state(fn (CleaningTimeWarning $record): string => $record->additional_minutes !== null
                                    ? sprintf('%d دقيقة', (int) $record->additional_minutes)
                                    : '-'),
                            TextEntry::make('quoted_amount')
                                ->label('تكلفة التمديد')
                                ->state(fn (CleaningTimeWarning $record): string => self::money(
                                    $record->quoted_amount,
                                    $record->quoted_currency,
                                )),
                            TextEntry::make('worker.user.name')
                                ->label('العامل')
                                ->placeholder('-'),
                            TextEntry::make('customer_response_display')
                                ->label('قرار العميل')
                                ->state(fn (CleaningTimeWarning $record): string => self::timeWarningResponseLabel(
                                    $record->customer_response,
                                    false,
                                )),
                            TextEntry::make('worker_response_display')
                                ->label('رد العامل')
                                ->state(fn (CleaningTimeWarning $record): string => self::timeWarningResponseLabel(
                                    $record->worker_response,
                                    true,
                                )),
                            TextEntry::make('sent_at')
                                ->label('وقت الطلب')
                                ->dateTime('Y-m-d h:i A')
                                ->placeholder('-'),
                            TextEntry::make('worker_responded_at')
                                ->label('وقت رد العامل')
                                ->dateTime('Y-m-d h:i A')
                                ->placeholder('بانتظار رد العامل'),
                            TextEntry::make('price_applied_at')
                                ->label('وقت إضافة الرسوم')
                                ->dateTime('Y-m-d h:i A')
                                ->placeholder('لم تتم إضافة الرسوم'),
                            TextEntry::make('worker_reject_message')
                                ->label('سبب رفض العامل')
                                ->placeholder('لا يوجد')
                                ->visible(fn (CleaningTimeWarning $record): bool => filled($record->worker_reject_message))
                                ->columnSpanFull(),
                        ])
                        ->columns([
                            'default' => 1,
                            'md' => 2,
                            'xl' => 3,
                        ]),
                ])
                ->columns([
                    'default' => 1,
                    'xl' => 3,
                ])
                ->visible(fn (CleaningBooking $record): bool => $record->timeWarnings->isNotEmpty())
                ->columnSpanFull(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('assign_supervisor')
                ->label('إسناد المشرف / تغييره')
                ->icon('heroicon-o-user-group')
                ->color('info')
                ->visible(fn (): bool => $this->canManageSupervisor())
                ->authorize(fn (): bool => $this->canManageSupervisor())
                ->modalHeading('إسناد مشرف الحجز')
                ->modalDescription('الإسناد يشمل جميع الزيارات؛ تتم مراجعة تعارض المواعيد والعهدة عند التأكيد. تدفق V2 متاح للعرض وإدارة العهدة فقط.')
                ->form([
                    Select::make('supervisor_user_id')
                        ->label('المشرف المسؤول')
                        ->options(fn (): array => User::role(SupervisedTeamService::ROLE)
                            ->where('is_active', true)
                            ->orderBy('name')->orderBy('id')
                            ->get(['id', 'name', 'phone'])
                            ->mapWithKeys(fn (User $user): array => [
                                $user->id => $user->name.' ('.$user->phone.')',
                            ])->all())
                        ->default(fn (): ?int => app(CleaningSupervisorAssignmentService::class)
                            ->activeSupervisorId($this->record))
                        ->searchable()
                        ->preload()
                        ->live()
                        ->required(),
                    Textarea::make('reason')
                        ->label('سبب تغيير المشرف')
                        ->helperText('يُحفظ السبب في سجل الإسناد عند تغيير المشرف الحالي.')
                        ->minLength(10)
                        ->maxLength(500)
                        ->required(fn (Get $get): bool => $this->isSupervisorReassignment($get('supervisor_user_id')))
                        ->visible(fn (Get $get): bool => $this->isSupervisorReassignment($get('supervisor_user_id'))),
                ])
                ->action(function (array $data): void {
                    try {
                        $result = app(CleaningSupervisorAssignmentService::class)->assign(
                            auth()->user(),
                            (int) $this->record->getKey(),
                            (int) $data['supervisor_user_id'],
                            $data['reason'] ?? null,
                            ! $this->record->isSupervisedTeam(),
                            fromDashboard: true,
                        );
                    } catch (HttpExceptionInterface $exception) {
                        Notification::make()
                            ->title('تعذر إسناد المشرف')
                            ->body($exception->getMessage() ?: 'راجع حالة الحجز ومواعيد المشرف وعهدة المواد والمعدات.')
                            ->danger()->send();

                        return;
                    }

                    $this->record = $this->record->fresh() ?? $this->record;
                    Notification::make()
                        ->title($result['unchanged'] ? 'المشرف معيّن مسبقاً' : 'تم حفظ إسناد المشرف')
                        ->success()->send();
                }),
            Action::make('terminate_open_time')
                ->label('إنهاء العمل بالوقت المفتوح')
                ->icon('heroicon-o-stop-circle')
                ->color('danger')
                ->visible(function (): bool {
                    if (! CleaningBookingResource::canEdit($this->record) || $this->record->booking_kind !== 'open_time') {
                        return false;
                    }

                    return ($this->record->work_started_at !== null
                        && $this->record->work_finished_at === null
                        && $this->record->open_time_finalized_at === null)
                        || $this->record->sessions()
                            ->where('session_type', CleaningBookingSession::TYPE_OPEN_TIME)
                            ->whereNotNull('work_started_at')
                            ->whereNull('work_finished_at')->exists();
                })
                ->requiresConfirmation()
                ->modalHeading('تأكيد إنهاء العمل وإيقاف احتساب الوقت')
                ->modalDescription('سيتم تثبيت وقت الإنهاء من الخادم واحتساب الأجرة النهائية وحفظ القرار وإبلاغ العميل والعامل.')
                ->form([
                    Select::make('session_id')
                        ->label('الجلسة المطلوب إنهاؤها')
                        ->options(function (): array {
                            $sessions = $this->record->sessions()
                                ->where('session_type', CleaningBookingSession::TYPE_OPEN_TIME)
                                ->whereNotNull('work_started_at')
                                ->whereNull('work_finished_at')
                                ->orderBy('sequence')->get();
                            if ($sessions->isEmpty()) {
                                return ['0' => 'الطلب الرئيسي (بدون جلسات منفصلة)'];
                            }

                            return $sessions->mapWithKeys(fn ($session): array => [
                                (string) $session->id => 'جلسة '.(int) $session->sequence.' — '.($session->scheduled_date?->format('Y-m-d') ?? '-'),
                            ])->all();
                        })
                        ->required(),
                    Textarea::make('reason')->label('سبب الإنهاء الإداري')->required()->maxLength(2000),
                ])
                ->action(function (array $data): void {
                    $sessionId = (int) $data['session_id'];
                    $session = $sessionId > 0 ? $this->record->sessions()->findOrFail($sessionId) : null;
                    app(CleaningAdministrativeOperationsService::class)->terminateOpenTime(
                        $this->record, auth()->user(), (string) $data['reason'], $session
                    );
                    $this->record = $this->record->fresh() ?? $this->record;
                    Notification::make()->title('تم إيقاف المؤقت وتثبيت المستحقات')->success()->send();
                }),
            Action::make('view_dispute')
                ->label('عرض النزاع')
                ->url(fn () => $this->record->disputes()->first()
                    ? DisputeResource::getUrl('view', ['record' => $this->record->disputes()->first()])
                    : '#')
                ->visible(fn (): bool => $this->record->disputes()->exists()),
            Action::make('cancel_booking')
                ->label('إلغاء الحجز')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => ! in_array($this->record->status, [
                    CleaningBookingStatus::Completed,
                    CleaningBookingStatus::Cancelled,
                    CleaningBookingStatus::UnderDispute,
                    CleaningBookingStatus::PartiallyCompleted,
                ], true))
                ->requiresConfirmation()
                ->modalHeading('إلغاء الحجز')
                ->modalDescription('سيتم إلغاء الحجز وإبلاغ العميل والعاملين المرتبطين به. لا يتم تطبيق غرامة تلقائية على العميل أو العامل عند الإلغاء الإداري.')
                ->form([
                    Textarea::make('reason')
                        ->label('سبب الإلغاء')
                        ->required()
                        ->maxLength(1000),
                ])
                ->action(function (array $data): void {
                    $fromStatus = (string) ($this->record->status?->value ?? $this->record->status);
                    $cancelled = DB::transaction(function () use ($data): CleaningBooking {
                        $booking = CleaningBooking::query()
                            ->whereKey($this->record->getKey())
                            ->lockForUpdate()
                            ->firstOrFail();

                        if (in_array($booking->status, [
                            CleaningBookingStatus::Completed,
                            CleaningBookingStatus::Cancelled,
                            CleaningBookingStatus::UnderDispute,
                            CleaningBookingStatus::PartiallyCompleted,
                        ], true)) {
                            return $booking;
                        }

                        $cancelledAt = now();
                        $reason = mb_trim((string) $data['reason']);
                        $sessionIds = $booking->sessions()
                            ->whereNotIn('status', CleaningBookingSessionStatus::terminalValues())
                            ->pluck('id');

                        if ($sessionIds->isNotEmpty()) {
                            CleaningBookingSessionWorkerAssignment::query()
                                ->whereIn('cleaning_booking_session_id', $sessionIds)
                                ->whereNotIn('status', [
                                    CleaningBookingWorkerAssignmentStatus::Completed->value,
                                    CleaningBookingWorkerAssignmentStatus::Rejected->value,
                                    CleaningBookingWorkerAssignmentStatus::Withdrawn->value,
                                    CleaningBookingWorkerAssignmentStatus::Cancelled->value,
                                ])
                                ->update([
                                    'status' => CleaningBookingWorkerAssignmentStatus::Cancelled->value,
                                    'released_at' => $cancelledAt,
                                    'released_reason' => 'ألغت الإدارة الحجز: '.$reason,
                                ]);

                            $booking->sessions()
                                ->whereIn('id', $sessionIds)
                                ->update([
                                    'status' => CleaningBookingSessionStatus::Cancelled->value,
                                    'cancelled_at' => $cancelledAt,
                                    'cancellation_reason' => $reason,
                                    'cancelled_by_role' => 'admin',
                                ]);
                        }

                        $booking->forceFill([
                            'status' => CleaningBookingStatus::Cancelled,
                            'cancelled_at' => $cancelledAt,
                            'cancellation_reason' => $reason,
                            'cancelled_by_role' => 'admin',
                            'cancellation_fee' => 0,
                        ])->save();

                        app(CleaningMaterialInventoryService::class)->releaseForBooking($booking);

                        return $booking->fresh();
                    });

                    if ($cancelled->status !== CleaningBookingStatus::Cancelled) {
                        Notification::make()
                            ->title('تعذر إلغاء الحجز في حالته الحالية')
                            ->danger()
                            ->send();

                        return;
                    }

                    app(CleaningLifecycleNotificationService::class)->notifyCustomer(
                        booking: $cancelled,
                        canonicalType: 'cleaning.booking.admin_cancelled',
                        action: 'admin_cancelled',
                        actorRole: 'admin',
                        fromStatus: $fromStatus,
                        occurredAt: $cancelled->cancelled_at?->toIso8601String(),
                        extraData: [
                            'cancellationReason' => $cancelled->cancellation_reason,
                            'cancellation_reason' => $cancelled->cancellation_reason,
                        ],
                    );

                    $this->record = $cancelled;
                    $this->refreshFormData([
                        'status',
                        'cancelled_at',
                        'cancellation_reason',
                        'cancelled_by_role',
                        'cancellation_fee',
                    ]);

                    Notification::make()
                        ->title('تم إلغاء الحجز بنجاح')
                        ->success()
                        ->send();
                }),
            EditAction::make()
                ->label('تعديل')
                ->visible(fn (): bool => CleaningBookingResource::canEdit($this->record)),
        ];
    }

    /**
     * @return array<int, array{
     *     customer_name:string,
     *     worker_name:string,
     *     rating:int,
     *     comment:?string,
     *     created_at:string
     * }>
     */
    /** @return array<int, array<string, string|null>> */
    private static function attendanceIncidents(CleaningBooking $record): array
    {
        return $record->sessions
            ->flatMap(function ($session): array {
                return $session->workerAssignments
                    ->filter(fn ($assignment): bool => $assignment->late_reported_at !== null || $assignment->no_travel_reported_at !== null)
                    ->map(function ($assignment) use ($session): array {
                        $isNoTravel = $assignment->no_travel_reported_at !== null;
                        $reportedAt = $isNoTravel ? $assignment->no_travel_reported_at : $assignment->late_reported_at;

                        return [
                            'session' => sprintf(
                                'زيارة %d — %s %s',
                                (int) $session->sequence,
                                $session->scheduled_date?->format('Y-m-d') ?? '-',
                                (string) $session->scheduled_time,
                            ),
                            'worker' => $assignment->worker?->user?->name ?? $assignment->worker?->first_name ?? '-',
                            'issue' => $isNoTravel ? 'عدم بدء التنقل' : 'تأخر',
                            'action' => self::attendanceActionLabel($assignment->attendance_action),
                            'reported_at' => $reportedAt?->format('Y-m-d h:i A'),
                            'resolved_at' => $assignment->attendance_resolved_at?->format('Y-m-d h:i A'),
                            'note' => filled($assignment->attendance_note) ? (string) $assignment->attendance_note : null,
                        ];
                    })->values()->all();
            })
            ->values()
            ->all();
    }

    private static function attendanceActionLabel(?string $action): string
    {
        return match ($action) {
            'wait' => 'انتظار العامل',
            'replace' => 'طلب استبدال العامل',
            'cancel' => 'إلغاء الزيارة دون غرامة',
            default => 'لم يحدد إجراء',
        };
    }

    private static function customerWorkerRatings(CleaningBooking $record): array
    {
        $ratings = $record->relationLoaded('ratings')
            ? $record->ratings
            : $record->ratings()->with(['customer', 'worker.user'])->get();

        return $ratings
            ->filter(fn (WorkerCustomerRating $rating): bool => self::enumValue($rating->rating_type) === WorkerCustomerRatingType::CustomerToWorker->value)
            ->sortByDesc('created_at')
            ->map(fn (WorkerCustomerRating $rating): array => [
                'customer_name' => $rating->customer?->name ?? $record->customer?->name ?? '-',
                'worker_name' => $rating->worker?->user?->name ?? $rating->worker?->first_name ?? '-',
                'rating' => (int) $rating->rating,
                'comment' => filled($rating->comment) ? (string) $rating->comment : null,
                'created_at' => $rating->created_at?->format('Y-m-d h:i A') ?? '-',
            ])
            ->values()
            ->all();
    }

    private static function formatRating(int $rating): string
    {
        $rating = max(0, min(5, $rating));

        return sprintf('%s (%d / 5)', str_repeat('★', $rating).str_repeat('☆', 5 - $rating), $rating);
    }

    private static function reviewColor(int $rating): string
    {
        return match (true) {
            $rating >= 4 => 'success',
            $rating === 3 => 'warning',
            default => 'danger',
        };
    }

    private static function extensionStatusLabel(CleaningTimeWarning $warning): string
    {
        $workerResponse = self::enumValue($warning->worker_response);

        if ($warning->worker_responded_at !== null || $workerResponse !== null) {
            return match ($workerResponse) {
                CleaningTimeWarningResponse::ExtendTime->value => 'وافق العامل على التمديد',
                CleaningTimeWarningResponse::CommitCurrentTime->value => 'رفض العامل التمديد',
                default => 'تم الرد على الطلب',
            };
        }

        $bookingStatus = $warning->booking?->status;
        $bookingStatusValue = $bookingStatus instanceof CleaningBookingStatus
            ? $bookingStatus->value
            : (string) ($bookingStatus ?? '');

        if (in_array($bookingStatusValue, [
            CleaningBookingStatus::Completed->value,
            CleaningBookingStatus::Cancelled->value,
        ], true)) {
            return 'مغلق';
        }

        return self::enumValue($warning->customer_response) === CleaningTimeWarningResponse::ExtendTime->value
            ? 'بانتظار رد العامل'
            : 'قيد الانتظار';
    }

    private static function extensionStatusColor(CleaningTimeWarning $warning): string
    {
        $workerResponse = self::enumValue($warning->worker_response);

        return match ($workerResponse) {
            CleaningTimeWarningResponse::ExtendTime->value => 'success',
            CleaningTimeWarningResponse::CommitCurrentTime->value => 'danger',
            default => $warning->worker_responded_at !== null ? 'gray' : 'warning',
        };
    }

    private static function timeWarningResponseLabel(mixed $response, bool $workerResponse): string
    {
        $value = self::enumValue($response);

        if ($value === null) {
            return $workerResponse ? 'بانتظار رد العامل' : '-';
        }

        return match ($value) {
            CleaningTimeWarningResponse::ExtendTime->value => $workerResponse ? 'موافقة على التمديد' : 'طلب تمديد الوقت',
            CleaningTimeWarningResponse::CommitCurrentTime->value => $workerResponse ? 'رفض التمديد واعتماد الوقت الحالي' : 'اعتماد الوقت الحالي',
            CleaningTimeWarningResponse::FinishEarly->value => 'إنهاء العمل مبكراً',
            default => $value,
        };
    }

    private static function enumValue(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private static function money(mixed $amount, ?string $currency): string
    {
        if ($amount === null || $amount === '') {
            return '-';
        }

        $formatted = number_format((float) $amount, 0, '.', ',');
        $currency = mb_strtoupper(mb_trim((string) $currency));

        return $currency === '' || $currency === 'SYP'
            ? $formatted.' ل.س'
            : $formatted.' '.$currency;
    }

    private function canManageSupervisor(): bool
    {
        $user = auth()->user();
        $assignments = app(CleaningSupervisorAssignmentService::class);

        return $user instanceof User
            && $assignments->canAssign($user, fromDashboard: true)
            && CleaningBookingResource::canEdit($this->record)
            && $assignments->isEligible($this->record)
            && ! in_array($this->record->status, [CleaningBookingStatus::Completed, CleaningBookingStatus::Cancelled], true);
    }

    private function isSupervisorReassignment(mixed $supervisorUserId): bool
    {
        $current = app(CleaningSupervisorAssignmentService::class)->activeSupervisorId($this->record);

        return $current !== null && (int) $supervisorUserId !== $current;
    }
}
