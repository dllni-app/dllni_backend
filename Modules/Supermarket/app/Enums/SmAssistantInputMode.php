<?php

declare(strict_types=1);

namespace Modules\Supermarket\Enums;

enum SmAssistantInputMode: string
{
    case Text = 'text';
    case Voice = 'voice';
}
