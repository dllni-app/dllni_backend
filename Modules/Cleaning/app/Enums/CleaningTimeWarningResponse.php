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
        return __('cleaning_admin.enums.time_warning_response.'.$this->value);
    }
}
