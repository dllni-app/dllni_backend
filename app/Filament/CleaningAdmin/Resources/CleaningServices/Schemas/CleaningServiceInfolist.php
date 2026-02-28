<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\CleaningServices\Schemas;

use Filament\Schemas\Schema;

final class CleaningServiceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                //
            ]);
    }
}
