<?php

declare(strict_types=1);

namespace Modules\Resturants\Enums;

enum RestaurantDocumentType: string
{
    case Identity = 'identity';
    case CommercialRegistration = 'commercial_registration';
    case HealthCertificate = 'health_certificate';
    case Other = 'other';
}
