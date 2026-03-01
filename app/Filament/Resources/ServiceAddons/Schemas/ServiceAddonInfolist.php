<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServiceAddons\Schemas;

use Filament\Schemas\Schema;

final class ServiceAddonInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                //
            ]);
    }
}
