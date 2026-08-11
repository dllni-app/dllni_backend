<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workers\Actions;

use App\Models\Worker;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

final class ChangeWorkerAvatarAction
{
    public static function make(): Action
    {
        return Action::make('changeAvatar')
            ->label(app()->isLocale('ar') ? 'تغيير صورة العامل' : 'Change worker image')
            ->icon('heroicon-o-camera')
            ->color('gray')
            ->modalHeading(app()->isLocale('ar') ? 'تغيير صورة العامل' : 'Change worker image')
            ->modalDescription(app()->isLocale('ar')
                ? 'ارفع صورة واضحة جديدة. لن يتم حذف الصورة الحالية إلا بعد حفظ الصورة الجديدة بنجاح.'
                : 'Upload a clear new image. The current image is kept until the replacement is stored successfully.')
            ->modalSubmitActionLabel(app()->isLocale('ar') ? 'حفظ الصورة' : 'Save image')
            ->schema([
                FileUpload::make('avatar')
                    ->label(app()->isLocale('ar') ? 'الصورة الجديدة' : 'New image')
                    ->image()
                    ->imageEditor()
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->maxSize(4096)
                    ->storeFiles(false)
                    ->required(),
            ])
            ->action(function (Worker $record, array $data): void {
                $avatar = $data['avatar'] ?? null;

                if (is_array($avatar)) {
                    $avatar = Arr::first($avatar);
                }

                if (! $avatar instanceof UploadedFile) {
                    throw ValidationException::withMessages([
                        'avatar' => app()->isLocale('ar')
                            ? 'اختر صورة صالحة للعامل.'
                            : 'Select a valid worker image.',
                    ]);
                }

                $newMedia = $record
                    ->addMedia($avatar)
                    ->toMediaCollection('avatar');

                $record->clearMediaCollectionExcept('avatar', $newMedia);
                $record->unsetRelation('media');

                Notification::make()
                    ->success()
                    ->title(app()->isLocale('ar') ? 'تم تحديث صورة العامل' : 'Worker image updated')
                    ->body(app()->isLocale('ar')
                        ? 'تم استبدال صورة الملف الشخصي بنجاح.'
                        : 'The profile image was replaced successfully.')
                    ->send();
            });
    }
}
