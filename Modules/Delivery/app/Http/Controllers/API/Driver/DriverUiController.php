<?php

declare(strict_types=1);

namespace Modules\Delivery\Http\Controllers\API\Driver;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Delivery\Enums\DeliveryAssignmentAttemptStatus;
use Modules\Delivery\Enums\DeliveryDriverAvailabilityStatus;
use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Delivery\Http\Requests\Driver\DriverCallEventRequest;
use Modules\Delivery\Http\Requests\Driver\DriverOrderIndexRequest;
use Modules\Delivery\Http\Requests\Driver\DriverPushTokenRequest;
use Modules\Delivery\Http\Requests\Driver\DriverWalletTransactionIndexRequest;
use Modules\Delivery\Http\Resources\DeliveryDriverResource;
use Modules\Delivery\Http\Resources\DeliveryFinancialTransactionResource;
use Modules\Delivery\Http\Resources\DeliveryOrderEventResource;
use Modules\Delivery\Http\Resources\DriverUiOrderResource;
use Modules\Delivery\Models\DeliveryAssignmentAttempt;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Delivery\Services\DeliveryOrderService;
use Modules\Delivery\Services\DriverDispatchService;
use Modules\Delivery\Services\FinancialLedgerService;
use RuntimeException;

final class DriverUiController
{
    public function __construct(
        private readonly DriverDispatchService $dispatchService,
        private readonly DeliveryOrderService $deliveryOrderService,
        private readonly FinancialLedgerService $ledgerService,
    ) {}

    public function bootstrap(Request $request): JsonResponse
    {
        $driver = $this->driver($request)->load('user');
        $account = $this->ledgerService->ensureAccount($driver, 'SYP');

        return response()->json([
            'data' => [
                'driver' => DeliveryDriverResource::make($driver),
                'availability' => (string) $driver->availability_status,
                'unread_notifications' => $request->user()->unreadNotifications()->count(),
                'active_order' => $this->activeOrderResource($driver),
                'wallet' => $this->walletPayload($account),
                'config' => [
                    'reject_reasons' => $this->rejectReasonsPayload(),
                    'min_supported_version' => (string) config('delivery.mobile.min_version', '1.0.0'),
                    'latest_version' => (string) config('delivery.mobile.latest_version', '1.0.0'),
                ],
            ],
        ]);
    }

