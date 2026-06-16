<?php

declare(strict_types=1);

namespace Modules\Delivery\Services;

use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Models\DeliveryFinancialTransaction;

final class DeliveryCollectionService
{
    public function __construct(
        private readonly FinancialLedgerService $ledgerService,
        private readonly FinancialSuspensionService $suspensionService,
        private readonly DeliveryNotificationService $notifications,
    ) {}

    public function recordManualCollection(
        DeliveryCompany $company,
        float $amount,
        ?string $note = null,
        ?int $recordedByUserId = null,
    ): DeliveryFinancialTransaction {
        $transaction = $this->ledgerService->recordCollectionCredit(
            company: $company,
            amount: $amount,
            note: $note,
            createdByUserId: $recordedByUserId,
        );

        $account = $this->ledgerService->accountForCompany($company);
        $this->suspensionService->evaluateCompanyAccount($account->fresh(), $company->fresh());
        $this->notifications->notifyCollectionPosted($company, $amount, $note);

        return $transaction;
    }
}
