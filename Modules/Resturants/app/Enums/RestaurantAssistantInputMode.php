<?php

declare(strict_types=1);

namespace Modules\Resturants\Enums;

enum RestaurantAssistantInputMode: string
{
    case Text = 'text';
    case Voice = 'voice';
}
