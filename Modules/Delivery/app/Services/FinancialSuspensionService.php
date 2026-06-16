<?php

declare(strict_types=1);

namespace Modules\Delivery\Services;

use Modules\Delivery\Enums\DeliveryDriverAvailabilityStatus;
use Modules\Delivery\Enums\DeliverySuspensionReason;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryFinancialAccount;

final class FinancialSuspensionService
{
    public function __construct(
        private readonly DeliveryNotificationService $notifications,
    ) {}

    public function evaluateCompanyAccount(DeliveryFinancialAccount $account, DeliveryCompany $company): void
    {
        $limit = (float) $account->financial_limit;

        if ($limit <= 0) {
            return;
        }

        $balance = (float) $account->current_balance;

        if ($balance >= $limit) {
            $this->suspendCompanyForFinancial($company, $account);

            return;
        }

        if (
            $company->is_suspended
            && $company->suspension_reason === DeliverySuspensionReason::Financial->value
        ) {
            $this->reactivateCompanyFinancial($company, $account);
        }
    }

    private function suspendCompanyForFinancial(DeliveryCompany $company, DeliveryFinancialAccount $account): void
    {
        if (
            $company->is_suspended
            && $company->suspension_reason === DeliverySuspensionReason::Financial->value
        ) {
            return;
        }

        $company->forceFill([
            'is_suspended' => true,
            'suspension_reason' => DeliverySuspensionReason::Financial->value,
            'suspended_until' => null,
        ])->save();

        $account->forceFill([
            'is_suspended' => true,
            'suspension_reason' => DeliverySuspensionReason::Financial->value,
            'suspended_at' => now(),
        ])->save();

        DeliveryDriver::query()
            ->where('company_id', $company->id)
            ->update([
                'is_suspended' => true,
                'suspension_reason' => DeliverySuspensionReason::Financial->value,
                'availability_status' => DeliveryDriverAvailabilityStatus::Offline->value,
            ]);

        $this->notifications->notifyFinancialSuspension($company);
    }

    private function reactivateCompanyFinancial(DeliveryCompany $company, DeliveryFinancialAccount $account): void
    {
        $company->forceFill([
            'is_suspended' => false,
            'suspension_reason' => null,
            'suspended_until' => null,
        ])->save();

        $account->forceFill([
            'is_suspended' => false,
            'suspension_reason' => null,
            'suspended_at' => null,
        ])->save();

        DeliveryDriver::query()
            ->where('company_id', $company->id)
            ->where('suspension_reason', DeliverySuspensionReason::Financial->value)
            ->update([
                'is_suspended' => false,
                'suspension_reason' => null,
                'suspended_until' => null,
            ]);

        $this->notifications->notifyFinancialReactivation($company);
    }
}
