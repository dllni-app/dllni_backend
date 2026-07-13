<?php

declare(strict_types=1);

namespace App\Filament\Resources\Disputes\Pages;

use App\Enums\DisputeResolution;
use App\Enums\DisputeStatus;
use App\Filament\Resources\Disputes\DisputeResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
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
                    CleaningBooking::class => ['customer', 'worker.user'],
                    EventBooking::class => ['customer'],
                    Order::class => ['customer'],
                ]);
            },
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
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
