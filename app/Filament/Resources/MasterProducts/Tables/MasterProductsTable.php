<?php

declare(strict_types=1);

namespace App\Filament\Resources\MasterProducts\Tables;

use App\Enums\MasterProductUnit;
use App\Models\MasterProduct;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class MasterProductsTable
{
    public static function configure(Table $table): Table
    {
        $unitOptions = collect(MasterProductUnit::cases())->mapWithKeys(
            fn (MasterProductUnit $unit): array => [$unit->value => __('supermarket_admin.enums.master_product_unit.'.$unit->value)]
        )->all();

        return $table
            ->searchPlaceholder('ابحث بالاسم، الباركود أو العلامة التجارية')
            ->columns([
                ImageColumn::make('image')->label('')->circular()
                    ->getStateUsing(fn (MasterProduct $record): ?string => $record->getFirstMediaUrl(MasterProduct::IMAGE_COLLECTION) ?: null),
                TextColumn::make('name')->label('المنتج المرجعي')->searchable()->sortable(),
                TextColumn::make('barcode')->label('الباركود')->searchable()->copyable()->placeholder('—'),
                TextColumn::make('category.name')->label('التصنيف')->searchable()->sortable()->placeholder('—'),
                TextColumn::make('brand')->label('العلامة التجارية')->searchable()->placeholder('—'),
                TextColumn::make('unit')->label('الوحدة')->badge()
                    ->formatStateUsing(fn (?MasterProductUnit $state): string => $state
                        ? __('supermarket_admin.enums.master_product_unit.'.$state->value)
                        : '—'),
                TextColumn::make('aliases_count')->counts('aliases')->label('أسماء بديلة')->sortable(),
                TextColumn::make('store_products_count')->counts('storeProducts')->label('منتجات متاجر مرتبطة')->sortable(),
                TextColumn::make('openfoodfacts_imported_at')->label('آخر استيراد')->dateTime('Y-m-d')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')->label('فعال')->boolean(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['category', 'media'])->withCount(['aliases', 'storeProducts']))
            ->filters([
                SelectFilter::make('category_id')->label('التصنيف')->relationship('category', 'name')->searchable()->preload(),
                SelectFilter::make('unit')->label('الوحدة')->options($unitOptions),
                SelectFilter::make('is_active')->label('فعال')->options([1 => 'نعم', 0 => 'لا']),
                Filter::make('missing_barcode')->label('بدون باركود')
                    ->query(fn (Builder $query): Builder => $query->where(fn (Builder $nested): Builder => $nested->whereNull('barcode')->orWhere('barcode', ''))),
                Filter::make('missing_category')->label('بدون تصنيف')
                    ->query(fn (Builder $query): Builder => $query->whereNull('category_id')),
                Filter::make('missing_image')->label('بدون صورة')
                    ->query(fn (Builder $query): Builder => $query->whereDoesntHave('media')),
                Filter::make('not_linked_to_store')->label('غير مرتبط بمنتجات متاجر')
                    ->query(fn (Builder $query): Builder => $query->whereDoesntHave('storeProducts')),
                Filter::make('openfoodfacts')->label('مستورد من OpenFoodFacts')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('openfoodfacts_imported_at')),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->defaultSort('updated_at', 'desc');
    }
}
