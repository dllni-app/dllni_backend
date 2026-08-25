<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBookings\Pages;

use App\Enums\WorkerCustomerRatingType;
use App\Filament\Resources\CleaningBookings\CleaningBookingResource;
use App\Filament\Resources\Disputes\DisputeResource;
use App\Models\WorkerCustomerRating;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningTimeWarningResponse;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningTimeWarning;

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
            Action::make('view_dispute')
                ->label('عرض النزاع')
                ->url(fn () => $this->record->disputes()->first()
                    ? DisputeResource::getUrl('view', ['record' => $this->record->disputes()->first()])
                    : '#')
                ->visible(fn (): bool => $this->record->disputes()->exists()),
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
        if ($value instanceof \BackedEnum) {
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
        $currency = strtoupper(trim((string) $currency));

        return $currency === '' || $currency === 'SYP'
            ? $formatted.' ل.س'
            : $formatted.' '.$currency;
    }
}
