<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmStoreTrustLogs;

use App\Filament\Resources\SmStoreTrustLogs\Pages\ListSmStoreTrustLogs;
use App\Filament\Resources\SmStoreTrustLogs\Pages\ViewSmStoreTrustLog;
use App\Filament\Resources\SmStoreTrustLogs\Schemas\SmStoreTrustLogInfolist;
use App\Filament\Resources\SmStoreTrustLogs\Tables\SmStoreTrustLogsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Modules\Supermarket\Models\SmStoreTrustLog;
use UnitEnum;

final class SmStoreTrustLogResource extends Resource
{
    protected static ?string $model = SmStoreTrustLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = null;

    protected static string|UnitEnum|null $navigationGroup = 'قسم المتاجر';

    protected static ?int $navigationSort = 4;

    protected static bool $shouldRegisterNavigation = false;

    public static function getNavigationLabel(): string
    {
        return __('supermarket_admin.trust_logs');
    }

    public static function getNavigationTooltip(): ?string
    {
        return __('supermarket_admin.tooltips.trust_logs');
    }

    public static function infolist(Schema $schema): Schema
    {
        return SmStoreTrustLogInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SmStoreTrustLogsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSmStoreTrustLogs::route('/'),
            'view' => ViewSmStoreTrustLog::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }
}