    public function indexOrders(DriverOrderIndexRequest $request): JsonResponse
    {
        $driver = $this->driver($request);
        $status = $request->validated('status');
        $perPage = (int) $request->integer('perPage', 20);

        $paginator = $this->ordersPaginator($driver, $status, $perPage);
        $mapped = collect($paginator->items())->map(fn (DeliveryOrder $order) => DriverUiOrderResource::make($order));

        return response()->json([
            'data' => $mapped,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, DeliveryOrder $order): JsonResponse
    {
        $driver = $this->driver($request);
        if (! $this->driverCanAccessOrder($driver, $order)) {
            return $this->error('FORBIDDEN', 'You are not allowed to access this order.', 403);
        }

        $this->injectOfferExpiry($driver, $order);

        return response()->json([
            'data' => DriverUiOrderResource::make($order->load('events')),
        ]);
    }

    public function timeline(Request $request, DeliveryOrder $order): JsonResponse
    {
        $driver = $this->driver($request);
        if (! $this->driverCanAccessOrder($driver, $order)) {
            return $this->error('FORBIDDEN', 'You are not allowed to access this order.', 403);
        }

        return response()->json([
            'data' => DeliveryOrderEventResource::collection(
                $order->events()->orderBy('id')->get(),
            ),
        ]);
    }

    public function offerState(Request $request, DeliveryOrder $order): JsonResponse
    {
        $driver = $this->driver($request);
        $attempt = DeliveryAssignmentAttempt::query()
            ->where('order_id', $order->id)
            ->where('driver_id', $driver->id)
            ->latest('id')
            ->first();

        $isOpen = $attempt?->status === DeliveryAssignmentAttemptStatus::Open->value
            && $attempt->expires_at !== null
            && $attempt->expires_at->isFuture();

        return response()->json([
            'data' => [
                'order_id' => $order->order_number,
                'attempt_id' => $attempt?->id,
                'is_open' => $isOpen,
                'offer_expires_at' => $attempt?->expires_at?->toIso8601String(),
                'server_time' => now()->toIso8601String(),
            ],
        ]);
    }

    public function arrivedPickup(Request $request, DeliveryOrder $order): JsonResponse
    {
        $driver = $this->driver($request);
        if ((int) $order->driver_id !== (int) $driver->id) {
            return $this->error('FORBIDDEN', 'Order is not assigned to this driver.', 403);
        }

        return DB::transaction(function () use ($driver, $order): JsonResponse {
            $locked = DeliveryOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->status === DeliveryOrderStatus::Accepted->value) {
                $locked = $this->deliveryOrderService->start($locked, (int) $driver->id);
            } elseif (! in_array($locked->status, [DeliveryOrderStatus::InProgress->value], true)) {
                return $this->error('CONFLICT', 'Invalid transition for arrived pickup.', 409);
            }

            if (! $this->hasActionEvent($locked->id, 'ARRIVED_PICKUP')) {
                $this->deliveryOrderService->recordStatusChange(
                    order: $locked,
                    from: DeliveryOrderStatus::tryFrom((string) $locked->status),
                    to: DeliveryOrderStatus::tryFrom((string) $locked->status) ?? DeliveryOrderStatus::InProgress,
                    note: 'Driver arrived at pickup point',
                    actorType: 'delivery_driver',
                    actorId: (int) $driver->id,
                    payload: ['action' => 'ARRIVED_PICKUP'],
                );
            }

            return response()->json([
                'data' => DriverUiOrderResource::make($locked->fresh()->load('events')),
            ]);
        });
    }

    public function arrivedDropoff(Request $request, DeliveryOrder $order): JsonResponse
    {
        $driver = $this->driver($request);
        if ((int) $order->driver_id !== (int) $driver->id) {
            return $this->error('FORBIDDEN', 'Order is not assigned to this driver.', 403);
        }

        return DB::transaction(function () use ($driver, $order): JsonResponse {
            $locked = DeliveryOrder::query()->lockForUpdate()->findOrFail($order->id);
            if (! in_array($locked->status, [DeliveryOrderStatus::PickedUp->value], true)) {
                return $this->error('CONFLICT', 'Invalid transition for arrived dropoff.', 409);
            }

            if (! $this->hasActionEvent($locked->id, 'ARRIVED_DROPOFF')) {
                $this->deliveryOrderService->recordStatusChange(
                    order: $locked,
                    from: DeliveryOrderStatus::PickedUp,
                    to: DeliveryOrderStatus::PickedUp,
                    note: 'Driver arrived at dropoff point',
                    actorType: 'delivery_driver',
                    actorId: (int) $driver->id,
                    payload: ['action' => 'ARRIVED_DROPOFF'],
                );
            }

            return response()->json([
                'data' => DriverUiOrderResource::make($locked->fresh()->load('events')),
            ]);
        });
    }

    public function callEvent(DriverCallEventRequest $request, DeliveryOrder $order): JsonResponse
    {
        $driver = $this->driver($request);
        if ((int) $order->driver_id !== (int) $driver->id) {
            return $this->error('FORBIDDEN', 'Order is not assigned to this driver.', 403);
        }

        $validated = $request->validated();
        $this->deliveryOrderService->recordStatusChange(
            order: $order,
            from: DeliveryOrderStatus::tryFrom((string) $order->status),
            to: DeliveryOrderStatus::tryFrom((string) $order->status) ?? DeliveryOrderStatus::InProgress,
            note: $validated['note'] ?? 'Driver call event',
            actorType: 'delivery_driver',
            actorId: (int) $driver->id,
            payload: [
                'action' => 'CALL_EVENT',
                'type' => $validated['type'],
                'target' => $validated['target'] ?? null,
                'timestamp' => isset($validated['timestamp'])
                    ? Carbon::parse((string) $validated['timestamp'])->toIso8601String()
                    : now()->toIso8601String(),
            ],
        );

        return response()->json(['data' => ['ok' => true]]);
    }

    public function walletTransactions(DriverWalletTransactionIndexRequest $request): JsonResponse
    {
        $driver = $this->driver($request);
        $account = $this->ledgerService->ensureAccount($driver, 'SYP');
        $perPage = (int) $request->integer('perPage', 20);
        $transactions = $account->transactions()->latest('id')->paginate($perPage);

        return response()->json([
            'data' => DeliveryFinancialTransactionResource::collection($transactions),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
                'last_page' => $transactions->lastPage(),
            ],
        ]);
    }

