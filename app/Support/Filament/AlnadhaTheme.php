<?php

declare(strict_types=1);

namespace App\Support\Filament;

final class AlnadhaTheme
{
    public const FONT = 'Cairo';

    public const PRIMARY = '#1E2A78';

    public const SECONDARY = '#6C63FF';

    public const RESTAURANTS = '#FF7A00';

    public const CLEANING = '#42C4B6';

    /**
     * @return array<string, string>
     */
    public static function colors(): array
    {
        return [
            'primary' => self::PRIMARY,
            'secondary' => self::SECONDARY,
            'info' => self::SECONDARY,
            'restaurants' => self::RESTAURANTS,
            'warning' => self::RESTAURANTS,
            'cleaning' => self::CLEANING,
            'success' => self::CLEANING,
        ];
    }
}
