<?php

declare(strict_types=1);

namespace Modules\Delivery\Enums;

enum DeliveryOrderStatus: string
{
    case New = 'new';
    case WaitingMerchantReady = 'waiting_merchant_ready';
    case SearchingForDriver = 'searching_for_driver';
    case Dispatching = 'dispatching';
    case Offered = 'offered';
    case Accepted = 'accepted';
    case InProgress = 'in_progress';
    case PickedUp = 'picked_up';
    case Delivered = 'delivered';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Stopped = 'stopped';
    case Cancelled = 'cancelled';
}
