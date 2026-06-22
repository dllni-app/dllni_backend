<?php

declare(strict_types=1);

namespace App\Providers;

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
}