    public function walletLimits(Request $request): JsonResponse
    {
        $driver = $this->driver($request);
        $account = $this->ledgerService->ensureAccount($driver, 'SYP');

        return response()->json([
            'data' => $this->walletPayload($account),
        ]);
    }

    public function statusCounts(Request $request): JsonResponse
    {
        $driver = $this->driver($request);

        $active = DeliveryOrder::query()
            ->where('driver_id', $driver->id)
            ->whereIn('status', [
                DeliveryOrderStatus::Accepted->value,
                DeliveryOrderStatus::InProgress->value,
                DeliveryOrderStatus::PickedUp->value,
            ])
            ->count();

        $completed = DeliveryOrder::query()
            ->where('driver_id', $driver->id)
            ->whereIn('status', [DeliveryOrderStatus::Delivered->value, DeliveryOrderStatus::Completed->value])
            ->count();

        $waitingAcceptance = DeliveryAssignmentAttempt::query()
            ->where('driver_id', $driver->id)
            ->where('status', DeliveryAssignmentAttemptStatus::Open->value)
            ->where('expires_at', '>', now())
            ->count();

        $rejected = DeliveryAssignmentAttempt::query()
            ->where('driver_id', $driver->id)
            ->where('status', DeliveryAssignmentAttemptStatus::Rejected->value)
            ->count();

        return response()->json([
            'data' => [
                'WAITING_ACCEPTANCE' => $waitingAcceptance,
                'ACTIVE' => $active,
                'COMPLETED' => $completed,
                'REJECTED' => $rejected,
            ],
        ]);
    }

