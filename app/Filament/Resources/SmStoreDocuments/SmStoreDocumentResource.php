<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmStoreDocuments;

use App\Filament\Concerns\AuthorizesPlatformAdminResource;
use App\Filament\Concerns\ResolvesSupermarketNavigationGroup;
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
use Illuminate\Database\Eloquent\Model;
use Modules\Supermarket\Models\SmStoreDocument;

final class SmStoreDocumentResource extends Resource
{
    use AuthorizesPlatformAdminResource;
    use ResolvesSupermarketNavigationGroup;

    protected static ?string $model = SmStoreDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = null;

    protected static ?int $navigationSort = 5;

    protected static bool $shouldRegisterNavigation = false;

    public static function getNavigationGroup(): ?string
    {
        return \App\Filament\Support\AdminNavigationGroup::supermarkets();
    }

    public static function getNavigationLabel(): string
    {
        return __('supermarket_admin.store_documents');
    }

    public static function getModelLabel(): string
    {
        return __('admin_resources.supermarket_document.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin_resources.supermarket_document.plural');
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

    public static function canViewAny(): bool
    {
        return self::dashboardAllowed('supermarket_stores.view');
    }

    public static function canView(Model $record): bool
    {
        return self::dashboardAllowed('supermarket_stores.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return self::dashboardAllowed('supermarket_stores.update');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListSmStoreDocuments::route('/'), 'view' => ViewSmStoreDocument::route('/{record}'), 'edit' => EditSmStoreDocument::route('/{record}/edit')];
    }
}
