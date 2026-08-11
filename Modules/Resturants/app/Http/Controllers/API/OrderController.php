<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Controllers\API;

use App\Enums\UserModuleType;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Resturants\Data\OrderData;
use Modules\Resturants\Http\Requests\OrderRequest;
use Modules\Resturants\Http\Requests\OrderRequests\OrderFilterRequest;
use Modules\Resturants\Http\Resources\OrderResource;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Services\OrderService;
use Throwable;

final class OrderController
{
    public function __construct(
        private OrderService $orderService
    ) {}

    public function index(OrderFilterRequest $request): AnonymousResourceCollection
    {
        $filters = $request->input('filter', []);
        $sort = $request->input('sort', '-created_at');
        $sortDirection = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $sortColumn = ltrim($sort, '-');

        $orders = Order::query()
            ->when(
                $this->authenticatedRestaurantSellerRestaurantId(),
                fn ($query, int $restaurantId) => $query->where('restaurant_id', $restaurantId),
                fn ($query) => $query->when($filters['restaurantId'] ?? null, fn ($query, $restaurantId) => $query->where('restaurant_id', $restaurantId))
            )
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['orderType'] ?? null, fn ($query, $orderType) => $query->where('order_type', $orderType))
            ->when($filters['pickupMode'] ?? null, fn ($query, $pickupMode) => $query->where('pickup_mode', $pickupMode))
            ->when($filters['dateFrom'] ?? null, fn ($query, $dateFrom) => $query->whereDate('created_at', '>=', $dateFrom))
            ->when($filters['dateTo'] ?? null, fn ($query, $dateTo) => $query->whereDate('created_at', '<=', $dateTo))
            ->when($request->boolean('filter.createdToday'), fn ($query) => $query->whereDate('created_at', today()))
            ->when($request->boolean('filter.hasDispute'), fn ($query) => $query->whereHas('disputes'))
            ->when($request->boolean('filter.late'), fn ($query) => $query->whereNotNull('pickup_scheduled_for')->where('pickup_scheduled_for', '<', now()))
            ->with(['user', 'restaurant', 'orderItems.product', 'orderStatusLogs', 'promoCode', 'assignedStaff', 'disputes'])
            ->orderBy($sortColumn, $sortDirection)
            ->paginate($request->integer('perPage', 20));

        return OrderResource::collection($orders);
    }

    /** @throws Throwable */
    public function store(OrderRequest $request): OrderResource
    {
        $order = $this->orderService->store(
            OrderData::from($request->validated())
        );

        return OrderResource::make(
            $order->load(['user', 'restaurant', 'orderItems.product', 'orderStatusLogs', 'promoCode', 'assignedStaff', 'disputes'])
        );
    }

    public function show(Order $order): OrderResource
    {
        $this->abortIfRestaurantSellerCannotAccess($order);

        $order->load([
            'user', 'restaurant', 'orderItems.product', 'orderStatusLogs', 'promoCode', 'assignedStaff', 'disputes',
        ]);

        return OrderResource::make($order);
    }

    /** @throws Throwable */
    public function update(OrderRequest $request, Order $order): OrderResource
    {
        $this->abortIfRestaurantSellerCannotAccess($order);

        $updated = $this->orderService->update(
            OrderData::from($request->validated()),
            $order
        );

        return OrderResource::make(
            $updated->load(['user', 'restaurant', 'orderItems.product', 'orderStatusLogs', 'promoCode', 'assignedStaff', 'disputes'])
        );
    }

    public function destroy(Order $order): Response
    {
        $this->abortIfRestaurantSellerCannotAccess($order);

        $order->delete();

        return response()->noContent();
    }

    private function authenticatedRestaurantSellerRestaurantId(): ?int
    {
        $user = auth()->user();

        if (! $user || $user->module_type !== UserModuleType::RestaurantSeller) {
            return null;
        }

        $restaurant = $user->restaurants()->select('id')->first();

        abort_if(! $restaurant, 403, 'No restaurant found for this owner.');

        return (int) $restaurant->id;
    }

    private function abortIfRestaurantSellerCannotAccess(Order $order): void
    {
        $restaurantId = $this->authenticatedRestaurantSellerRestaurantId();

        if ($restaurantId === null) {
            return;
        }

        abort_if((int) $order->restaurant_id !== $restaurantId, 403, 'You do not have access to this order.');
    }
}
