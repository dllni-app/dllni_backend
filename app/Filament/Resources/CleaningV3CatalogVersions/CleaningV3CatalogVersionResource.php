<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningV3CatalogVersions;

use App\Filament\Clusters\CleaningCatalogCluster;
use App\Filament\Resources\CleaningV3CatalogVersions\Pages\CreateCleaningV3CatalogVersion;
use App\Filament\Resources\CleaningV3CatalogVersions\Pages\EditCleaningV3CatalogVersion;
use App\Filament\Resources\CleaningV3CatalogVersions\Pages\ListCleaningV3CatalogVersions;
use App\Filament\Resources\CleaningV3CatalogVersions\RelationManagers\ItemsRelationManager;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;

final class CleaningV3CatalogVersionResource extends Resource
{
    protected static ?string $model = CleaningV3CatalogVersion::class;

    protected static ?string $cluster = CleaningCatalogCluster::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-duplicate';

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return 'مسودات كتالوج V3';
    }

    public static function getModelLabel(): string
    {
        return 'مسودة كتالوج';
    }

    public static function getPluralModelLabel(): string
    {
        return 'مسودات كتالوج التنظيف V3';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('نسخة كتالوج — مرحلة الإعداد فقط')
                ->description('لا يتم نشر أي نسخة قبل اعتماد مصفوفة الخدمات والتسعير والتحقق من التمويل.')
                ->schema([
                    TextInput::make('title')->label('اسم المسودة')->required()->maxLength(180),
                    Textarea::make('notes')->label('ملاحظات المراجعة')->maxLength(10000)->rows(4),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->label('الاسم')->searchable()->sortable(),
                TextColumn::make('status')->label('الحالة')->badge()
                    ->formatStateUsing(static fn (string $state): string => match ($state) {
                        'draft' => 'مسودة — غير منشورة',
                        'published' => 'منشورة',
                        'retired' => 'مؤرشفة',
                        default => $state,
                    }),
                TextColumn::make('items_count')->counts('items')->label('عدد العناصر'),
                TextColumn::make('updated_at')->label('آخر تعديل')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->headerActions([\Filament\Actions\CreateAction::make()->label('مسودة جديدة')])
            ->recordActions([
                \Filament\Actions\Action::make('duplicatePublished')
                    ->label('إنشاء مسودة لتعديل الأسعار')
                    ->visible(fn (CleaningV3CatalogVersion $record): bool => $record->status === CleaningV3CatalogVersion::PUBLISHED
                        && \Modules\Cleaning\Services\CleaningV3AdminCatalogPublication::authorized())
                    ->authorize(fn (): bool => \Modules\Cleaning\Services\CleaningV3AdminCatalogPublication::authorized())
                    ->requiresConfirmation()
                    ->action(function (CleaningV3CatalogVersion $record) {
                        $draft = app(\Modules\Cleaning\Services\CleaningV3AdminCatalogPublication::class)->duplicate($record);
                        return redirect(self::getUrl('edit', ['record' => $draft]));
                    }),
                \Filament\Actions\EditAction::make()->label('إدارة المسودة')
                    ->visible(fn (CleaningV3CatalogVersion $record): bool => self::canEdit($record)),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [ItemsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCleaningV3CatalogVersions::route('/'),
            'create' => CreateCleaningV3CatalogVersion::route('/create'),
            'edit' => EditCleaningV3CatalogVersion::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return self::hasPermission('cleaning-v3-catalog.view');
    }

    public static function canView(Model $record): bool
    {
        return self::hasPermission('cleaning-v3-catalog.view');
    }

    public static function canCreate(): bool
    {
        return self::hasPermission('cleaning-v3-catalog.create');
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof CleaningV3CatalogVersion
            && $record->status === CleaningV3CatalogVersion::DRAFT
            && self::hasPermission('cleaning-v3-catalog.update');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    private static function hasPermission(string $permission): bool
    {
        $user = auth()->user();

        return $user !== null
            && ($user->hasAnyRole(['admin', 'Super Admin']) || $user->can($permission));
    }
}
