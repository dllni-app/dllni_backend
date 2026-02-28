<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmOrderDisputes\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
use Modules\Supermarket\Enums\SmDisputeStatus;

final class SmOrderDisputeForm
{
    public static function configure(Schema $schema): Schema
    {
        $statusOptions = collect(SmDisputeStatus::cases())->mapWithKeys(
            fn (SmDisputeStatus $c) => [$c->value => __('supermarket_admin.enums.dispute_status.'.$c->value)]
        )->all();

        return $schema
            ->components([
                Select::make('status')
                    ->label(__('supermarket_admin.form.status'))
                    ->options($statusOptions)
                    ->required()
                    ->native(false),
                Textarea::make('resolution_notes')
                    ->label(__('supermarket_admin.form.resolution_notes'))
                    ->rows(4),
            ]);
    }
}
