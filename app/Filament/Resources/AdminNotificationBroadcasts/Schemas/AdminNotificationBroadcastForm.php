<?php

declare(strict_types=1);

namespace App\Filament\Resources\AdminNotificationBroadcasts\Schemas;

use App\Enums\UserModuleType;
use App\Models\AdminNotificationBroadcast;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

final class AdminNotificationBroadcastForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('محتوى الإشعار')
                ->schema([
                    TextInput::make('title')->label('العنوان')->required()->maxLength(255),
                    Textarea::make('body')->label('نص الإشعار')->required()->rows(4),
                ]),
            Section::make('المستلمون')
                ->schema([
                    Select::make('audience_type')->label('إرسال إلى')->required()->native(false)->live()
                        ->default(AdminNotificationBroadcast::AUDIENCE_ALL)
                        ->options([
                            AdminNotificationBroadcast::AUDIENCE_ALL => 'جميع المستخدمين',
                            AdminNotificationBroadcast::AUDIENCE_MODULE_TYPES => 'فئات محددة',
                            AdminNotificationBroadcast::AUDIENCE_SPECIFIC_USERS => 'مستخدمون محددون',
                        ]),
                    CheckboxList::make('module_types')->label('الفئات')->columns(2)
                        ->options([
                            'customer' => 'المستخدمون (العملاء)',
                            UserModuleType::RestaurantSeller->value => 'المطاعم',
                            UserModuleType::SupermarketSeller->value => 'السوبر ماركت',
                            UserModuleType::CleaningWorker->value => 'التنظيف',
                            UserModuleType::DeliveryDriver->value => 'التوصيل',
                        ])
                        ->required(fn (Get $get): bool => $get('audience_type') === AdminNotificationBroadcast::AUDIENCE_MODULE_TYPES)
                        ->visible(fn (Get $get): bool => $get('audience_type') === AdminNotificationBroadcast::AUDIENCE_MODULE_TYPES),
                    Select::make('users')->label('المستخدمون')->relationship(
                        name: 'users',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query): Builder => $query->where('is_active', true)->orderBy('name'),
                    )->multiple()->searchable(['name', 'phone'])->preload()
                        ->required(fn (Get $get): bool => $get('audience_type') === AdminNotificationBroadcast::AUDIENCE_SPECIFIC_USERS)
                        ->visible(fn (Get $get): bool => $get('audience_type') === AdminNotificationBroadcast::AUDIENCE_SPECIFIC_USERS),
                ]),
        ]);
    }
}
