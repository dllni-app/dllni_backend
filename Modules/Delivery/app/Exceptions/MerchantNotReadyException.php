<?php

declare(strict_types=1);

namespace Modules\Delivery\Exceptions;

use InvalidArgumentException;

final class MerchantNotReadyException extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('The merchant has not marked this order as ready for pickup.');
    }
}
