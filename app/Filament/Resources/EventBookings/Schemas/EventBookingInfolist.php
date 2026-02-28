<?php

declare(strict_types=1);

namespace App\Filament\Resources\EventBookings\Schemas;

use Filament\Schemas\Schema;

final class EventBookingInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                //
            ]);
    }
}
