<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Resturants\Models\Order;
use Modules\Supermarket\Models\SmOrder;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Persist the placement outcome in the same transaction as the merchant order.
 * A retry with the same user/section/key returns the first order after the cart
 * has been consumed; a different payload must never reuse the old receipt.
 */
final class CommerceCheckoutReceiptService
{
    /**
     * @param  Closure(): Model  $place
     * @return array{0: Model, 1: bool} [order, newly created]
     */
    public function execute(
        int $userId,
        string $section,
        int $cartId,
        string $idempotencyKey,
        array $payload,
        Closure $place,
    ): array {
        if (! in_array($section, ['restaurant', 'supermarket'], true)) {
            throw new InvalidArgumentException('Unsupported commerce section');
        }

        if (! preg_match('/^[a-zA-Z0-9_-]{16,80}$/D', $idempotencyKey)) {
            throw ValidationException::withMessages(['idempotencyKey' => ['Idempotency-Key must be 16–80 alphanumeric, dash or underscore characters.']]);
        }

        $fingerprint = hash('sha256', json_encode(['cartId' => $cartId, 'payload' => $payload], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $now = now();

        DB::table('commerce_checkout_receipts')->insertOrIgnore([
            'user_id' => $userId,
            'section' => $section,
            'cart_id' => $cartId,
            'idempotency_key' => $idempotencyKey,
            'payload_fingerprint' => $fingerprint,
            'status' => 'processing',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $receipt = DB::table('commerce_checkout_receipts')
            ->where('user_id', $userId)
            ->where('section', $section)
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();

        if (! $receipt || (int) $receipt->cart_id !== $cartId || ! hash_equals((string) $receipt->payload_fingerprint, $fingerprint)) {
            throw new ConflictHttpException('Idempotency key already belongs to a different checkout payload.');
        }

        if ($receipt->order_id !== null && $receipt->status === 'completed') {
            $model = $section === 'restaurant' ? Order::class : SmOrder::class;
            $order = $model::query()->findOrFail((int) $receipt->order_id);

            return [$order, false];
        }

        if ($receipt->status !== 'processing') {
            throw new ConflictHttpException('Checkout receipt has an unknown status.');
        }

        $order = $place();
        DB::table('commerce_checkout_receipts')->where('id', $receipt->id)->update([
            'order_id' => $order->getKey(),
            'status' => 'completed',
            'updated_at' => now(),
        ]);

        return [$order, true];
    }

    public function lookup(int $userId, string $key, ?string $section = null): array
    {
        $query = DB::table('commerce_checkout_receipts')
            ->where('user_id', $userId)
            ->where('idempotency_key', $key);
        if ($section !== null) {
            $query->where('section', $section);
        }
        $receipts = $query->orderByDesc('id')
            ->get(['section', 'status', 'order_id', 'created_at']);

        // The same key can exist in independent sections. Don't guess which one.
        if ($receipts->count() > 1) {
            throw new ConflictHttpException('Specify the commerce section to resolve this submission.');
        }

        $receipt = $receipts->first();

        return $receipt === null
            ? ['status' => 'not_found', 'orderId' => null, 'section' => null]
            : ['status' => $receipt->status, 'orderId' => $receipt->order_id, 'section' => $receipt->section];
    }
}
