<?php

declare(strict_types=1);

namespace App\Enums;

enum DisputeCategory: string
{
    case PoorQuality = 'poor_quality';
    case PropertyDamage = 'property_damage';
    case Unprofessional = 'unprofessional';
    case BillingIssue = 'billing_issue';
    case CustomerTermsViolation = 'customer_terms_violation';
    case FinancialOrVerbalDispute = 'financial_or_verbal_dispute';
    case ForceMajeure = 'force_majeure';
    case Other = 'other';

    public function label(): string
    {
        return __('cleaning_admin.enums.dispute_category.'.$this->value);
    }
}
