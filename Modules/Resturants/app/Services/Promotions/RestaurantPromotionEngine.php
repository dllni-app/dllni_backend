<?php

declare(strict_types=1);

namespace Modules\Resturants\Services\Promotions;

use DateTimeInterface;
use Generator;
use InvalidArgumentException;
use Modules\Resturants\Enums\OfferRewardType;

/**
 * Pure, deterministic quantity allocation over grouped cart lines.
 * Integer money. A group replaces (never stacks with) individual discounts.
 * Candidate enumeration and exact search are bounded; on budget exhaustion
 * retain the best feasible solution found, never an unearned reward.
 */
final class RestaurantPromotionEngine
{
    public const VERSION = 'restaurant-promotions-v1';

    public function __construct(
        private readonly int $candidateLimit = 512,
        private readonly int $generationBudget = 50000,
        private readonly int $searchBudget = 50000,
    ) {
        if ($candidateLimit < 1 || $generationBudget < 1 || $searchBudget < 1) {
            throw new InvalidArgumentException('Promotion engine budgets must be positive.');
        }
    }

    /**
     * @param list<PromotionCartLine> $cartLines
     * @param list<PromotionRule> $rules
     */
    public function price(int $restaurantId, array $cartLines, array $rules, DateTimeInterface $at): PromotionPricingResult
    {
        $lines = [];
        foreach ($cartLines as $line) {
            if ($line->restaurantId !== $restaurantId || isset($lines[$line->id])) {
                throw new InvalidArgumentException('Promotion cart must contain unique lines from one restaurant.');
            }
            $lines[$line->id] = $line;
        }
        ksort($lines, SORT_NUMERIC);

        $active = [];
        foreach ($rules as $rule) {
            if ($rule->isValidAt($restaurantId, $at)) {
                if (isset($active[$rule->id])) {
                    throw new InvalidArgumentException('Duplicate promotion rule.');
                }
                $active[$rule->id] = $rule;
            }
        }
        ksort($active, SORT_NUMERIC);

        $remaining = array_map(static fn (PromotionCartLine $line): int => $line->quantity, $lines);
        $caps = array_map(static fn (PromotionRule $rule): int => $rule->limit(), $active);
        $fallback = false;
        $candidates = $this->candidates($lines, $active, $fallback);
        usort($candidates, static fn (array $a, array $b): int =>
            ($b['gain'] <=> $a['gain']) ?: ($a['rule']->id <=> $b['rule']->id) ?: strcmp($a['key'], $b['key']));

        // A deterministic feasible starting point; exact search improves it.
        $best = [];
        $bestGain = 0;
        $greedyRemaining = $remaining;
        $greedyCaps = $caps;
        foreach ($candidates as $index => $candidate) {
            $count = $this->maxApplications($candidate, $greedyRemaining, $greedyCaps);
            if ($count > 0) {
                $best[$index] = $count;
                $bestGain += $count * $candidate['gain'];
                $this->consume($candidate, $count, $greedyRemaining, $greedyCaps);
            }
        }

        $budget = $this->searchBudget;
        $this->search($candidates, 0, $remaining, $caps, [], 0, $best, $bestGain, $budget);
        $fallback = $fallback || $budget <= 0;

        $segments = array_fill_keys(array_keys($lines), []);
        $applications = [];
        foreach ($best as $index => $count) {
            $candidate = $candidates[$index];
            $rule = $candidate['rule'];
            $this->consume($candidate, $count, $remaining, $caps);
            foreach ($candidate['qualifying'] as $lineId => $quantity) {
                $this->addSegment($segments[$lineId], $lines[$lineId], $quantity * $count, 'qualifying', $rule);
            }
            foreach ($candidate['reward'] as $lineId => $quantity) {
                $this->addSegment($segments[$lineId], $lines[$lineId], $quantity * $count, 'reward', $rule);
            }
            $applications[$rule->id] ??= [
                'rule' => $rule->snapshot(), 'applications' => 0, 'rewardQuantity' => 0,
                'freeQuantity' => 0, 'discount' => 0, 'gain' => 0, 'netRevenue' => 0,
            ];
            $application = &$applications[$rule->id];
            $application['applications'] += $count;
            $application['rewardQuantity'] += $count * $rule->rewardQuantity;
            $application['freeQuantity'] += $rule->rewardPercentBps === 10000 ? $count * $rule->rewardQuantity : 0;
            $application['discount'] += $count * $candidate['discount'];
            $application['gain'] += $count * $candidate['gain'];
            $application['netRevenue'] += $count * $candidate['netRevenue'];
            unset($application);
        }
        ksort($applications, SORT_NUMERIC);

        $resultLines = [];
        $invalid = [];
        $original = $ordinary = $promotion = $net = 0;
        foreach ($lines as $id => $line) {
            $unused = $remaining[$id];
            if ($unused > 0) {
                $role = $line->rewardOfferId === null ? 'ordinary' : 'invalid_reward';
                $this->addSegment($segments[$id], $line, $unused, $role, null);
                if ($role === 'invalid_reward') {
                    $invalid[] = [
                        'itemId' => $id, 'offerId' => $line->rewardOfferId, 'quantity' => $unused,
                        'code' => 'PROMOTION_REWARD_UNAVAILABLE',
                    ];
                }
            }
            $lineResult = [
                'originalTotal' => 0, 'netTotal' => 0, 'ordinaryDiscount' => 0, 'promotionDiscount' => 0,
                'rewardQuantity' => 0, 'freeQuantity' => 0, 'invalidRewardQuantity' => 0,
                'allocations' => array_values($segments[$id]),
            ];
            foreach ($lineResult['allocations'] as $segment) {
                $quantity = $segment['quantity'];
                if ($segment['role'] === 'invalid_reward') {
                    $lineResult['invalidRewardQuantity'] += $quantity;
                    continue;
                }
                $lineResult['originalTotal'] += $segment['originalUnit'] * $quantity;
                $lineResult['netTotal'] += $segment['netUnit'] * $quantity;
                $lineResult['ordinaryDiscount'] += $segment['ordinaryDiscount'] * $quantity;
                $lineResult['promotionDiscount'] += $segment['promotionDiscount'] * $quantity;
                $lineResult['rewardQuantity'] += $segment['role'] === 'reward' ? $quantity : 0;
                $lineResult['freeQuantity'] += $segment['free'] ? $quantity : 0;
            }
            $resultLines[$id] = $lineResult;
            $original += $lineResult['originalTotal'];
            $ordinary += $lineResult['ordinaryDiscount'];
            $promotion += $lineResult['promotionDiscount'];
            $net += $lineResult['netTotal'];
        }

        $fingerprint = hash('sha256', json_encode([
            self::VERSION,
            $restaurantId,
            array_values($lines),
            array_values(array_map(static fn (PromotionRule $rule): array => $rule->snapshot(), $active)),
            $resultLines, $applications,
        ], JSON_THROW_ON_ERROR));

        return new PromotionPricingResult(
            $resultLines, $original, $ordinary, $promotion, $net, $applications,
            $this->progress($lines, $active, $remaining, $caps, $applications),
            $invalid, $fingerprint, $fallback,
        );
    }

