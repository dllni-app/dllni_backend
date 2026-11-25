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
use Modules\Cleaning\Services\CleaningSettledVisitFundingAuditService;
use Modules\Cleaning\Services\CleaningLifecycleNotificationService;
use Modules\Cleaning\Services\CleaningMaterialInventoryService;
use Modules\Cleaning\Services\CleaningSupervisorAssignmentService;
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
                ->description('تنبيه تدقيقي فقط: التسوية مسجلة تاريخياً، ولا يعني ذلك إثبات التمويل أو السماح بتعديل المبالغ.')
                ->schema([
                    RepeatableEntry::make('settled_visit_funding_audit')
                        ->hiddenLabel()
                        ->getStateUsing(fn (CleaningBooking $record): array => app(CleaningSettledVisitFundingAuditService::class)->unresolved($record))
                        ->schema([
                            TextEntry::make('sequence')->label('الزيارة'),
                            TextEntry::make('payment_status')->label('حالة التسوية')->badge()->color('warning'),
                            TextEntry::make('worker_amount')->label('مستحق العمال'),
                            TextEntry::make('travel')->label('المواصلات المضمنة'),
                            TextEntry::make('gap')->label('العجز غير الممول')->badge()->color('danger'),
                            TextEntry::make('reason')->label('سبب المراجعة')->columnSpanFull(),
                        ])
                        ->columns(['default' => 1, 'md' => 2, 'xl' => 3]),
                ])
                ->visible(fn (CleaningBooking $record): bool => app(CleaningSettledVisitFundingAuditService::class)->unresolved($record) !== [])
                ->columnSpanFull(),
            Section::make('تقارير التأخر وعدم التنقل')
                ->description('سجل بلاغات العميل المتعلقة بالطلب والعمال، مع الإجراء الذي اختاره ووقت إغلاق الحالة.')
                ->schema([
                    RepeatableEntry::make('attendance_incidents')
                        ->hiddenLabel()
                        ->getStateUsing(fn (CleaningBooking $record): array => self::attendanceIncidents($record))
                        ->schema([
                            TextEntry::make('session')
                                ->label('موعد الطلب'),
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
            Action::make('reviewV3OperationalAdmission')
                ->label('فحص اعتماد تشغيل الحجز V3')
                ->visible(fn (): bool => $this->record->contract_version === 'v3'
                    && data_get($this->record->property_details, 'v3_admission_hold') === true
                    && \Modules\Cleaning\Services\CleaningV3CashSettlementService::authorized())
                ->authorize(fn (): bool => \Modules\Cleaning\Services\CleaningV3CashSettlementService::authorized())
                ->action(function (): void {
                    $review = app(\Modules\Cleaning\Services\CleaningV3OperationalAdmissionReview::class)->review((int) $this->record->id);
                    $labels = [
                        'BOOKING_NOT_PENDING_OPERATIONAL_REVIEW' => 'الحجز ليس قيد انتظار الاعتماد',
                        'SIGNED_PRICE_OR_VISIT_MISMATCH' => 'عدم تطابق عرض السعر أو الزيارات',
                        'VISIT_SCHEDULE_OR_ROSTER_REVIEW_REQUIRED' => 'المواعيد أو التعيينات تحتاج مراجعة',
                        'WORKER_APK_MANIFEST_UNAVAILABLE' => 'نسخة العامل الموقعة غير مهيأة',
                        'CLEARED_FUNDING_SOURCE_INTEGRATION_REQUIRED' => 'مصدر تمويل المنصة المحقق غير مربوط بعد',
                        'SIGNED_APK_ROLLOUT_AND_VISIT_EXECUTION_QA_REQUIRED' => 'يلزم إثبات توافق النسخ واختبار التنفيذ',
                    ];
                    Notification::make()->title('الحجز غير مؤهل للإرسال')
                        ->body(implode(' | ', array_map(fn ($issue) => $labels[$issue] ?? $issue, $review['blockers'])))
                        ->warning()->persistent()->send();
                }),
            Action::make('reviewV3FundingRequirement')
                ->label('متطلبات تمويل زيارة V3')
                ->visible(fn (): bool => $this->record->contract_version === 'v3'
                    && \Modules\Cleaning\Services\CleaningV3CashSettlementService::authorized())
                ->authorize(fn (): bool => \Modules\Cleaning\Services\CleaningV3CashSettlementService::authorized())
                ->form([
                    Select::make('visitId')->label('الزيارة المطلوبة')
                        ->options(fn (): array => $this->record->sessions()->pluck('sequence', 'id')
                            ->map(fn ($sequence) => 'زيارة '.$sequence)->all())->required(),
                    \Filament\Forms\Components\TextInput::make('extensionId')->label('معرّف التمديد (إن وجد)')
                        ->helperText('اتركه فارغاً لمراجعة التمويل الحالي.')->maxLength(36),
                ])
                ->action(function (array $data): void {
                    try {
                        $visit = (int) $data['visitId'];
                        $extension = $data['extensionId'] ?? null;
                        $state = filled($extension)
                            ? app(\Modules\Cleaning\Services\CleaningV3LiveExtensionService::class)
                                ->fundingRequirement((int) $this->record->id, $visit, $extension)
                            : app(\Modules\Cleaning\Services\CleaningV3OperationalFundingService::class)
                                ->review((int) $this->record->id, $visit);
                        Notification::make()->title('بيانات التمويل المطلوبة من أمين الخزينة')
                            ->body(json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))
                            ->persistent()->send();
                    } catch (\DomainException $e) {
                        Notification::make()->title('تعذر مراجعة التمويل')->body($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('importV3FundingProof')
                ->label('استيراد إثبات تمويل زيارة V3')
                ->visible(fn (): bool => $this->record->contract_version === 'v3'
                    && \Modules\Cleaning\Services\CleaningV3CashSettlementService::authorized())
                ->authorize(fn (): bool => \Modules\Cleaning\Services\CleaningV3CashSettlementService::authorized())
                ->modalDescription('استورد الإثبات الموقّع من أمين خزينة مستقل. لا ينشئ هذا الإجراء رصيداً نقدياً أو يعدّ الدفع محصلاً.')
                ->form([
                    Select::make('visitId')->label('الزيارة المطلوبة')
                        ->options(fn (): array => $this->record->sessions()->pluck('sequence', 'id')
                            ->map(fn ($sequence) => 'زيارة '.$sequence)->all())->required(),
                    \Filament\Forms\Components\TextInput::make('extensionId')->label('معرّف التمديد (إن وجد)')->maxLength(36),
                    Textarea::make('proof')->label('الإثبات الموقّع JSON')->required()->maxLength(16000)->rows(8),
                ])
                ->action(function (array $data): void {
                    try {
                        $proof = json_decode($data['proof'], true, 32, JSON_THROW_ON_ERROR);
                        if (! is_array($proof)) {
                            throw new \DomainException('V3_TREASURY_ALLOCATION_INVALID');
                        }
                        $visit = (int) $data['visitId'];
                        $extension = $data['extensionId'] ?? null;
                        if (filled($extension)) {
                            app(\Modules\Cleaning\Services\CleaningV3LiveExtensionService::class)
                                ->apply((int) $this->record->id, $visit, $extension, $proof);
                        } else {
                            app(\Modules\Cleaning\Services\CleaningV3OperationalFundingService::class)
                                ->import((int) $this->record->id, $visit, $proof);
                        }
                        Notification::make()->title('تم التحقق من إثبات التمويل وتسجيله')->success()->send();
                    } catch (\DomainException|\JsonException $e) {
                        Notification::make()->title('لم يعتمد إثبات التمويل')->body($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('reviewV3CashSettlement')
                ->label('مراجعة التسوية النقدية V3')
                ->visible(fn (): bool => $this->record->contract_version === 'v3'
                    && \Modules\Cleaning\Services\CleaningV3CashSettlementService::authorized())
                ->authorize(fn (): bool => \Modules\Cleaning\Services\CleaningV3CashSettlementService::authorized())
                ->form([
                    Select::make('visitId')->label('الزيارة المكتملة')
                        ->options(fn (): array => $this->record->sessions()->where('status', 'completed')
                            ->pluck('sequence', 'id')->map(fn ($sequence) => 'زيارة '.$sequence)->all())->required(),
                ])
                ->action(function (array $data): void {
                    try {
                        $state = app(\Modules\Cleaning\Services\CleaningV3CashSettlementService::class)
                            ->review((int) $this->record->id, (int) $data['visitId']);
                        if (array_key_exists('collectorUserId', $state)) {
                            $name = User::query()->whereKey($state['collectorUserId'])->value('name');
                            Notification::make()->title('المحاسبة النقدية للمشرف')
                                ->body('المشرف: '.$name.' | المبلغ من العميل: '.$state['customerAmountSyp']
                                    .' | أجور العمال: '.$state['workerAmountSyp'].' | أجور الإشراف: '.$state['supervisorAmountSyp']
                                    .' | مستحق الشركة: '.$state['companyAmountSyp'].' | الحالة: '.match ($state['status']) {
                                        'reported' => 'بانتظار التسليم', 'received' => 'تم التسليم', default => 'بانتظار تصريح المشرف',
                                    })->persistent()->send();
                            return;
                        }
                        $body = 'المستحق: '.$state['totalDueSyp'].' ل.س — العجز: '.$state['remainingFundingGapSyp']
                            .' — وقت العمل الفعلي: '.$state['activeSeconds'].' ثانية';
                        foreach ($state['workerPayables'] as $worker) {
                            $body .= ' | عامل '.$worker['workerId'].': '.$worker['grossPayableSyp'].' ل.س (worker:'.$worker['workerId'].')';
                        }
                        foreach ($state['supervisorPayables'] as $key => $amount) {
                            $body .= ' | مشرف '.$key.': '.$amount.' ل.س';
                        }
                        foreach ($state['movements'] as $movement) {
                            $body .= ' | '.$movement['reference'].' — '.$movement['amount'].' ل.س — '.($movement['status'] === 'posted' ? 'مؤكد' : 'قيد التحقق');
                        }
                        Notification::make()->title('مراجعة النقد والمستحقات')->body($body)->persistent()->send();
                    } catch (\DomainException $e) {
                        Notification::make()->title('التسوية غير متاحة')->body($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('receiveV3SupervisorCash')
                ->label('تسجيل استلام نقدي من المشرف')
                ->visible(fn (): bool => $this->record->contract_version === 'v3'
                    && config('cleaning_v3_catalog.cash_settlement_enabled', false)
                    && \Modules\Cleaning\Services\CleaningV3CashSettlementService::authorized())
                ->authorize(fn (): bool => \Modules\Cleaning\Services\CleaningV3CashSettlementService::authorized())
                ->modalDescription('يسجل هذا الإجراء المال الذي تسلمته الإدارة فعلياً بعد تصريح المشرف وسداد الأجور. لا ينفذ أي تحويل مالي.')
                ->form([
                    Select::make('visitId')->label('الزيارة المكتملة')->options(fn (): array => $this->record->sessions()
                        ->where('status', 'completed')->where('supervision_mode', 'supervisor')
                        ->pluck('sequence', 'id')->map(fn ($sequence) => 'زيارة '.$sequence)->all())->required(),
                    \Filament\Forms\Components\TextInput::make('amount')->label('المبلغ المستلم فعلياً (ل.س)')->numeric()->minValue(0)->required(),
                    \Filament\Forms\Components\TextInput::make('reference')->label('رقم سند القبض')->required()->maxLength(128),
                    Textarea::make('evidence')->label('إثبات الاستلام والمحاسبة الفعلية')->required()->minLength(10)->maxLength(500),
                ])
                ->action(function (array $data): void {
                    try {
                        $amount = bcadd((string) $data['amount'], '0', 2);
                        if (bccomp((string) $data['amount'], $amount, 6) !== 0) {
                            throw new \DomainException('V3_SUPERVISOR_CASH_RECEIPT_MISMATCH');
                        }
                        app(\Modules\Cleaning\Services\CleaningV3SupervisorCashAccountingService::class)
                            ->receive((int) $this->record->id, (int) $data['visitId'], $amount, $data['reference'], $data['evidence']);
                        Notification::make()->title('تم تسجيل استلام المبلغ الفعلي')->success()->send();
                    } catch (\DomainException $e) {
                        Notification::make()->title('تعذرت المحاسبة')->body($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('recordV3SupervisorPresence')
                ->label('تسجيل إثبات حضور المشرف')
                ->visible(fn (): bool => $this->record->contract_version === 'v3'
                    && config('cleaning_v3_catalog.cash_settlement_enabled', false)
                    && \Modules\Cleaning\Services\CleaningV3CashSettlementService::authorized())
                ->authorize(fn (): bool => \Modules\Cleaning\Services\CleaningV3CashSettlementService::authorized())
                ->form([
                    Select::make('visitId')->label('الزيارة المكتملة')
                        ->options(fn (): array => $this->record->sessions()->where('status', 'completed')
                            ->where('supervision_mode', 'supervisor')->pluck('sequence', 'id')
                            ->map(fn ($sequence) => 'زيارة '.$sequence)->all())->required(),
                    Select::make('supervisorId')->label('المشرف')
                        ->options(fn (): array => User::query()->whereIn('id', $this->record->supervisorAssignments()->pluck('supervisor_user_id'))->pluck('name', 'id')->all())->required(),
                    \Filament\Forms\Components\TextInput::make('from')->label('بداية الحضور UTC')->placeholder('2026-11-25T09:00:00Z')->required(),
                    \Filament\Forms\Components\TextInput::make('until')->label('نهاية الحضور UTC')->placeholder('2026-11-25T11:00:00Z')->required(),
                    \Filament\Forms\Components\TextInput::make('reference')->label('مرجع إثبات الحضور')->required()->maxLength(128),
                    Textarea::make('evidence')->label('مصدر الإثبات الفعلي للحضور')->required()->minLength(10)->maxLength(500),
                ])
                ->action(function (array $data): void {
                    try {
                        $result = app(\Modules\Cleaning\Services\CleaningV3SupervisorAttendanceProofService::class)->record(
                            (int) $this->record->id, (int) $data['visitId'], (int) $data['supervisorId'],
                            $data['from'], $data['until'], $data['reference'], $data['evidence'],
                        );
                        Notification::make()->title('تم تسجيل الإثبات بانتظار مسؤول آخر')
                            ->body('الوقت الفعلي بعد استبعاد التوقف: '.$result['active_seconds'].' ثانية')->success()->persistent()->send();
                    } catch (\DomainException $e) {
                        Notification::make()->title('تعذر تسجيل الحضور')->body($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('verifyV3SupervisorPresence')
                ->label('اعتماد حضور المشرف')
                ->visible(fn (): bool => $this->record->contract_version === 'v3'
                    && config('cleaning_v3_catalog.cash_settlement_enabled', false)
                    && \Modules\Cleaning\Services\CleaningV3CashSettlementService::authorized())
                ->authorize(fn (): bool => \Modules\Cleaning\Services\CleaningV3CashSettlementService::authorized())
                ->form([
                    Select::make('visitId')->label('الزيارة المكتملة')
                        ->options(fn (): array => $this->record->sessions()->where('status', 'completed')
                            ->where('supervision_mode', 'supervisor')->pluck('sequence', 'id')
                            ->map(fn ($sequence) => 'زيارة '.$sequence)->all())->required(),
                    \Filament\Forms\Components\TextInput::make('reference')->label('مرجع الإثبات')->required()->maxLength(128),
                    \Filament\Forms\Components\TextInput::make('activeSeconds')->label('ثواني العمل الفعلية المطابقة للإثبات')->integer()->minValue(1)->required(),
                    \Filament\Forms\Components\Checkbox::make('verified')->label('تحققت من حضور المشرف فعلياً ومن مستند الإثبات')->rule('accepted')->required(),
                ])
                ->action(function (array $data): void {
                    try {
                        if (($data['verified'] ?? false) !== true) {
                            throw new \DomainException('V3_SUPERVISOR_ATTENDANCE_EVIDENCE_REQUIRED');
                        }
                        app(\Modules\Cleaning\Services\CleaningV3SupervisorAttendanceProofService::class)->verify(
                            (int) $this->record->id, (int) $data['visitId'], $data['reference'], (int) $data['activeSeconds'],
                        );
                        Notification::make()->title('تم اعتماد حضور المشرف')->success()->send();
                    } catch (\DomainException $e) {
                        Notification::make()->title('تعذر اعتماد الحضور')->body($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('recordV3CashMovement')
                ->label('تسجيل حركة نقدية V3')
                ->visible(fn (): bool => $this->record->contract_version === 'v3'
                    && config('cleaning_v3_catalog.cash_settlement_enabled', false)
                    && \Modules\Cleaning\Services\CleaningV3CashSettlementService::authorized())
                ->authorize(fn (): bool => \Modules\Cleaning\Services\CleaningV3CashSettlementService::authorized())
                ->modalDescription('سجل فقط مبلغاً تم استلامه أو صرفه فعلياً من صندوق المنصة. التسجيل وحده لا يثبت التحصيل ولا التسوية. يلزم تحقق مسؤول آخر من الدليل.')
                ->form([
                    Select::make('visitId')->label('الزيارة المكتملة')
                        ->options(fn (): array => $this->record->sessions()->where('status', 'completed')
                            ->pluck('sequence', 'id')->map(fn ($sequence) => 'زيارة '.$sequence)->all())->required(),
                    Select::make('kind')->label('الحركة')->options([
                        'collection' => 'استلام نقدي في صندوق المنصة', 'funding_topup' => 'إيداع تمويل فعلي لتغطية عجز الزيارة', 'payout' => 'صرف مستحق عامل أو مشرف', 'fee_refund' => 'رد رسم عامل إضافي',
                    ])->required(),
                    \Filament\Forms\Components\TextInput::make('beneficiary')->label('الطرف')
                        ->helperText('treasury للتحصيل، worker:رقم_العامل أو supervisor:رقم_المشرف للصرف، customer لرد الرسم')->required()->maxLength(96),
                    \Filament\Forms\Components\TextInput::make('amount')->label('المبلغ الفعلي بالليرة السورية (مثال 100.00)')
                        ->required()->regex('/^(?:0|[1-9][0-9]{0,11})\.[0-9]{2}$/'),
                    \Filament\Forms\Components\TextInput::make('reference')->label('مرجع سند الصندوق الفريد')->required()->maxLength(128),
                    Textarea::make('evidence')->label('دليل وسبب الحركة الفعلية')->required()->minLength(10)->maxLength(500),
                ])
                ->action(function (array $data): void {
                    try {
                        app(\Modules\Cleaning\Services\CleaningV3CashSettlementService::class)->record(
                            (int) $this->record->id, (int) $data['visitId'], $data['kind'], $data['beneficiary'],
                            $data['amount'], $data['reference'], $data['evidence'],
                        );
                        Notification::make()->title('تم تسجيل الحركة بانتظار تحقق مسؤول آخر')->success()->send();
                    } catch (\DomainException $e) {
                        Notification::make()->title('لم تسجل الحركة')->body($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('verifyV3CashMovement')
                ->label('التحقق من حركة نقدية V3')
                ->visible(fn (): bool => $this->record->contract_version === 'v3'
                    && config('cleaning_v3_catalog.cash_settlement_enabled', false)
                    && \Modules\Cleaning\Services\CleaningV3CashSettlementService::authorized())
                ->authorize(fn (): bool => \Modules\Cleaning\Services\CleaningV3CashSettlementService::authorized())
                ->modalDescription('أكد فقط بعد مطابقة سند الصندوق مع المبلغ الفعلي. لا يمكن للمسؤول الذي سجل الحركة اعتمادها.')
                ->form([
                    Select::make('visitId')->label('الزيارة المكتملة')
                        ->options(fn (): array => $this->record->sessions()->where('status', 'completed')
                            ->pluck('sequence', 'id')->map(fn ($sequence) => 'زيارة '.$sequence)->all())->required(),
                    \Filament\Forms\Components\TextInput::make('reference')->label('مرجع السند')->required()->maxLength(128),
                    \Filament\Forms\Components\TextInput::make('amount')->label('المبلغ المطابق الفعلي')->required()
                        ->regex('/^(?:0|[1-9][0-9]{0,11})\.[0-9]{2}$/'),
                    \Filament\Forms\Components\Checkbox::make('verified')->label('تحققت فعلياً من السند والمبلغ')->rule('accepted')->required(),
                ])
                ->action(function (array $data): void {
                    try {
                        if (($data['verified'] ?? false) !== true) {
                            throw new \DomainException('V3_CASH_EVIDENCE_REQUIRED');
                        }
                        $state = app(\Modules\Cleaning\Services\CleaningV3CashSettlementService::class)
                            ->verify((int) $this->record->id, (int) $data['visitId'], $data['reference'], $data['amount']);
                        Notification::make()->title($state['paymentStatus'] === 'settled'
                            ? 'تمت مطابقة التسوية النقدية' : 'تم التحقق من الحركة النقدية')->success()->send();
                    } catch (\DomainException $e) {
                        Notification::make()->title('تعذر اعتماد الحركة')->body($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('assign_supervisor')
                ->label('إسناد المشرف / تغييره')
                ->icon('heroicon-o-user-group')
                ->color('info')
                ->visible(fn (): bool => $this->canManageSupervisor())
                ->authorize(fn (): bool => $this->canManageSupervisor())
                ->modalHeading('إسناد مشرف الحجز')
                ->modalDescription('يتم إسناد المشرف لهذا الطلب فقط، مع مراجعة تعارض المواعيد والعهدة عند التأكيد.')
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
                                'الطلب — %s %s',
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
            'cancel' => 'إلغاء الطلب دون غرامة',
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
            && ! $assignments->isExecutionLocked($this->record)
            && ! in_array($this->record->status, [CleaningBookingStatus::Completed, CleaningBookingStatus::Cancelled], true);
    }

    private function isSupervisorReassignment(mixed $supervisorUserId): bool
    {
        $current = app(CleaningSupervisorAssignmentService::class)->activeSupervisorId($this->record);

        return $current !== null && (int) $supervisorUserId !== $current;
    }
}
