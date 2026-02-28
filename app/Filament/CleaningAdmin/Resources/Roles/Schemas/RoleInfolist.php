<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\Roles\Schemas;

use Filament\Schemas\Schema;

final class RoleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                //
            ]);
    }
}
