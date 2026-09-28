<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliveryDisputes\Pages;

use App\Enums\DisputeStatus;
use App\Filament\Resources\DeliveryDisputes\DeliveryDisputeResource;
use App\Models\Dispute;
use App\Models\DisputeMessage;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

final class ViewDeliveryDispute extends ViewRecord
{
    protected static string $resource=DeliveryDisputeResource::class;

    public function getTitle():string{return 'نزاع التوصيل '.$this->record->ticket_number;}

    protected function getHeaderActions():array
    {
        return [
            Action::make('add_message')
                ->label('إضافة رسالة')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->visible(fn():bool=>DeliveryDisputeResource::canIntervene())
                ->form([Textarea::make('body')->label('الرسالة')->required()->maxLength(5000)])
                ->action(function(array $data):void{
                    /** @var Dispute $dispute */
                    $dispute=$this->record;
                    DisputeMessage::query()->create([
                        'dispute_id'=>$dispute->id,
                        'sender_id'=>auth()->id(),
                        'sender_type'=>'user',
                        'body'=>trim((string)$data['body']),
                    ]);
                    activity('delivery_disputes_admin')->performedOn($dispute)->causedBy(auth()->user())
                        ->withProperties(['message'=>$data['body']])->log('delivery_dispute_message_added');
                    Notification::make()->title('تمت إضافة الرسالة')->success()->send();
                }),
            Action::make('change_status')
                ->label('تحديث حالة النزاع')
                ->icon('heroicon-o-check-circle')
                ->visible(fn():bool=>DeliveryDisputeResource::canIntervene())
                ->form([
                    Select::make('status')->label('الحالة')
                        ->options(collect(DisputeStatus::cases())->mapWithKeys(fn(DisputeStatus $s):array=>[$s->value=>$s->label()])->all())
                        ->required(),
                    Textarea::make('reason')->label('سبب التغيير')->required()->maxLength(2000),
                ])
                ->requiresConfirmation()
                ->action(function(array $data):void{
                    /** @var Dispute $dispute */
                    $dispute=$this->record;
                    $before=$dispute->status?->value ?? (string)$dispute->status;
                    $dispute->update(['status'=>(string)$data['status']]);
                    DisputeMessage::query()->create([
                        'dispute_id'=>$dispute->id,
                        'sender_id'=>auth()->id(),
                        'sender_type'=>'user',
                        'body'=>'Admin status update: '.trim((string)$data['reason']),
                    ]);
                    activity('delivery_disputes_admin')->performedOn($dispute)->causedBy(auth()->user())
                        ->withProperties(['from'=>$before,'to'=>$data['status'],'reason'=>$data['reason']])
                        ->log('delivery_dispute_status_changed');
                    $this->refreshFormData(['status']);
                    Notification::make()->title('تم تحديث حالة النزاع')->success()->send();
                }),
        ];
    }
}
