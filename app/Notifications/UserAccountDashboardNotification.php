<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Resources\SystemUsers\SystemUserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

final class UserAccountDashboardNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly User $user,
        private readonly string $event,
        private readonly ?string $previousName = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $isRegistered = $this->event === 'registered';
        $title = $isRegistered ? 'تم إنشاء حساب مستخدم جديد' : 'قام مستخدم بتغيير اسمه';
        $body = $isRegistered
            ? sprintf('تم إنشاء حساب %s (%s) من تطبيق المستخدم.', $this->user->name, $this->user->phone ?: '-')
            : sprintf('غيّر المستخدم اسمه من %s إلى %s.', $this->previousName ?: '-', $this->user->name);

        return array_merge(
            FilamentNotification::make()
                ->title($title)
                ->body($body)
                ->icon($isRegistered ? 'heroicon-o-user-plus' : 'heroicon-o-pencil-square')
                ->info()
                ->actions([
                    Action::make('view')
                        ->label('عرض المستخدم')
                        ->url(SystemUserResource::getUrl('view', ['record' => $this->user]))
                        ->markAsRead(),
                ])
                ->getDatabaseMessage(),
            ['sound_type' => 'notify'],
        );
    }
}
