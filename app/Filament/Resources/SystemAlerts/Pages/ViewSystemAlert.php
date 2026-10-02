<?php

declare(strict_types=1);

namespace App\Filament\Resources\SystemAlerts\Pages;

use App\Enums\SystemAlertStatus;
use App\Filament\Resources\SystemAlerts\SystemAlertResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

final class ViewSystemAlert extends ViewRecord
{
    protected static string $resource = SystemAlertResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('acknowledge')
                ->label('استلام التنبيه')
                ->color('warning')
                ->visible(fn (): bool => SystemAlertResource::canUpdateAlerts() && $this->record->status === SystemAlertStatus::New)
                ->requiresConfirmation()
                ->action(function (): void {
                    $before=$this->record->status?->value;
                    $this->record->update([
                        'status'=>SystemAlertStatus::Acknowledged->value,
                        'acknowledged_at'=>now(),
                        'acknowledged_by'=>auth()->id(),
                    ]);
                    activity('system_alerts')->performedOn($this->record)->causedBy(auth()->user())->withProperties(['from'=>$before,'to'=>'acknowledged'])->log('system_alert_acknowledged');
                    $this->refreshFormData(['status','acknowledged_at','acknowledged_by']);
                    Notification::make()->title('تم استلام التنبيه')->success()->send();
                }),
            Action::make('resolve')
                ->label('حل التنبيه')
                ->color('success')
                ->visible(fn (): bool => SystemAlertResource::canUpdateAlerts() && $this->record->status !== SystemAlertStatus::Resolved)
                ->form([Textarea::make('resolution_note')->label('ملاحظة الحل')->required()->maxLength(2000)])
                ->requiresConfirmation()
                ->action(function (array $data): void {
                    $before=$this->record->status?->value;
                    $this->record->update([
                        'status'=>SystemAlertStatus::Resolved->value,
                        'resolved_at'=>now(),
                        'resolved_by'=>auth()->id(),
                        'resolution_note'=>trim((string)$data['resolution_note']),
                    ]);
                    activity('system_alerts')->performedOn($this->record)->causedBy(auth()->user())->withProperties(['from'=>$before,'to'=>'resolved','note'=>$data['resolution_note']])->log('system_alert_resolved');
                    $this->refreshFormData(['status','resolved_at','resolved_by','resolution_note']);
                    Notification::make()->title('تم حل التنبيه')->success()->send();
                }),
        ];
    }
}