    private function candidates(array $lines, array $rules, bool &$fallback): array
    {
        $result = [];
        $budget = $this->generationBudget;
        foreach ($rules as $rule) {
            $qualifying = array_filter($lines, static fn (PromotionCartLine $line): bool =>
                $line->available && $line->rewardOfferId === null && in_array($line->productId, $rule->qualifyingIds, true));
            if ($rule->rewardType === OfferRewardType::SpecificProducts) {
                $rewards = array_filter($lines, static fn (PromotionCartLine $line): bool =>
                    $line->available && $line->rewardOfferId === $rule->id && in_array($line->productId, $rule->availableRewardIds ?? $rule->rewardIds, true));
                foreach ($this->compositions($qualifying, $rule->buyQuantity, $budget) as $paid) {
                    foreach ($this->compositions($rewards, $rule->rewardQuantity, $budget) as $reward) {
                        $this->candidate($result, $lines, $rule, $paid, $reward);
                        if (count($result) >= $this->candidateLimit || $budget <= 0) {
                            $fallback = true;
                            break 3;
                        }
                    }
                }
            } else {
                $sets = [$qualifying];
                if ($rule->rewardType === OfferRewardType::SameProduct) {
                    $sets = [];
                    foreach ($qualifying as $line) {
                        $sets[$line->productId][$line->id] = $line;
                    }
                    ksort($sets, SORT_NUMERIC);
                }
                foreach ($sets as $set) {
                    foreach ($this->compositions($set, $rule->buyQuantity + $rule->rewardQuantity, $budget) as $group) {
                        // The cheapest eligible selected units are the reward.
                        $ids = array_keys($group);
                        usort($ids, static fn (int $a, int $b): int =>
                            ($lines[$a]->regularBase <=> $lines[$b]->regularBase)
                            ?: ($lines[$a]->productId <=> $lines[$b]->productId) ?: ($a <=> $b));
                        $paid = $group;
                        $reward = [];
                        $needed = $rule->rewardQuantity;
                        foreach ($ids as $id) {
                            $take = min($needed, $group[$id]);
                            if ($take > 0) {
                                $reward[$id] = $take;
                                $paid[$id] -= $take;
                                if ($paid[$id] === 0) {
                                    unset($paid[$id]);
                                }
                                $needed -= $take;
                            }
                            if ($needed === 0) {
                                break;
                            }
                        }
                        $this->candidate($result, $lines, $rule, $paid, $reward);
                        if (count($result) >= $this->candidateLimit || $budget <= 0) {
                            $fallback = true;
                            break 3;
                        }
                    }
                }
            }
            if ($budget <= 0) {
                $fallback = true;
                break;
            }
        }

        return array_values($result);
    }

