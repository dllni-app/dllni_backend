<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Resources\CleaningMemberBonuses\CleaningMemberBonusResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Modules\Cleaning\Models\CleaningMemberBonus;

final class NewCleaningMemberBonusDashboardNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly CleaningMemberBonus $bonus)
    {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $customerName = $this->bonus->customer?->name ?? 'Member';
        $rewardValue = number_format((float) $this->bonus->reward_value, 2);

        return FilamentNotification::make()
            ->title('Member bonus pending activation')
            ->body("{$customerName} reached the loyalty rule and earned {$rewardValue} {$this->bonus->reward_type}. Activate it manually from the dashboard.")
            ->icon('heroicon-o-gift')
            ->warning()
            ->actions([
                Action::make('view')
                    ->label('Review bonus')
                    ->url(CleaningMemberBonusResource::getUrl('view', ['record' => $this->bonus]))
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }
}
