<?php

declare(strict_types=1);

namespace App\Filament\Resources\SystemUsers\Support;

use App\Models\User;
use App\Models\Worker;
use App\Services\AdminAccountPasswordResetService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;

final class ResetAccountPasswordAction
{
    public static function make(): Action
    {
        return Action::make('resetAccountPassword')
            ->label('إعادة تعيين كلمة المرور')
            ->icon('heroicon-o-key')
            ->color('warning')
            ->visible(fn (User|Worker $record): bool => app(AdminAccountPasswordResetService::class)
                ->canReset(auth()->user(), $record))
            ->requiresConfirmation()
            ->modalHeading(fn (User|Worker $record): string => 'إعادة تعيين كلمة مرور '.self::accountName($record))
            ->modalDescription('أدخل كلمة مرور جديدة وأكّدها. لن يحتاج المستخدم إلى رمز SMS. سيتم تسجيل العملية وإلغاء رموز الدخول الحالية لهذا الحساب.')
            ->modalSubmitActionLabel('تأكيد إعادة التعيين')
            ->form([
                TextInput::make('new_password')
                    ->label('كلمة المرور الجديدة')
                    ->password()
                    ->revealable()
                    ->required()
                    ->minLength(8)
                    ->maxLength(255)
                    ->autocomplete('new-password'),
                TextInput::make('new_password_confirmation')
                    ->label('تأكيد كلمة المرور الجديدة')
                    ->password()
                    ->revealable()
                    ->required()
                    ->same('new_password')
                    ->autocomplete('new-password'),
            ])
            ->action(function (User|Worker $record, array $data): void {
                $admin = auth()->user();

                if (! $admin instanceof User) {
                    throw new AuthorizationException();
                }

                app(AdminAccountPasswordResetService::class)->reset(
                    $admin,
                    $record,
                    (string) $data['new_password'],
                );

                Notification::make()
                    ->title('تمت إعادة تعيين كلمة المرور بنجاح')
                    ->body('يمكن للمستخدم الدخول بكلمة المرور الجديدة. تم إلغاء رموز الدخول القديمة.')
                    ->success()
                    ->send();
            });
    }

    private static function accountName(User|Worker $record): string
    {
        if ($record instanceof Worker) {
            return (string) ($record->user?->name ?: $record->first_name);
        }

        return (string) $record->name;
    }
}
