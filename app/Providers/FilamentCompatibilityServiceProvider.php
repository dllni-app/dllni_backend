<?php

declare(strict_types=1);

namespace App\Providers;

use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\ServiceProvider;

final class FilamentCompatibilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (
            ! class_exists(\Filament\Infolists\Components\Section::class, false)
            && class_exists(\Filament\Schemas\Components\Section::class)
        ) {
            class_alias(
                \Filament\Schemas\Components\Section::class,
                \Filament\Infolists\Components\Section::class
            );
        }
    }

    public function boot(): void
    {
        Table::configureUsing(
            static fn (Table $table): Table => $table->defaultSort(
                static function (Builder $query): Builder {
                    $model = $query->getModel();
                    $createdAtColumn = $model->getCreatedAtColumn();

                    if ($model->usesTimestamps() && filled($createdAtColumn)) {
                        return $query->orderByDesc($model->qualifyColumn($createdAtColumn));
                    }

                    return $query->orderByDesc($model->getQualifiedKeyName());
                }
            )
        );
    }
}
