<?php

declare(strict_types=1);

use Modules\Delivery\Enums\DeliverySuspensionReason;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Services\DriverManagementService;

it('prevents unsuspending a financially suspended driver', function (): void {
    $company = DeliveryCompany::factory()->create();
    $driver = DeliveryDriver::factory()->create([
        'company_id' => $company->id,
        'is_suspended' => true,
        'suspension_reason' => DeliverySuspensionReason::Financial->value,
    ]);

    app(DriverManagementService::class)->unsuspend($driver);
})->throws(InvalidArgumentException::class);
