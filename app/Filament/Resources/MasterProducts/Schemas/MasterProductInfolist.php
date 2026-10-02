<?php

declare(strict_types=1);

namespace App\Filament\Resources\MasterProducts\Schemas;

use App\Enums\MasterProductUnit;
use App\Models\MasterProduct;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class MasterProductInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('المنتج المرجعي')->schema([
                ImageEntry::make('image')->label('الصورة')->circular()
                    ->getStateUsing(fn (MasterProduct $record): ?string => $record->getFirstMediaUrl(MasterProduct::IMAGE_COLLECTION) ?: null),
                TextEntry::make('name')->label('الاسم'),
                TextEntry::make('barcode')->label('الباركود')->copyable()->placeholder('—'),
                TextEntry::make('category.name')->label('التصنيف')->placeholder('—'),
                TextEntry::make('unit')->label('الوحدة')->formatStateUsing(
                    fn (?MasterProductUnit $state): string => $state
                        ? __('supermarket_admin.enums.master_product_unit.'.$state->value)
                        : '—'
                ),
                TextEntry::make('brand')->label('العلامة التجارية')->placeholder('—'),
                IconEntry::make('is_active')->label('فعال')->boolean(),
                TextEntry::make('description')->label('الوصف')->placeholder('—')->columnSpanFull(),
            ])->columns(3),
            Section::make('الأسماء البديلة')->schema([
                RepeatableEntry::make('aliases')->label('')
                    ->schema([TextEntry::make('alias')->label('الاسم')])->columns(1),
            ])->visible(fn (MasterProduct $record): bool => $record->aliases()->exists()),
            Section::make('الربط مع المتاجر')->schema([
                TextEntry::make('store_products_count')->label('عدد منتجات المتاجر المرتبطة')
                    ->state(fn (MasterProduct $record): int => $record->storeProducts()->count()),
                RepeatableEntry::make('storeProducts')->label('')
                    ->schema([
                        TextEntry::make('store.name')->label('المتجر')->placeholder('—'),
                        TextEntry::make('name')->label('اسم المتجر للمنتج'),
                        TextEntry::make('barcode')->label('الباركود')->placeholder('—'),
                        TextEntry::make('price')->label('السعر')->money(config('app.currency', 'SYP')),
                        TextEntry::make('stock_quantity')->label('المخزون'),
                    ])->columns(5),
            ])->visible(fn (MasterProduct $record): bool => $record->storeProducts()->exists()),
            Section::make('بيانات المصدر')->schema([
                TextEntry::make('openfoodfacts_url')->label('OpenFoodFacts')
                    ->url(fn (?string $state): ?string => $state)->openUrlInNewTab()->placeholder('—'),
                TextEntry::make('openfoodfacts_imported_at')->label('تاريخ الاستيراد')->dateTime('Y-m-d H:i')->placeholder('—'),
                TextEntry::make('openfoodfacts_last_modified_at')->label('آخر تعديل بالمصدر')->dateTime('Y-m-d H:i')->placeholder('—'),
                TextEntry::make('openfoodfacts_payload_hash')->label('بصمة المصدر')->copyable()->placeholder('—'),
            ])->columns(2)->collapsible(),
        ]);
    }
}
