<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBookings\Pages;

use App\Filament\Resources\CleaningBookings\CleaningBookingResource;
use App\Filament\Resources\Disputes\DisputeResource;
use App\Models\CleaningFinancialPenalty;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningCancellationFinancialPenaltyService;
use Throwable;

final class ViewCleaningBooking extends ViewRecord
{
    protected static string $resource = CleaningBookingResource::class;

    public function getTitle(): string
    {
        return 'عرض حجز تنظيف';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('add_financial_penalty')
                ->label('إضافة غرامة مالية')
                ->icon('heroicon-o-banknotes')
                ->color('danger')
                ->visible(fn (): bool => $this->canAddFinancialPenalty())
                ->modalHeading('إضافة غرامة مالية')
                ->modalDescription('سيتم خصم الغرامة من الحساب المالي للعامل وإرسال إشعار له. لا يمكن تعديل الغرامة أو عكسها بعد الإضافة.')
                ->modalSubmitActionLabel('تأكيد الغرامة')
                ->requiresConfirmation()
                ->form([
                    Placeholder::make('worker')
                        ->label('العامل الذي ألغى الطلب')
                        ->content(fn (): string => $this->record->cancelledByWorker?->first_name ?: $this->record->cancelledByWorker?->user?->name ?: '-'),
                    Placeholder::make('booking_total')
                        ->label('الحد الأعلى للغرامة')
                        ->content(fn (): string => number_format((float) $this->record->total_price, 0).' '.config('app.currency', 'SYP')),
                    TextInput::make('amount')
                        ->label('قيمة الغرامة')
                        ->numeric()
                        ->minValue(0.01)
                        ->maxValue(fn (): float => (float) $this->record->total_price)
                        ->required()
                        ->live(debounce: 300)
                        ->helperText(fn (): string => 'يجب ألا تتجاوز إجمالي الطلب: '.number_format((float) $this->record->total_price, 0).' '.config('app.currency', 'SYP')),
                    Placeholder::make('predicted_source')
                        ->label('المصدر المالي المتوقع')
                        ->content(function (Get $get): string {
                            $amount = $get('amount');
                            if (! is_numeric($amount)) {
                                return 'أدخل قيمة الغرامة أولاً.';
                            }

                            $source = app(CleaningCancellationFinancialPenaltyService::class)
                                ->predictedSource($this->record, (float) $amount);

                            return $source === CleaningFinancialPenalty::SOURCE_DEPOSIT ? 'الإيداع' : ($source === CleaningFinancialPenalty::SOURCE_DEBT ? 'الدين' : '-');
                        }),
                    Placeholder::make('cancellation_timing')
                        ->label('توقيت الإلغاء')
                        ->content(fn (): string => $this->cancellationTimingLabel()),
                    Textarea::make('notes')
                        ->label('سبب وملاحظات الغرامة')
                        ->required()
                        ->maxLength(1000)
                        ->rows(4)
                        ->columnSpanFull(),
                ])
                ->action(function (array $data): void {
                    try {
                        app(CleaningCancellationFinancialPenaltyService::class)->apply(
                            booking: $this->record,
                            amount: (float) $data['amount'],
                            notes: (string) $data['notes'],
                            appliedByAdminId: auth()->id(),
                        );

                        $this->record = $this->record->fresh([
                            'cancelledByWorker.user',
                            'financialPenalty',
                            'workerAssignments.worker.user',
                        ]);

                        Notification::make()->title('تمت إضافة الغرامة المالية وإشعار العامل.')->success()->send();
                    } catch (InvalidArgumentException $exception) {
                        Notification::make()->title('تعذر إضافة الغرامة')->body($exception->getMessage())->danger()->persistent()->send();
                    } catch (Throwable $exception) {
                        report($exception);
                        Notification::make()->title('تعذر إضافة الغرامة')->body('حدث خطأ غير متوقع أثناء تنفيذ العملية.')->danger()->persistent()->send();
                    }
                }),
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

    private function canAddFinancialPenalty(): bool
    {
        $record = $this->record;
        if (! $record instanceof CleaningBooking) {
            return false;
        }

        $status = $record->status instanceof CleaningBookingStatus
            ? $record->status
            : CleaningBookingStatus::tryFrom((string) $record->status);

        return $status === CleaningBookingStatus::Cancelled
            && (string) $record->cancelled_by_role === 'worker'
            && $record->cancelled_by_worker_id !== null
            && ! $record->financialPenalty()->exists()
            && CleaningBookingResource::canApplyFinancialPenalty($record);
    }

    private function cancellationTimingLabel(): string
    {
        $minutes = $this->record->cancellation_offset_minutes;
        if (! is_numeric($minutes)) {
            return '-';
        }

        $minutes = (int) $minutes;

        return $minutes > 0
            ? "قبل الموعد بـ {$minutes} دقيقة"
            : ($minutes < 0 ? 'بعد الموعد بـ '.abs($minutes).' دقيقة' : 'في موعد بدء العمل');
    }
}