    /** Enumerate line-count combinations without allocating an object per unit. */
    private function compositions(array $lines, int $needed, int &$budget): Generator
    {
        $ids = array_keys($lines);
        $suffix = [count($ids) => 0];
        for ($i = count($ids) - 1; $i >= 0; $i--) {
            $suffix[$i] = $suffix[$i + 1] + $lines[$ids[$i]]->quantity;
        }
        if ($needed <= ($suffix[0] ?? 0)) {
            yield from $this->compositionAt($lines, $ids, $suffix, 0, $needed, [], $budget);
        }
    }

    private function compositionAt(array $lines, array $ids, array $suffix, int $index, int $needed, array $counts, int &$budget): Generator
    {
        if ($budget <= 0) {
            return;
        }
        $budget--;
        if ($needed === 0) {
            yield $counts;
            return;
        }
        if (! isset($ids[$index]) || $suffix[$index] < $needed) {
            return;
        }
        $id = $ids[$index];
        $max = min($needed, $lines[$id]->quantity);
        $min = max(0, $needed - $suffix[$index + 1]);
        for ($take = $max; $take >= $min && $budget > 0; $take--) {
            $next = $counts;
            if ($take > 0) {
                $next[$id] = $take;
            }
            yield from $this->compositionAt($lines, $ids, $suffix, $index + 1, $needed - $take, $next, $budget);
        }
    }

    private function candidate(array &$result, array $lines, PromotionRule $rule, array $paid, array $reward): void
    {
        ksort($paid, SORT_NUMERIC);
        ksort($reward, SORT_NUMERIC);
        $usage = $paid;
        foreach ($reward as $id => $quantity) {
            $usage[$id] = ($usage[$id] ?? 0) + $quantity;
        }
        ksort($usage, SORT_NUMERIC);
        $original = $baseline = $discount = 0;
        foreach ($usage as $id => $quantity) {
            $original += $lines[$id]->originalUnit() * $quantity;
            $baseline += $lines[$id]->baselineUnit() * $quantity;
        }
        foreach ($reward as $id => $quantity) {
            $discount += $rule->rewardDiscount($lines[$id]->regularBase) * $quantity;
        }
        $net = $original - $discount;
        $gain = $baseline - $net;
        if ($gain <= 0) {
            return;
        }
        $key = json_encode([$rule->id, $paid, $reward], JSON_THROW_ON_ERROR);
        $result[$key] = [
            'rule' => $rule, 'key' => $key, 'qualifying' => $paid, 'reward' => $reward,
            'usage' => $usage, 'gain' => $gain, 'discount' => $discount, 'netRevenue' => $net,
        ];
    }

    private function maxApplications(array $candidate, array $remaining, array $caps): int
    {
        $max = $caps[$candidate['rule']->id];
        foreach ($candidate['usage'] as $id => $quantity) {
            $max = min($max, intdiv($remaining[$id], $quantity));
        }

        return $max;
    }

    private function consume(array $candidate, int $count, array &$remaining, array &$caps): void
    {
        $caps[$candidate['rule']->id] -= $count;
        foreach ($candidate['usage'] as $id => $quantity) {
            $remaining[$id] -= $quantity * $count;
        }
    }

