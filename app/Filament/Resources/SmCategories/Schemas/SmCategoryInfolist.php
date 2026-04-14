<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmCategories\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

final class SmCategoryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('store.name')
                    ->label(__('supermarket_admin.stores')),
                TextEntry::make('name')
                    ->label(__('supermarket_admin.form.category_name')),
                TextEntry::make('slug')
                    ->label(__('supermarket_admin.form.category_slug')),
                TextEntry::make('description')
                    ->label(__('supermarket_admin.form.description'))
                    ->placeholder('—')
                    ->columnSpanFull(),
                TextEntry::make('sort_order')
                    ->label(__('supermarket_admin.form.sort_order'))
                    ->numeric(),
                TextEntry::make('image_path')
                    ->label(__('supermarket_admin.form.image_path'))
                    ->placeholder('—'),
                IconEntry::make('is_active')
                    ->label(__('supermarket_admin.form.is_active'))
                    ->boolean(),
                TextEntry::make('created_at')
                    ->label(__('supermarket_admin.infolist.created_at'))
                    ->dateTime()
                    ->placeholder('—'),
                TextEntry::make('updated_at')
                    ->label(__('supermarket_admin.infolist.updated_at'))
                    ->dateTime()
                    ->placeholder('—'),
            ]);
    }
}
