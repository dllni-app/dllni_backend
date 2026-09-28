<?php

declare(strict_types=1);

namespace Modules\Supermarket\Services;

use Modules\Supermarket\Enums\SmCommissionType;
use Modules\Supermarket\Models\SmCommissionRule;
use Modules\Supermarket\Models\SmOrder;

final class SmCommissionCalculator
{
    /**
     * @return array{
     *   commission_rule_id:int|null,
     *   commission_base_amount:float,
     *   commission_amount:float,
     *   store_net_amount:float,
     *   commission_snapshot:array<string,mixed>
     * }
     */
    public function snapshotFor(SmOrder $order): array
    {
        $merchantDiscount = $order->coupon_id !== null && $order->platform_coupon_id === null
            ? max(0.0, (float) $order->discount_amount)
            : 0.0;

        $base = round(max(0.0, (float) $order->subtotal - $merchantDiscount), 2);

        return $this->snapshotForBase($order, $base);
    }

    /**
     * @return array{
     *   commission_rule_id:int|null,
     *   commission_base_amount:float,
     *   commission_amount:float,
     *   store_net_amount:float,
     *   commission_snapshot:array<string,mixed>
     * }
     */
    public function snapshotForBase(SmOrder $order, float $baseAmount): array
    {
        $base = round(max(0.0, $baseAmount), 2);
        $rule = $this->resolveRule((int) $order->store_id, $base);

        if ($rule === null) {
            return [
                'commission_rule_id' => null,
                'commission_base_amount' => $base,
                'commission_amount' => 0.0,
                'store_net_amount' => $base,
                'commission_snapshot' => [
                    'version' => 2,
                    'source' => 'no_active_rule',
                    'base' => 'merchant_gross_amount',
                    'calculated_at' => now()->toIso8601String(),
                ],
            ];
        }

        $commission = $rule->commission_type === SmCommissionType::Percentage
            ? $base * ((float) $rule->value / 100)
            : (float) $rule->value;

        if ($rule->max_commission_amount !== null) {
            $commission = min($commission, (float) $rule->max_commission_amount);
        }

        $commission = max(0.0, min($base, round($commission, 2)));

        return [
            'commission_rule_id' => $rule->id,
            'commission_base_amount' => $base,
            'commission_amount' => $commission,
            'store_net_amount' => round($base - $commission, 2),
            'commission_snapshot' => [
                'version' => 2,
                'source' => 'sm_commission_rule',
                'base' => 'merchant_gross_amount',
                'rule_id' => $rule->id,
                'commission_type' => $rule->commission_type->value,
                'value' => (float) $rule->value,
                'min_order_amount' => $rule->min_order_amount !== null ? (float) $rule->min_order_amount : null,
                'max_commission_amount' => $rule->max_commission_amount !== null ? (float) $rule->max_commission_amount : null,
                'starts_at' => $rule->starts_at?->toIso8601String(),
                'ends_at' => $rule->ends_at?->toIso8601String(),
                'calculated_at' => now()->toIso8601String(),
            ],
        ];
    }

    private function resolveRule(int $storeId, float $baseAmount): ?SmCommissionRule
    {
        return SmCommissionRule::query()
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->where(fn ($query) => $query->whereNull('min_order_amount')->orWhere('min_order_amount', '<=', $baseAmount))
            ->orderByRaw('COALESCE(min_order_amount, 0) DESC')
            ->orderByDesc('is_default')
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->first();
    }
}
