<?php

declare(strict_types=1);

namespace Tests\Unit\Restaurant;

use DateTimeImmutable;
use Modules\Resturants\Enums\OfferRewardType;
use Modules\Resturants\Services\Promotions\PromotionCartLine;
use Modules\Resturants\Services\Promotions\PromotionPricingResult;
use Modules\Resturants\Services\Promotions\PromotionRule;
use Modules\Resturants\Services\Promotions\RestaurantPromotionEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RestaurantPromotionEngineTest extends TestCase
{
    private function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-12T12:00:00+03:00');
    }

    private function line(int $id, int $product, int $quantity, int $regular = 3000000, ?int $baseline = null, int $modifiers = 0, ?int $intent = null, bool $available = true): PromotionCartLine
    {
        return new PromotionCartLine($id, 1, $product, $quantity, $regular, $baseline ?? $regular, $modifiers, $intent, $available);
    }

    private function rule(int $id = 1, array $ids = [101], int $buy = 2, int $reward = 1, int $percent = 10000,
        OfferRewardType $mode = OfferRewardType::SameProduct, array $rewards = [], bool $repeatable = true,
        ?int $cap = null, bool $active = true, ?DateTimeImmutable $endsAt = null, int $restaurant = 1): PromotionRule
    {
        return new PromotionRule($id, $restaurant, 1, 'Test promotion', $buy, $reward, $mode, $percent,
            $ids, $rewards, $repeatable, $cap, $active, null, $endsAt);
    }

    private function price(array $lines, array $rules, ?RestaurantPromotionEngine $engine = null): PromotionPricingResult
    {
        $result = ($engine ?? new RestaurantPromotionEngine())->price(1, $lines, $rules, $this->at());
        $this->assertInvariants($lines, $result);

        return $result;
    }

    private function assertInvariants(array $lines, PromotionPricingResult $result): void
    {
        self::assertSame($result->originalSubtotal - $result->ordinaryDiscount - $result->promotionDiscount, $result->netSubtotal);
        self::assertGreaterThanOrEqual(0, $result->netSubtotal);
        self::assertSame($result->netSubtotal, array_sum(array_column($result->lines, 'netTotal')));
        self::assertSame($result->promotionDiscount, array_sum(array_column($result->appliedPromotions, 'discount')));
        foreach ($lines as $line) {
            $allocated = $result->lines[$line->id]['allocations'];
            self::assertSame($line->quantity, array_sum(array_column($allocated, 'quantity')));
            foreach ($allocated as $segment) {
                self::assertGreaterThan(0, $segment['quantity']);
                self::assertGreaterThanOrEqual(0, $segment['netUnit']);
                self::assertLessThanOrEqual($segment['originalUnit'], $segment['netUnit']);
            }
        }
    }

    public static function quantities(): array
    {
        return [[1,30000,0],[2,60000,0],[3,60000,1],[4,90000,1],[5,120000,1],[6,120000,2],[9,180000,3]];
    }

    #[DataProvider('quantities')]
    public function test_same_product_quantity_progress_and_repeat(int $qty, int $total, int $applications): void
    {
        $result = $this->price([$this->line(1, 101, $qty)], [$this->rule()]);
        self::assertSame($total * 100, $result->netSubtotal);
        self::assertSame($applications, $result->appliedPromotions[1]['applications'] ?? 0);
        self::assertSame($applications, $result->lines[1]['freeQuantity']);
        if ($qty === 2) {
            self::assertSame(1, $result->progress[0]['availableRewardCount']);
        }
    }

    public function test_cap_and_non_repeatable(): void
    {
        foreach ([$this->rule(repeatable: false), $this->rule(cap: 1)] as $rule) {
            $result = $this->price([$this->line(1, 101, 6)], [$rule]);
            self::assertSame(15000000, $result->netSubtotal);
            self::assertSame([], $result->progress);
        }
    }

    public function test_partial_reward_and_modifiers_are_not_discounted(): void
    {
        $result = $this->price([$this->line(1, 101, 3, modifiers: 300000)], [$this->rule(percent: 5000)]);
        self::assertSame(8400000, $result->netSubtotal);
        self::assertSame(0, $result->lines[1]['freeQuantity']);
        self::assertSame(1, $result->lines[1]['rewardQuantity']);
        $free = $this->price([$this->line(1, 101, 3, modifiers: 300000)], [$this->rule()]);
        self::assertSame(6900000, $free->netSubtotal);
    }

    public function test_existing_discounts_do_not_stack_and_ties_keep_ordinary(): void
    {
        $result = $this->price([$this->line(1, 101, 4, baseline: 2400000)], [$this->rule()]);
        self::assertSame(8400000, $result->netSubtotal);
        self::assertSame(600000, $result->ordinaryDiscount);
        self::assertSame(3000000, $result->promotionDiscount);
        $tie = $this->price([$this->line(1, 101, 3, baseline: 2000000)], [$this->rule()]);
        self::assertSame([], $tie->appliedPromotions);
        self::assertSame(6000000, $tie->netSubtotal);
        $betterOrdinary = $this->price([$this->line(1, 101, 3, baseline: 1000000)], [$this->rule()]);
        self::assertSame([], $betterOrdinary->appliedPromotions);
    }

    public function test_same_product_can_span_configurations_but_not_different_skus(): void
    {
        $rule = $this->rule(ids: [101,102]);
        $result = $this->price([$this->line(1, 101, 2), $this->line(2, 101, 1, modifiers: 300000)], [$rule]);
        self::assertSame(6300000, $result->netSubtotal);
        $otherSku = $this->price([$this->line(1, 101, 2), $this->line(2, 102, 1)], [$rule]);
        self::assertSame(9000000, $otherSku->netSubtotal);
    }

    public function test_mix_and_match_chooses_the_cheapest_in_group(): void
    {
        $lines = [$this->line(1, 101, 1, 2000000), $this->line(2, 102, 1, 3000000), $this->line(3, 103, 1, 4000000)];
        $rule = $this->rule(ids: [101,102,103], mode: OfferRewardType::CheapestEligible);
        $result = $this->price($lines, [$rule]);
        self::assertSame(7000000, $result->netSubtotal);
        self::assertSame(1, $result->lines[1]['freeQuantity']);
        self::assertSame(0, $result->lines[2]['freeQuantity']);
        self::assertSame($result->fingerprint, $this->price(array_reverse($lines), [$rule])->fingerprint);
    }

    public function test_specific_reward_is_explicit_and_invalid_claims_are_not_charged(): void
    {
        $rule = $this->rule(buy: 1, mode: OfferRewardType::SpecificProducts, rewards: [201]);
        $meal = $this->line(1, 101, 1, 2500000);
        $ordinaryDrink = $this->line(2, 201, 1, 500000);
        $rewardDrink = $this->line(3, 201, 1, 500000, intent: 1);
        $before = $this->price([$meal, $ordinaryDrink], [$rule]);
        self::assertSame(3000000, $before->netSubtotal);
        self::assertSame(1, $before->progress[0]['availableRewardCount']);
        $claimed = $this->price([$meal, $rewardDrink], [$rule]);
        self::assertSame(2500000, $claimed->netSubtotal);
        self::assertSame(1, $claimed->lines[3]['freeQuantity']);
        $invalid = $this->price([$rewardDrink], [$rule]);
        self::assertTrue($invalid->requiresReview());
        self::assertSame(1, $invalid->lines[3]['invalidRewardQuantity']);
        self::assertSame(0, $invalid->netSubtotal);
        self::assertNull($invalid->linePayload(3)['allocations'][0]['unitPrice']);
    }

    public function test_expired_disabled_foreign_and_malformed_rules_do_not_apply(): void
    {
        $line = $this->line(1, 101, 3);
        $rules = [
            $this->rule(id: 1, active: false),
            $this->rule(id: 2, endsAt: $this->at()->modify('-1 second')),
            $this->rule(id: 3, restaurant: 2),
            $this->rule(id: 4, buy: 0),
            $this->rule(id: 5, percent: 10001),
            $this->rule(id: 6, mode: OfferRewardType::SpecificProducts, rewards: [101]),
        ];
        $result = $this->price([$line], $rules);
        self::assertSame(9000000, $result->netSubtotal);
        self::assertSame([], $result->appliedPromotions);
        $unavailable = $this->price([$this->line(1, 101, 3, available: false)], [$this->rule()]);
        self::assertSame([], $unavailable->appliedPromotions);
        $inclusive = $this->price([$line], [$this->rule(endsAt: $this->at())]);
        self::assertSame(6000000, $inclusive->netSubtotal);
    }

    public function test_exact_search_beats_greedy_on_overlapping_rules(): void
    {
        $lines = [$this->line(1, 101, 2, 10000), $this->line(2, 102, 1, 9000), $this->line(3, 103, 1, 9000)];
        $rules = [
            $this->rule(id: 1, buy: 1),
            $this->rule(id: 2, ids: [101,102], buy: 1, mode: OfferRewardType::CheapestEligible),
            $this->rule(id: 3, ids: [101,103], buy: 1, mode: OfferRewardType::CheapestEligible),
        ];
        $result = $this->price($lines, $rules);
        self::assertSame(18000, $result->promotionDiscount);
        self::assertSame(20000, $result->netSubtotal);
        self::assertFalse($result->boundedFallback);
        self::assertSame($result->fingerprint, $this->price(array_reverse($lines), array_reverse($rules))->fingerprint);
        $fallback = $this->price($lines, $rules, new RestaurantPromotionEngine(searchBudget: 1));
        self::assertTrue($fallback->boundedFallback);
        self::assertGreaterThanOrEqual($result->netSubtotal, $fallback->netSubtotal);
    }

    public function test_zero_prices_and_minor_unit_rounding(): void
    {
        $zero = $this->price([$this->line(1, 101, 3, 0)], [$this->rule()]);
        self::assertSame(0, $zero->netSubtotal);
        self::assertSame([], $zero->appliedPromotions);
        $rounded = $this->price([$this->line(1, 101, 2, 101)], [$this->rule(buy: 1, percent: 5000)]);
        self::assertSame(51, $rounded->promotionDiscount);
        self::assertSame(151, $rounded->netSubtotal);
    }

    public function test_cross_restaurant_cart_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RestaurantPromotionEngine())->price(1, [new PromotionCartLine(1, 2, 101, 1, 100, 100)], [], $this->at());
    }

    public function test_bounded_large_cart_and_invariants(): void
    {
        $lines = [];
        for ($i = 1; $i <= 30; $i++) {
            $lines[] = $this->line($i, 100 + $i, 50, 1000 * $i, modifiers: $i);
        }
        $rule = $this->rule(ids: range(101,130), mode: OfferRewardType::CheapestEligible);
        $result = $this->price($lines, [$rule]);
        self::assertTrue($result->boundedFallback);
        self::assertSame($result->fingerprint, $this->price(array_reverse($lines), [$rule])->fingerprint);
    }
}
