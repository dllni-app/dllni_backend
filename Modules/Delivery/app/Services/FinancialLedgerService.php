<?php

declare(strict_types=1);

namespace Modules\Delivery\Services;

use App\Models\Dispute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Delivery\Enums\DeliveryFinancialDirection;
use Modules\Delivery\Enums\DeliveryFinancialTransactionType;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Models\DeliveryFinancialAccount;
use Modules\Delivery\Models\DeliveryFinancialTransaction;
use Modules\Delivery\Models\DeliveryOrder;

final class FinancialLedgerService
{
    public function ensureAccount(Model $owner, ?string $currency = null): DeliveryFinancialAccount
    {
        $currency ??= (string) config('delivery.pricing.default_currency', 'SYP');

        $defaults = [
            'current_balance' => 0,
            'financial_limit' => 0,
        ];

        if ($owner instanceof DeliveryCompany) {
            $defaults['financial_limit'] = $owner->financial_limit;
        }

        return DeliveryFinancialAccount::query()->firstOrCreate(
            [
                'owner_type' => $owner::class,
                'owner_id' => $owner->id,
                'currency' => $currency,
            ],
            $defaults,
        );
    }

    public function accountForCompany(DeliveryCompany $company, ?string $currency = null): DeliveryFinancialAccount
    {
        $account = $this->ensureAccount($company, $currency);

        if ((float) $account->financial_limit !== (float) $company->financial_limit) {
            $account->forceFill(['financial_limit' => $company->financial_limit])->save();
        }

        return $account->fresh();
    }

    public function recordOrderFeeDebit(DeliveryOrder $order): ?DeliveryFinancialTransaction
    {
        $company = $order->company;

        if (! $company instanceof DeliveryCompany) {
            $company = DeliveryCompany::query()->findOrFail($order->company_id);
        }

        $this->accountForCompany($company, $order->currency);

        $alreadyRecorded = DeliveryFinancialTransaction::query()
            ->where('reference_type', DeliveryOrder::class)
            ->where('reference_id', $order->id)
            ->where('transaction_type', DeliveryFinancialTransactionType::OrderFeeDebit->value)
            ->exists();

        if ($alreadyRecorded) {
            return null;
        }

        $fee = (float) $order->delivery_fee;

        if ($fee <= 0) {
            return null;
        }

        return $this->recordTransaction(
            owner: $company,
            transactionType: DeliveryFinancialTransactionType::OrderFeeDebit->value,
            direction: DeliveryFinancialDirection::Debit->value,
            amount: $fee,
            referenceType: DeliveryOrder::class,
            referenceId: $order->id,
            note: "Delivery fee for order {$order->order_number}",
            metadata: [
                'orderNumber' => $order->order_number,
                'currency' => $order->currency,
            ],
        );
    }

    public function recordDisputePenaltyDebit(
        DeliveryCompany $company,
        Dispute $dispute,
        float $amount,
        ?int $createdByUserId = null,
    ): ?DeliveryFinancialTransaction {
        if ($amount <= 0) {
            return null;
        }

        $this->accountForCompany($company);

        $alreadyRecorded = DeliveryFinancialTransaction::query()
            ->where('reference_type', Dispute::class)
            ->where('reference_id', $dispute->id)
            ->where('transaction_type', DeliveryFinancialTransactionType::DisputePenaltyDebit->value)
            ->exists();

        if ($alreadyRecorded) {
            return null;
        }

        return $this->recordTransaction(
            owner: $company,
            transactionType: DeliveryFinancialTransactionType::DisputePenaltyDebit->value,
            direction: DeliveryFinancialDirection::Debit->value,
            amount: $amount,
            referenceType: Dispute::class,
            referenceId: $dispute->id,
            note: "Dispute penalty for ticket {$dispute->ticket_number}",
            metadata: [
                'ticketNumber' => $dispute->ticket_number,
                'bookingType' => $dispute->booking_type,
                'bookingId' => $dispute->booking_id,
            ],
            createdByUserId: $createdByUserId,
        );
    }

    public function recordCollectionCredit(
        DeliveryCompany $company,
        float $amount,
        ?string $note = null,
        ?int $createdByUserId = null,
    ): DeliveryFinancialTransaction {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Collection amount must be greater than zero.');
        }

        $this->accountForCompany($company);

        return $this->recordTransaction(
            owner: $company,
            transactionType: DeliveryFinancialTransactionType::CollectionCredit->value,
            direction: DeliveryFinancialDirection::Credit->value,
            amount: $amount,
            note: $note ?? 'Manual collection',
            metadata: ['source' => 'admin_collection'],
            createdByUserId: $createdByUserId,
        );
    }

    public function recordTransaction(
        Model $owner,
        string $transactionType,
        string $direction,
        float $amount,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $note = null,
        ?array $metadata = null,
        ?int $createdByUserId = null,
    ): DeliveryFinancialTransaction {
        return DB::transaction(function () use ($owner, $transactionType, $direction, $amount, $referenceType, $referenceId, $note, $metadata, $createdByUserId) {
            $account = DeliveryFinancialAccount::query()
                ->lockForUpdate()
                ->where('owner_type', $owner::class)
                ->where('owner_id', $owner->id)
                ->firstOrFail();

            $balanceBefore = $account->current_balance;
            $balanceAfter = $direction === 'debit' ? $balanceBefore + $amount : $balanceBefore - $amount;

            $transaction = DeliveryFinancialTransaction::query()->create([
                'account_id' => $account->id,
                'transaction_type' => $transactionType,
                'direction' => $direction,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'note' => $note,
                'metadata' => $metadata,
                'created_by_user_id' => $createdByUserId,
            ]);

            $account->forceFill(['current_balance' => $balanceAfter])->save();

            return $transaction;
        });
    }
}
