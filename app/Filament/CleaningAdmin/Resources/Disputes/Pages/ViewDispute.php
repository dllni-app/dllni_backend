<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\Disputes\Pages;

use App\Enums\DisputeResolution;
use App\Enums\DisputeStatus;
use App\Filament\CleaningAdmin\Resources\Disputes\DisputeResource;
use App\Models\DisputeMessage;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

final class ViewDispute extends ViewRecord
{
    protected static string $resource = DisputeResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->record->load(['messages.sender', 'booking.customer', 'booking.worker']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reply')
                ->label('رد')
                ->form([
                    Textarea::make('body')->label('النص')->required()->rows(4),
                ])
                ->action(function (array $data): void {
                    DisputeMessage::create([
                        'dispute_id' => $this->record->id,
                        'sender_id' => auth()->id(),
                        'sender_type' => User::class,
                        'body' => $data['body'],
                    ]);
                    $this->record->load('messages.sender');
                    Notification::make()->title('تم إرسال الرد')->success()->send();
                }),
            Action::make('refund_partial')
                ->label('إعادة جزء من المبلغ')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('إعادة جزء من المبلغ للعميل')
                ->action(function (): void {
                    $this->record->update(['resolution' => DisputeResolution::PartialRefund]);
                    Notification::make()->title('تم تسجيل القرار')->success()->send();
                }),
            Action::make('deduct_worker')
                ->label('خصم من العامل')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('خصم من رصيد العامل')
                ->action(function (): void {
                    $this->record->update(['resolution' => DisputeResolution::WorkerPenalty]);
                    Notification::make()->title('تم تسجيل القرار')->success()->send();
                }),
            Action::make('close')
                ->label('إغلاق النزاع')
                ->requiresConfirmation()
                ->modalHeading('إغلاق النزاع')
                ->action(function (): void {
                    $this->record->update(['status' => DisputeStatus::Closed]);
                    Notification::make()->title('تم إغلاق النزاع')->success()->send();
                }),
            EditAction::make(),
        ];
    }
}
