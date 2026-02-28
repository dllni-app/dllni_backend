<?php

declare(strict_types=1);

namespace Modules\Cleaning\Enums;

enum CleaningTimeWarningResponse: string
{
    case ExtendTime = 'extend_time';
    case CommitCurrentTime = 'commit_current_time';
    case FinishEarly = 'finish_early';

    public function label(): string
    {
        return match ($this) {
            self::ExtendTime => 'تمديد',
            self::CommitCurrentTime => 'التزام بالوقت',
            self::FinishEarly => 'إنهاء مبكر',
        };
    }
}
