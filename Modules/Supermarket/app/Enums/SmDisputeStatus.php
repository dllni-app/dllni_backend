<?php

declare(strict_types=1);

namespace Modules\Supermarket\Enums;

enum SmDisputeStatus: string
{
    case Open = 'open';
    case UnderReview = 'under_review';
    case Resolved = 'resolved';
    case Closed = 'closed';
}
