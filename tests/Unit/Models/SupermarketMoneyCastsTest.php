<?php

declare(strict_types=1);

use Modules\Supermarket\Models\SmCartItem;
use Modules\Supermarket\Models\SmModifier;
use Modules\Supermarket\Models\SmOfferProduct;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmOrderItem;
use Modules\Supermarket\Models\SmProduct;

it('preserves decimal money precision without a database connection', function (): void {
    $moneyFields = [
        SmProduct::class => ['price', 'discounted_price'],
        SmModifier::class => ['price'],
        SmOfferProduct::class => ['offer_price'],
        SmCartItem::class => ['unit_price'],
        SmOrderItem::class => ['unit_price', 'total_price'],
        SmOrder::class => [
            'subtotal', 'discount_amount', 'service_fee', 'delivery_fee',
            'total_amount', 'cancellation_fee_amount',
        ],
    ];

    foreach ($moneyFields as $modelClass => $fields) {
        $model = new $modelClass();

        foreach ($fields as $field) {
            expect($model->getCasts()[$field])->toBe('decimal:2');
        }
    }

    // Exercise the framework decimal cast independently of model accessors
    // that may derive prices from offers or override an unavailable amount.
    $price = new class extends \Illuminate\Database\Eloquent\Model {
        protected $casts = ['amount' => 'decimal:2'];
    };
    $price->setRawAttributes(['amount' => '17.35']);
    expect($price->amount)->toBe('17.35');
    $price->setRawAttributes(['amount' => '0.00']);
    expect($price->amount)->toBe('0.00');
});
