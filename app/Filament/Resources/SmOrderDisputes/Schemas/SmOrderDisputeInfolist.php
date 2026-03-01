<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmOrderDisputes\Schemas;

use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Modules\Supermarket\Enums\SmDisputeStatus;

final class SmOrderDisputeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $statusOptions = collect(SmDisputeStatus::cases())->mapWithKeys(
            fn (SmDisputeStatus $c) => [$c->value => __('supermarket_admin.enums.dispute_status.'.$c->value)]
        )->all();

        return $schema
            ->components([
                Section::make(__('supermarket_admin.disputes'))
                    ->schema([
                        TextEntry::make('ticket_number')->label(__('supermarket_admin.form.ticket_number')),
                        TextEntry::make('order.order_number')->label(__('supermarket_admin.infolist.order_number'))->placeholder('—'),
                        TextEntry::make('status')
                            ->label(__('supermarket_admin.form.status'))
                            ->formatStateUsing(fn ($state) => $state ? ($statusOptions[$state->value] ?? $state->value) : '—')
                            ->badge(),
                        TextEntry::make('reason')->label(__('supermarket_admin.form.reason'))->placeholder('—'),
                        TextEntry::make('description')->label(__('supermarket_admin.form.description'))->placeholder('—')->columnSpanFull(),
                        TextEntry::make('resolution_notes')->label(__('supermarket_admin.form.resolution_notes'))->placeholder('—')->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
