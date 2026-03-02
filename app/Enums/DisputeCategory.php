<?php

declare(strict_types=1);

namespace App\Enums;

enum DisputeCategory: string
{
    case PoorQuality = 'poor_quality';
    case PropertyDamage = 'property_damage';
    case Unprofessional = 'unprofessional';
    case BillingIssue = 'billing_issue';
    case Other = 'other';

    public function label(): string
    {
        return __('cleaning_admin.enums.dispute_category.'.$this->value);
    }
}
