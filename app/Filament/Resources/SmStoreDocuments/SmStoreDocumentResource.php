<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmStoreDocuments;

use App\Filament\Resources\SmStoreDocuments\Pages\EditSmStoreDocument;
use App\Filament\Resources\SmStoreDocuments\Pages\ListSmStoreDocuments;
use App\Filament\Resources\SmStoreDocuments\Pages\ViewSmStoreDocument;
use App\Filament\Resources\SmStoreDocuments\Schemas\SmStoreDocumentForm;
use App\Filament\Resources\SmStoreDocuments\Schemas\SmStoreDocumentInfolist;
use App\Filament\Resources\SmStoreDocuments\Tables\SmStoreDocumentsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Modules\Supermarket\Models\SmStoreDocument;
use UnitEnum;

final class SmStoreDocumentResource extends Resource
{
    protected static ?string $model = SmStoreDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = null;

    protected static string|UnitEnum|null $navigationGroup = 'قسم المتاجر';

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return __('supermarket_admin.store_documents');
    }

    public static function getNavigationTooltip(): ?string
    {
        return __('supermarket_admin.tooltips.store_documents');
    }

    public static function form(Schema $schema): Schema
    {
        return SmStoreDocumentForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SmStoreDocumentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SmStoreDocumentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSmStoreDocuments::route('/'),
            'view' => ViewSmStoreDocument::route('/{record}'),
            'edit' => EditSmStoreDocument::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
