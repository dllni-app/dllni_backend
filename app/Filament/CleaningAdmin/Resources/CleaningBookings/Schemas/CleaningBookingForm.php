<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\CleaningBookings\Schemas;

use Filament\Schemas\Schema;

final class CleaningBookingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                //
            ]);
    }
}
