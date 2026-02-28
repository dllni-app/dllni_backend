<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmOrderDisputes\RelationManagers;

use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class SmOrderDisputeMessagesRelationManager extends RelationManager
{
    protected static string $relationship = 'messages';

    protected static ?string $title = null;

    public static function getTitle($ownerRecord, string $pageClass): string
    {
        return __('supermarket_admin.form.messages');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Textarea::make('message')
                    ->label(__('supermarket_admin.form.description'))
                    ->required()
                    ->rows(4),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')->label(__('supermarket_admin.infolist.order_customer'))->placeholder('—'),
                TextColumn::make('message')->label(__('supermarket_admin.form.description'))->limit(80)->placeholder('—'),
                TextColumn::make('created_at')->label(__('supermarket_admin.infolist.created_at'))->dateTime('Y-m-d H:i'),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                $this->createAction()->mutateFormDataUsing(function (array $data): array {
                    $data['user_id'] = auth()->id();

                    return $data;
                }),
            ]);
    }
}
