<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBookings\Pages;

use App\Filament\Resources\CleaningBookings\CleaningBookingResource;
use App\Filament\Resources\Disputes\DisputeResource;
use App\Models\CleaningFinancialPenalty;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningCancellationFinancialPenaltyService;

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
                ->requiresConfirmation()
                ->modalHeading('إضافة غرامة مالية للعامل')
                ->modalDescription('سيتم خصم الغرامة من رصيد إيداع العامل أو إضافتها إلى دينه، وسيتم إرسال إشعار له. لا يمكن تعديل الغرامة أو عكسها بعد الإضافة.')
                ->form([
                    TextInput::make('amount')
                        ->label('قيمة الغرامة')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(fn (): float => (float) $this->record->total_price)
                        ->suffix('SYP')
                        ->required()
                        ->helperText(fn (): string => 'الحد الأعلى هو إجمالي قيمة الطلب: '.number_format((float) $this->record->total_price, 0).' SYP'),
                    Textarea::make('notes')
                        ->label('سبب وملاحظات الغرامة')
                        ->rows(4)
                        ->maxLength(1000)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    try {
                        app(CleaningCancellationFinancialPenaltyService::class)->apply(
                            booking: $this->record,
                            amount: (float) $data['amount'],
                            notes: (string) $data['notes'],
                            appliedByAdminId: auth()->id(),
                        );

                        $this->record->refresh();

                        Notification::make()
                            ->title('تمت إضافة الغرامة المالية')
                            ->body('تم تحديث الحساب المالي للعامل وإرسال إشعار له.')
                            ->success()
                            ->send();
                    } catch (InvalidArgumentException $exception) {
                        Notification::make()
                            ->title('تعذر إضافة الغرامة')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                })
                ->visible(fn (): bool => $this->canAddFinancialPenalty($this->record)),
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

    private function canAddFinancialPenalty(CleaningBooking $booking): bool
    {
        return $booking->status === CleaningBookingStatus::Cancelled
            && (string) $booking->cancelled_by_role === 'worker'
            && ! CleaningFinancialPenalty::query()->where('cleaning_booking_id', $booking->id)->exists();
    }
}
