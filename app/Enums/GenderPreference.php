<?php

declare(strict_types=1);

namespace App\Enums;

enum GenderPreference: string
{
    case Male = 'male';
    case Female = 'female';
    case Any = 'any';
}
