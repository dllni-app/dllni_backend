<?php

declare(strict_types=1);

namespace App\Filament\Resources\MasterProducts\Schemas;

use App\Enums\MasterProductUnit;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class MasterProductForm
{
    public static function configure(Schema $schema): Schema
    {
        $unitOptions = collect(MasterProductUnit::cases())->mapWithKeys(
            fn (MasterProductUnit $unit): array => [$unit->value => __('supermarket_admin.enums.master_product_unit.'.$unit->value)]
        )->all();

        return $schema->components([
            Section::make('صورة المنتج المرجعي')
                ->description('الصورة المركزية للمنتج. رفع صورة جديدة يستبدل الصورة الحالية، بينما السعر والمخزون يبقيان خاصين بكل متجر.')
                ->schema([
                    FileUpload::make('image_upload')
                        ->label('صورة المنتج')
                        ->image()
                        ->imageEditor()
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(2048)
                        ->maxFiles(1)
                        ->storeFiles(false)
                        ->dehydrated(false)
                        ->helperText('اختياري. JPG / PNG / WebP حتى 2 MB.'),
                ]),
            Section::make('بيانات المنتج المرجعي')->schema([
                Select::make('category_id')->label('التصنيف المرجعي')
                    ->relationship('category', 'name', fn ($query) => $query->orderBy('sort_order')->orderBy('name'))
                    ->searchable()->preload()->nullable(),
                TextInput::make('name')->label('اسم المنتج')->required()->maxLength(255),
                TextInput::make('barcode')->label('الباركود')->maxLength(64)->unique(ignoreRecord: true)->nullable(),
                Select::make('unit')->label('الوحدة')->options($unitOptions)->required()->native(false),
                TextInput::make('brand')->label('العلامة التجارية')->maxLength(255)->nullable(),
                Toggle::make('is_active')->label('فعال')->default(true),
                Textarea::make('description')->label('الوصف')->columnSpanFull()->rows(3)->maxLength(65535)->nullable(),
            ])->columns(3),
            Section::make('الأسماء البديلة')->description('تستخدم لتحسين البحث والربط مع أسماء المنتجات المختلفة في المتاجر.')
                ->schema([
                    Repeater::make('aliases')->relationship()->label('')
                        ->defaultItems(0)
                        ->schema([TextInput::make('alias')->label('الاسم البديل')->required()->maxLength(255)])
                        ->addActionLabel('إضافة اسم بديل')->columnSpanFull(),
                ]),
            Section::make('مصدر OpenFoodFacts')->schema([
                TextInput::make('openfoodfacts_url')->label('رابط المصدر')->url()->maxLength(2048)->nullable(),
                TextInput::make('openfoodfacts_payload_hash')->label('بصمة البيانات')->disabled()->dehydrated(false),
                TextInput::make('openfoodfacts_imported_at')->label('آخر استيراد')->disabled()->dehydrated(false),
                TextInput::make('openfoodfacts_last_modified_at')->label('آخر تعديل بالمصدر')->disabled()->dehydrated(false),
            ])->columns(2)->collapsible(),
        ]);
    }
}
