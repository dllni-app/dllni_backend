<?php

declare(strict_types=1);

namespace Modules\Resturants\Services\Promotions;

use App\Services\Promotions\PromotionEngine;
use DateTimeInterface;

/** Keeps the restaurant constructor, result types and pricing fingerprints stable. */
final class RestaurantPromotionEngine
{
    public const VERSION = PromotionEngine::VERSION;

    private readonly PromotionEngine $engine;

    public function __construct(int $candidateLimit = 512, int $generationBudget = 50000, int $searchBudget = 50000)
    {
        $this->engine = new PromotionEngine($candidateLimit, $generationBudget, $searchBudget);
    }

    public function price(int $restaurantId, array $cartLines, array $rules, DateTimeInterface $at): PromotionPricingResult
    {
        $result = $this->engine->price($restaurantId, $cartLines, $rules, $at);

        return new PromotionPricingResult(...get_object_vars($result));
    }
}
