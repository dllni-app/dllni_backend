<?php

declare(strict_types=1);

namespace App\Filament\Resources\Disputes\Pages;

use App\Enums\DisputeResolution;
use App\Enums\DisputeStatus;
use App\Filament\Resources\Disputes\DisputeResource;
use App\Models\DisputeMessage;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\EventBooking;
use Modules\Resturants\Models\Order;

final class ViewDispute extends ViewRecord
{
    protected static string $resource = DisputeResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->record->load([
            'messages.sender',
            'booking' => function (MorphTo $morphTo): void {
                $morphTo->morphWith([
                    CleaningBooking::class => ['customer', 'worker'],
                    EventBooking::class => ['customer'],
                    Order::class => ['customer'],
                ]);
            },
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reply')
                ->label(__('cleaning_admin.disputes.actions.reply'))
                ->form([
                    Textarea::make('body')->label(__('cleaning_admin.disputes.fields.body'))->required()->rows(4),
                ])
                ->action(function (array $data): void {
                    DisputeMessage::create([
                        'dispute_id' => $this->record->id,
                        'sender_id' => auth()->id(),
                        'sender_type' => User::class,
                        'body' => $data['body'],
                    ]);
                    $this->record->load('messages.sender');
                    Notification::make()->title(__('cleaning_admin.disputes.notifications.reply_sent'))->success()->send();
                }),
            Action::make('refund_partial')
                ->label(__('cleaning_admin.disputes.actions.refund_partial'))
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading(__('cleaning_admin.disputes.modals.refund_heading'))
                ->action(function (): void {
                    $this->record->update(['resolution' => DisputeResolution::PartialRefund]);
                    Notification::make()->title(__('cleaning_admin.disputes.notifications.resolution_saved'))->success()->send();
                }),
            Action::make('deduct_worker')
                ->label(__('cleaning_admin.disputes.actions.deduct_worker'))
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading(__('cleaning_admin.disputes.modals.deduct_heading'))
                ->action(function (): void {
                    $this->record->update(['resolution' => DisputeResolution::WorkerPenalty]);
                    Notification::make()->title(__('cleaning_admin.disputes.notifications.resolution_saved'))->success()->send();
                }),
            Action::make('close')
                ->label(__('cleaning_admin.disputes.actions.close'))
                ->requiresConfirmation()
                ->modalHeading(__('cleaning_admin.disputes.modals.close_heading'))
                ->action(function (): void {
                    $this->record->update(['status' => DisputeStatus::Closed]);
                    Notification::make()->title(__('cleaning_admin.disputes.notifications.dispute_closed'))->success()->send();
                }),
            EditAction::make(),
        ];
    }
}
