<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkerCustomerRatingType: string
{
    case WorkerToCustomer = 'worker_to_customer';
    case CustomerToWorker = 'customer_to_worker';
}
