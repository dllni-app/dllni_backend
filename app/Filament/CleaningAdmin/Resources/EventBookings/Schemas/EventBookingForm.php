<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\EventBookings\Schemas;

use Filament\Schemas\Schema;

final class EventBookingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                //
            ]);
    }
}
