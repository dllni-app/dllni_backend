<?php

declare(strict_types=1);

namespace Modules\Cleaning\Enums;

enum CleaningAssignmentMode: string
{
    case PreferredWorker = 'preferred_worker';
    case OpenCount = 'open_count';
}
