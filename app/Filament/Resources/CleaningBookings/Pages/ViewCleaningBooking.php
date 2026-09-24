<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBookings\Pages;

use App\Enums\WorkerCustomerRatingType;
use App\Filament\Resources\CleaningBookings\CleaningBookingResource;
use App\Filament\Resources\Disputes\DisputeResource;
use App\Models\WorkerCustomerRating;
use App\Support\Broadcast\BroadcastAfterResponse;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningTimeWarningResponse;
use Modules\Cleaning\Events\CleaningBookingTrackingUpdated;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningTimeWarning;
use Modules\Cleaning\Services\CleaningLifecycleNotificationService;
use Throwable;

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
        ]);

        // ViewRecord schemas use a multi-column root layout by default. The
        // existing cleaning booking infolist already owns its responsive grid,
        // so keeping the root at one column prevents that grid from occupying
        // only half of the available page and leaving a large empty area.
        $schema = parent::infolist($schema)->columns(1);
        $existingComponents = $schema->getComponents(withHidden: true, withOriginalKeys: true);

        return $schema->components([
            ...$existingComponents,
            Section::make('تقييم العميل')
                ->description('تقييم العميل وتعليقه على العامل بعد تنفيذ الحجز.')
                ->collapsible()
                ->collapsed()
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
            Section::make('طلبات تمديد الوقت')
                ->description('طلبات تمديد العمل ونتيجتها. جميع الأوقات بتوقيت سوريا - حلب.')
                ->collapsible()
                ->collapsed()
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
                            TextEntry::make('worker_response_display')
                                ->label('رد العامل')
                                ->state(fn (CleaningTimeWarning $record): string => self::timeWarningResponseLabel(
                                    $record->worker_response,
                                    true,
                                )),
                            TextEntry::make('sent_at')
                                ->label('وقت الطلب')
                                ->formatStateUsing(fn ($state): string => self::localDateTime($state))
                                ->placeholder('-'),
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
                        ], true)) {
                            return $booking;
                        }

                        $booking->forceFill([
                            'status' => CleaningBookingStatus::Cancelled,
                            'cancelled_at' => now(),
                            'cancellation_reason' => mb_trim((string) $data['reason']),
                            'cancelled_by_role' => 'admin',
                            'cancellation_fee' => 0,
                        ])->save();

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
                        canonicalType: 'cleaning.booking.order_cancelled',
                        action: 'admin_cancelled',
                        actorRole: 'admin',
                        fromStatus: $fromStatus,
                        occurredAt: $cancelled->cancelled_at?->toIso8601String(),
                        extraData: [
                            'cancellationReason' => $cancelled->cancellation_reason,
                            'cancellation_reason' => $cancelled->cancellation_reason,
                        ],
                    );

                    $this->dispatchTrackingUpdate($cancelled);
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
                'created_at' => self::localDateTime($rating->created_at),
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

    private static function localDateTime(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        try {
            $timezone = (string) config('app.dashboard_timezone', 'Asia/Damascus');
            $formatted = Carbon::parse($value)->setTimezone($timezone)->format('Y-m-d h:i A');

            return str_replace(['AM', 'PM'], ['ص', 'م'], $formatted);
        } catch (Throwable) {
            return (string) $value;
        }
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

    private function dispatchTrackingUpdate(CleaningBooking $booking): void
    {
        BroadcastAfterResponse::send(new CleaningBookingTrackingUpdated($booking->id, [
            'cleaningBookingId' => $booking->id,
            'status' => $booking->status?->value,
            'workerId' => $booking->worker_id,
            'assignmentMode' => $booking->resolvedAssignmentMode(),
            'requiredWorkers' => max(1, (int) ($booking->number_of_workers ?? 1)),
            'acceptedWorkers' => $booking->acceptedWorkerCount(),
            'remainingWorkers' => $booking->remainingWorkerCount(),
            'startApprovedWorkers' => $booking->startApprovedWorkerCount(),
            'notStartApprovedWorkers' => $booking->notStartApprovedWorkerCount(),
            'isTeamFulfilled' => $booking->isTeamFulfilled(),
            'startedTravelAt' => $booking->started_travel_at?->toIso8601String(),
            'arrivedAt' => $booking->arrived_at?->toIso8601String(),
            'workStartedAt' => $booking->work_started_at?->toIso8601String(),
            'workFinishedAt' => $booking->work_finished_at?->toIso8601String(),
            'customerConfirmedAt' => $booking->customer_confirmed_at?->toIso8601String(),
            'cancelledAt' => $booking->cancelled_at?->toIso8601String(),
            'updatedAt' => now()->toIso8601String(),
        ]));
    }
}