    public function readAllNotifications(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['data' => ['ok' => true]]);
    }

    public function unreadNotificationCount(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'count' => $request->user()->unreadNotifications()->count(),
            ],
        ]);
    }

    public function rejectReasons(): JsonResponse
    {
        return response()->json([
            'data' => $this->rejectReasonsPayload(),
        ]);
    }

    public function versionCheck(Request $request): JsonResponse
    {
        $current = (string) ($request->query('version') ?? '0.0.0');
        $min = (string) config('delivery.mobile.min_version', '1.0.0');
        $latest = (string) config('delivery.mobile.latest_version', $min);

        return response()->json([
            'data' => [
                'current' => $current,
                'min_supported' => $min,
                'latest' => $latest,
                'must_update' => version_compare($current, $min, '<'),
            ],
        ]);
    }

    public function registerPushToken(DriverPushTokenRequest $request): JsonResponse
    {
        $request->user()->forceFill([
            'fcm_token' => $request->validated('pushToken'),
        ])->saveQuietly();

        return response()->json(['data' => ['ok' => true]]);
    }

    public function unregisterPushToken(DriverPushTokenRequest $request): JsonResponse
    {
        if ($request->user()->fcm_token === $request->validated('pushToken')) {
            $request->user()->forceFill(['fcm_token' => null])->saveQuietly();
        }

        return response()->json(['data' => ['ok' => true]]);
    }

    private function activeOrderResource(DeliveryDriver $driver): ?DriverUiOrderResource
    {
        $order = $this->dispatchService->currentActiveOrderForDriver($driver);
        if ($order === null) {
            return null;
        }

        return DriverUiOrderResource::make($order->load('events'));
    }

    private function walletPayload(object $account): array
    {
        $balance = (float) data_get($account, 'current_balance', 0);
        $limit = (float) data_get($account, 'financial_limit', 0);
        $ratio = $limit > 0 ? round(($balance / $limit), 4) : 0.0;

        return [
            'current_balance' => $balance,
            'financial_limit' => $limit,
            'threshold_ratio' => $ratio,
            'warning_level' => $ratio >= 1 ? 'STOP' : ($ratio >= 0.8 ? 'NEAR_STOP' : 'NORMAL'),
            'is_suspended' => (bool) data_get($account, 'is_suspended', false),
            'suspension_reason' => data_get($account, 'suspension_reason'),
            'currency' => (string) data_get($account, 'currency', 'SYP'),
        ];
    }

    private function driver(Request $request): DeliveryDriver
    {
        /** @var DeliveryDriver $driver */
        $driver = $request->attributes->get('deliveryDriver');

        return $driver;
    }

    private function rejectReasonsPayload(): array
    {
        return [
            ['code' => 'TOO_FAR', 'label' => 'بعيد جداً عن موقعي', 'requires_text' => false],
            ['code' => 'NOT_SUITABLE', 'label' => 'الطلب غير مناسب', 'requires_text' => false],
            ['code' => 'OFF_SHIFT', 'label' => 'انتهاء وقت العمل', 'requires_text' => false],
            ['code' => 'OTHER', 'label' => 'سبب آخر', 'requires_text' => true],
        ];
    }

    private function hasActionEvent(int $orderId, string $action): bool
    {
        return DB::table('delivery_order_events')
            ->where('order_id', $orderId)
            ->where('payload->action', $action)
            ->exists();
    }

    private function injectOfferExpiry(DeliveryDriver $driver, DeliveryOrder $order): void
    {
        $attempt = DeliveryAssignmentAttempt::query()
            ->where('order_id', $order->id)
            ->where('driver_id', $driver->id)
            ->where('status', DeliveryAssignmentAttemptStatus::Open->value)
            ->latest('id')
            ->first();

        $order->setAttribute('current_offer_expires_at', $attempt?->expires_at);
    }

    private function driverCanAccessOrder(DeliveryDriver $driver, DeliveryOrder $order): bool
    {
        if ((int) $order->driver_id === (int) $driver->id) {
            return true;
        }

        return DeliveryAssignmentAttempt::query()
            ->where('order_id', $order->id)
            ->where('driver_id', $driver->id)
            ->exists();
    }

    private function ordersPaginator(DeliveryDriver $driver, ?string $status, int $perPage): LengthAwarePaginator
    {
        $page = request()->integer('page', 1);

        if ($status === 'WAITING_ACCEPTANCE' || $status === 'REJECTED') {
            $attemptStatus = $status === 'WAITING_ACCEPTANCE'
                ? DeliveryAssignmentAttemptStatus::Open->value
                : DeliveryAssignmentAttemptStatus::Rejected->value;

            $attempts = DeliveryAssignmentAttempt::query()
                ->where('driver_id', $driver->id)
                ->where('status', $attemptStatus)
                ->when(
                    $status === 'WAITING_ACCEPTANCE',
                    fn ($query) => $query->where('expires_at', '>', now()),
                )
                ->with('order.events')
                ->latest('id')
                ->get();

            $orders = $attempts->pluck('order')->filter()->unique('id')->values();

            return new LengthAwarePaginator(
                items: $orders->forPage($page, $perPage)->all(),
                total: $orders->count(),
                perPage: $perPage,
                currentPage: $page,
            );
        }

        $query = DeliveryOrder::query()
            ->where('driver_id', $driver->id)
            ->with('events')
            ->latest('updated_at');

        if ($status === 'ACTIVE') {
            $query->whereIn('status', [
                DeliveryOrderStatus::Accepted->value,
                DeliveryOrderStatus::InProgress->value,
                DeliveryOrderStatus::PickedUp->value,
            ]);
        } elseif ($status === 'COMPLETED') {
            $query->whereIn('status', [DeliveryOrderStatus::Delivered->value, DeliveryOrderStatus::Completed->value]);
        }

        return $query->paginate($perPage);
    }

    private function error(string $code, string $message, int $status, array $fields = []): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'fields' => (object) $fields,
            ],
            'request_id' => request()->header('X-Request-Id') ?? (string) str()->uuid(),
        ], $status);
    }
}

