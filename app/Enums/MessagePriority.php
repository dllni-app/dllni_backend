<?php

declare(strict_types=1);

namespace DevKandil\NotiFire\Enums;

enum MessagePriority: string
{
    case HIGH = 'high';
    case NORMAL = 'normal';
}
