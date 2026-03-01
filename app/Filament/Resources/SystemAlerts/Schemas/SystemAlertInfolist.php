<?php

declare(strict_types=1);

namespace App\Filament\Resources\SystemAlerts\Schemas;

use Filament\Schemas\Schema;

final class SystemAlertInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                //
            ]);
    }
}
