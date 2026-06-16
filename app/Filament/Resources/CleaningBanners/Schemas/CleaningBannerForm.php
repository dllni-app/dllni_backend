<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBanners\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class CleaningBannerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('cleaning_admin.cleaning_banners.sections.content'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')
                            ->label(__('cleaning_admin.cleaning_banners.fields.title'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('target_url')
                            ->label(__('cleaning_admin.cleaning_banners.fields.target_url'))
                            ->url()
                            ->maxLength(2048),
                        Textarea::make('subtitle')
                            ->label(__('cleaning_admin.cleaning_banners.fields.subtitle'))
                            ->columnSpanFull()
                            ->rows(3),
                        FileUpload::make('image_path')
                            ->label(__('cleaning_admin.cleaning_banners.fields.image'))
                            ->disk('public')
                            ->directory('cleaning-banners')
                            ->image()
                            ->imageEditor()
                            ->maxSize(4096)
                            ->required()
                            ->columnSpanFull(),
                    ]),
                Section::make(__('cleaning_admin.cleaning_banners.sections.visibility'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('sort_order')
                            ->label(__('cleaning_admin.cleaning_banners.fields.sort_order'))
                            ->numeric()
                            ->default(0)
                            ->required(),
                        Toggle::make('is_active')
                            ->label(__('cleaning_admin.cleaning_banners.fields.is_active'))
                            ->default(true),
                        DateTimePicker::make('starts_at')
                            ->label(__('cleaning_admin.cleaning_banners.fields.starts_at')),
                        DateTimePicker::make('ends_at')
                            ->label(__('cleaning_admin.cleaning_banners.fields.ends_at')),
                    ]),
            ]);
    }
}
