<?php

declare(strict_types=1);

namespace App\Filament\Company\Resources\DeliveryDisputes\Pages;

use App\Filament\Company\Resources\DeliveryDisputes\DeliveryDisputeResource;
use App\Models\Dispute;
use App\Models\DisputeMessage;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

final class ViewDeliveryDispute extends ViewRecord
{
    protected static string $resource = DeliveryDisputeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('add_message')
                ->label(__('delivery_company.disputes.actions.add_message'))
                ->icon('heroicon-o-chat-bubble-left-right')
                ->visible(fn (): bool => auth()->user()?->can('delivery_disputes.update') ?? false)
                ->form([
                    Textarea::make('body')
                        ->label(__('delivery_company.disputes.fields.message_body'))
                        ->required()
                        ->maxLength(5000),
                ])
                ->action(function (array $data): void {
                    /** @var Dispute $dispute */
                    $dispute = $this->record;

                    DisputeMessage::query()->create([
                        'dispute_id' => $dispute->id,
                        'sender_id' => auth()->id(),
                        'sender_type' => 'user',
                        'body' => (string) $data['body'],
                    ]);

                    Notification::make()
                        ->title(__('delivery_company.disputes.actions.add_message'))
                        ->success()
                        ->send();

                    $this->redirect(DeliveryDisputeResource::getUrl('view', ['record' => $dispute], panel: 'company'));
                }),
        ];
    }
}