    private function search(array $candidates, int $index, array $remaining, array $caps, array $selected, int $gain, array &$best, int &$bestGain, int &$budget): void
    {
        if ($budget <= 0) {
            return;
        }
        $budget--;
        if ($gain > $bestGain || ($gain === $bestGain && $this->selectionKey($selected, $candidates) < $this->selectionKey($best, $candidates))) {
            $best = $selected;
            $bestGain = $gain;
        }

        $count = count($candidates);
        while ($index < $count && $this->maxApplications($candidates[$index], $remaining, $caps) === 0) {
            $index++;
        }
        if ($index >= $count) {
            return;
        }

        $candidate = $candidates[$index];
        $max = $this->maxApplications($candidate, $remaining, $caps);
        // Avoid exploring unbounded multiplicities for a final candidate.
        if ($index === $count - 1) {
            $selected[$index] = $max;
            $nextGain = $gain + $max * $candidate['gain'];
            if ($nextGain > $bestGain || ($nextGain === $bestGain && $this->selectionKey($selected, $candidates) < $this->selectionKey($best, $candidates))) {
                $best = $selected;
                $bestGain = $nextGain;
            }
            return;
        }

        for ($applications = $max; $applications >= 0 && $budget > 0; $applications--) {
            $nextRemaining = $remaining;
            $nextCaps = $caps;
            $nextSelected = $selected;
            if ($applications > 0) {
                $nextSelected[$index] = $applications;
                $this->consume($candidate, $applications, $nextRemaining, $nextCaps);
            }
            $this->search($candidates, $index + 1, $nextRemaining, $nextCaps, $nextSelected,
                $gain + $applications * $candidate['gain'], $best, $bestGain, $budget);
        }
    }

    private function selectionKey(array $selected, array $candidates): string
    {
        $keys = [];
        foreach ($selected as $index => $count) {
            $candidate = $candidates[$index];
            $keys[] = sprintf('%020d', $candidate['rule']->id).':'.$candidate['key'].':'.$count;
        }
        sort($keys, SORT_STRING);

        return implode('|', $keys);
    }

    private function addSegment(array &$segments, PromotionCartLine $line, int $quantity, string $role, ?PromotionRule $rule): void
    {
        $discount = $role === 'reward' ? $rule->rewardDiscount($line->regularBase) : 0;
        $ordinaryDiscount = $role === 'ordinary' ? $line->regularBase - $line->baselineBase : 0;
        $net = match ($role) {
            'ordinary' => $line->baselineUnit(),
            'invalid_reward' => 0,
            default => $line->originalUnit() - $discount,
        };
        $offerId = $rule?->id ?? ($role === 'invalid_reward' ? $line->rewardOfferId : null);
        $key = $role.':'.($offerId ?? 0).':'.$net;
        $segments[$key] ??= [
            'role' => $role, 'offerId' => $offerId, 'quantity' => 0, 'netUnit' => $net,
            'originalUnit' => $line->originalUnit(), 'ordinaryDiscount' => $ordinaryDiscount,
            'promotionDiscount' => $discount, 'free' => $role === 'reward' && $rule->rewardPercentBps === 10000,
        ];
        $segments[$key]['quantity'] += $quantity;
    }

    private function progress(array $lines, array $rules, array $remaining, array $caps, array $applications): array
    {
        $result = [];
        foreach ($rules as $rule) {
            if ($caps[$rule->id] < 1) {
                continue;
            }
            $qualifying = [];
            $selectedRewards = 0;
            foreach ($lines as $line) {
                if (! $line->available || $remaining[$line->id] <= 0) {
                    continue;
                }
                if ($line->rewardOfferId === null && in_array($line->productId, $rule->qualifyingIds, true)) {
                    $key = $rule->rewardType === OfferRewardType::SameProduct ? $line->productId : 0;
                    $qualifying[$key] = ($qualifying[$key] ?? 0) + $remaining[$line->id];
                }
                if ($line->rewardOfferId === $rule->id && in_array($line->productId, $rule->rewardIds, true)) {
                    $selectedRewards += $remaining[$line->id];
                }
            }
            foreach ($qualifying as $productId => $quantity) {
                $specific = $rule->rewardType === OfferRewardType::SpecificProducts;
                $groupSize = $rule->buyQuantity + $rule->rewardQuantity;
                if ($specific) {
                    $entitled = min(intdiv($quantity, $rule->buyQuantity), $caps[$rule->id]) * $rule->rewardQuantity;
                    $available = ($rule->availableRewardIds ?? $rule->rewardIds) === [] ? 0 : max(0, $entitled - $selectedRewards);
                    $missing = $quantity < $rule->buyQuantity ? $rule->buyQuantity - $quantity : 0;
                } else {
                    if ($quantity >= $groupSize) {
                        continue;
                    }
                    $missing = $groupSize - $quantity;
                    $available = $quantity >= $rule->buyQuantity ? $missing : 0;
                }
                if ($missing === 0 && $available === 0) {
                    continue;
                }
                $result[] = [
                    ...$rule->snapshot(), 'productId' => $productId ?: null,
                    'applications' => $applications[$rule->id]['applications'] ?? 0,
                    'remainingApplications' => $caps[$rule->id] > 100 ? null : $caps[$rule->id],
                    'nextMissingQuantity' => $missing,
                    'availableRewardCount' => $available,
                ];
            }
        }

        return $result;
    }
}