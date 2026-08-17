<?php

declare(strict_types=1);

namespace App\Filament\Resources\AdminNotificationBroadcasts;

use App\Filament\Resources\AdminNotificationBroadcasts\Pages\CreateAdminNotificationBroadcast;
use App\Filament\Resources\AdminNotificationBroadcasts\Pages\ListAdminNotificationBroadcasts;
use App\Filament\Resources\AdminNotificationBroadcasts\Schemas\AdminNotificationBroadcastForm;
use App\Filament\Resources\AdminNotificationBroadcasts\Tables\AdminNotificationBroadcastsTable;
use App\Models\AdminNotificationBroadcast;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class AdminNotificationBroadcastResource extends Resource
{
    protected static ?string $model = AdminNotificationBroadcast::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?int $navigationSort = 16;

    public static function getNavigationGroup(): ?string
    {
        return __('restaurant_admin.general_sections');
    }

    public static function getNavigationLabel(): string
    {
        return 'إرسال إشعارات';
    }

    public static function getModelLabel(): string
    {
        return 'إشعار';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الإشعارات';
    }

    public static function form(Schema $schema): Schema
    {
        return AdminNotificationBroadcastForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AdminNotificationBroadcastsTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'Super Admin']) ?? false;
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAdminNotificationBroadcasts::route('/'),
            'create' => CreateAdminNotificationBroadcast::route('/create'),
        ];
    }
}
