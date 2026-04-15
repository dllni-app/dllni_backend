<?php

declare(strict_types=1);

namespace Modules\User\Services;

use App\Models\MasterProduct;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Models\SmSmartList;
use Modules\Supermarket\Models\SmSmartListItem;
use Modules\Supermarket\Models\SmSmartListSchedule;

final class UserSupermarketShoppingListService
{
    public function __construct(
        private readonly UserSupermarketCartService $carts,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function index(int $userId): array
    {
        return SmSmartList::query()
            ->where('user_id', $userId)
            ->with('schedule')
            ->withCount('items')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (SmSmartList $list): array => $this->listSummary($list))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function show(int $userId, int $listId): array
    {
        $list = $this->findOwnedList($userId, $listId);

        return $this->listDetail($list->load([
            'schedule',
            'items' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            'items.masterProduct',
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function store(
        int $userId,
        string $name,
        ?string $description,
        bool $isActive,
        ?array $schedule = null,
    ): array {
        $list = SmSmartList::create([
            'user_id' => $userId,
            'name' => $name,
            'description' => $description,
            'is_active' => $isActive,
        ]);

        $this->upsertSchedule($list, $schedule);

        return $this->listDetail($list->fresh()->load([
            'schedule',
            'items' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            'items.masterProduct',
        ]));
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function updateList(int $userId, int $listId, array $validated): array
    {
        $list = $this->findOwnedList($userId, $listId);
        $data = [];

        if (array_key_exists('name', $validated)) {
            $data['name'] = $validated['name'];
        }

        if (array_key_exists('description', $validated)) {
            $data['description'] = $validated['description'];
        }

        if (array_key_exists('isActive', $validated)) {
            $data['is_active'] = (bool) $validated['isActive'];
        }

        if ($data !== []) {
            $list->update($data);
        }

        if (array_key_exists('schedule', $validated)) {
            $this->upsertSchedule($list->refresh(), is_array($validated['schedule']) ? $validated['schedule'] : null);
        }

        return $this->listDetail($list->fresh()->load([
            'schedule',
            'items' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            'items.masterProduct',
        ]));
    }

    public function destroy(int $userId, int $listId): void
    {
        $list = $this->findOwnedList($userId, $listId);
        $list->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function storeItem(
        int $userId,
        int $listId,
        int $masterProductId,
        float $quantity,
        ?string $unit,
        int $sortOrder,
        bool $isIncluded,
    ): array {
        $list = $this->findOwnedList($userId, $listId);
        MasterProduct::query()->where('id', $masterProductId)->firstOrFail();

        SmSmartListItem::create([
            'smart_list_id' => $list->id,
            'master_product_id' => $masterProductId,
            'quantity' => $quantity,
            'unit' => $unit,
            'sort_order' => $sortOrder,
            'is_included' => $isIncluded,
        ]);

        return $this->listDetail($list->fresh()->load([
            'schedule',
            'items' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            'items.masterProduct',
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function updateItem(
        int $userId,
        int $listId,
        int $itemId,
        ?float $quantity,
        ?int $sortOrder,
        ?bool $isIncluded,
    ): array {
        $list = $this->findOwnedList($userId, $listId);
        $item = SmSmartListItem::query()
            ->where('id', $itemId)
            ->where('smart_list_id', $list->id)
            ->firstOrFail();

        $data = [];

        if ($quantity !== null) {
            $data['quantity'] = $quantity;
        }

        if ($sortOrder !== null) {
            $data['sort_order'] = $sortOrder;
        }

        if ($isIncluded !== null) {
            $data['is_included'] = $isIncluded;
        }

        if ($data !== []) {
            $item->update($data);
        }

        return $this->listDetail($list->fresh()->load([
            'schedule',
            'items' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            'items.masterProduct',
        ]));
    }

    public function destroyItem(int $userId, int $listId, int $itemId): void
    {
        $list = $this->findOwnedList($userId, $listId);
        SmSmartListItem::query()
            ->where('id', $itemId)
            ->where('smart_list_id', $list->id)
            ->firstOrFail()
            ->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function addListToCart(int $userId, int $listId): array
    {
        return DB::transaction(function () use ($userId, $listId): array {
            $list = $this->findOwnedList($userId, $listId);
            $list->load([
                'items' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            ]);

            $includedItems = $list->items
                ->filter(fn (SmSmartListItem $item): bool => (bool) $item->is_included)
                ->values();

            if ($includedItems->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => ['There are no included items to add to the cart.'],
                ]);
            }

            $resolvedItems = $includedItems->map(function (SmSmartListItem $row): array {
                $products = SmProduct::query()
                    ->where('master_product_id', $row->master_product_id)
                    ->where('is_available', true)
                    ->orderBy('id')
                    ->get(['id', 'store_id']);

                if ($products->isEmpty()) {
                    throw ValidationException::withMessages([
                        'items' => ["No available product found for master product id {$row->master_product_id}."],
                    ]);
                }

                return [
                    'row' => $row,
                    'products' => $products,
                ];
            });

            $candidateStoreIds = null;

            foreach ($resolvedItems as $itemOptions) {
                $storeIds = $itemOptions['products']->pluck('store_id')->unique()->values()->all();
                $candidateStoreIds = $candidateStoreIds === null
                    ? $storeIds
                    : array_values(array_intersect($candidateStoreIds, $storeIds));
            }

            if ($candidateStoreIds === [] || $candidateStoreIds === null) {
                throw ValidationException::withMessages([
                    'items' => ['The included items do not share a common store.'],
                ]);
            }

            $effectiveStoreId = (int) $candidateStoreIds[0];
            $lines = [];

            foreach ($resolvedItems as $itemOptions) {
                $row = $itemOptions['row'];
                $product = $itemOptions['products']->firstWhere('store_id', $effectiveStoreId);

                if ($product === null) {
                    throw ValidationException::withMessages([
                        'items' => [
                            "No available product found in the selected store for master product id {$row->master_product_id}.",
                        ],
                    ]);
                }

                $lines[] = [
                    'productId' => $product->id,
                    'quantity' => max(1, (int) round((float) $row->quantity)),
                ];
            }

            if ($lines === []) {
                throw ValidationException::withMessages([
                    'items' => ['There are no included items to add to the cart.'],
                ]);
            }

            return $this->carts->addLinesForStore($userId, $effectiveStoreId, $lines);
        });
    }

    private function findOwnedList(int $userId, int $listId): SmSmartList
    {
        return SmSmartList::query()
            ->where('id', $listId)
            ->where('user_id', $userId)
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function listSummary(SmSmartList $list): array
    {
        return [
            'id' => $list->id,
            'name' => $list->name,
            'description' => $list->description,
            'isActive' => (bool) $list->is_active,
            'schedule' => $this->schedulePayload($list),
            'itemsCount' => (int) ($list->items_count ?? $list->items()->count()),
            'createdAt' => $list->created_at?->toDateTimeString(),
            'updatedAt' => $list->updated_at?->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function listDetail(SmSmartList $list): array
    {
        $items = $list->relationLoaded('items')
            ? $list->items
            : $list->items()->orderBy('sort_order')->orderBy('id')->with('masterProduct')->get();

        return [
            'id' => $list->id,
            'name' => $list->name,
            'description' => $list->description,
            'isActive' => (bool) $list->is_active,
            'schedule' => $this->schedulePayload($list),
            'items' => $items->map(fn (SmSmartListItem $item): array => $this->itemPayload($item))->values()->all(),
            'createdAt' => $list->created_at?->toDateTimeString(),
            'updatedAt' => $list->updated_at?->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function schedulePayload(SmSmartList $list): ?array
    {
        /** @var SmSmartListSchedule|null $schedule */
        $schedule = $list->relationLoaded('schedule')
            ? $list->schedule
            : $list->schedule()->first();

        if ($schedule === null) {
            return null;
        }

        return [
            'frequencyType' => $schedule->frequency_type,
            'weekDays' => $schedule->week_days,
            'monthDays' => $schedule->month_days,
            'periods' => $schedule->periods,
            'isActive' => (bool) $schedule->is_active,
            'nextRunAt' => $schedule->next_run_at?->toDateTimeString(),
            'lastRunAt' => $schedule->last_run_at?->toDateTimeString(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $schedule
     */
    private function upsertSchedule(SmSmartList $list, ?array $schedule): void
    {
        if ($schedule === null) {
            return;
        }

        $frequencyType = $schedule['frequency_type'] ?? $schedule['frequencyType'] ?? null;
        $isActive = (bool) ($schedule['is_active'] ?? $schedule['isActive'] ?? true);
        $weekDays = $this->normalizeIntegerList($schedule['week_days'] ?? $schedule['weekDays'] ?? null);
        $monthDays = $this->normalizeIntegerList($schedule['month_days'] ?? $schedule['monthDays'] ?? null);
        $periods = $this->normalizePeriods($schedule['periods'] ?? null);

        if ($frequencyType === 'weekly' && $weekDays === []) {
            throw ValidationException::withMessages([
                'schedule.weekDays' => ['At least one weekday must be selected for weekly schedules.'],
            ]);
        }

        if ($frequencyType === 'monthly' && $monthDays === []) {
            throw ValidationException::withMessages([
                'schedule.monthDays' => ['At least one month day must be selected for monthly schedules.'],
            ]);
        }

        if ($periods === []) {
            throw ValidationException::withMessages([
                'schedule.periods' => ['At least one time period is required.'],
            ]);
        }

        $nextRunAt = $isActive
            ? $this->calculateNextRunAt($frequencyType, $weekDays, $monthDays, $periods)
            : null;

        SmSmartListSchedule::query()->updateOrCreate(
            ['smart_list_id' => $list->id],
            [
                'frequency_type' => $frequencyType,
                'week_days' => $weekDays,
                'month_days' => $monthDays,
                'periods' => $periods,
                'is_active' => $isActive,
                'next_run_at' => $nextRunAt,
            ]
        );
    }

    /**
     * @param  array<int, mixed>|int|string|null  $value
     * @return array<int, int>
     */
    private function normalizeIntegerList(array|int|string|null $value): array
    {
        if ($value === null) {
            return [];
        }

        $items = is_array($value) ? $value : [$value];

        return array_values(array_unique(array_map(static fn (mixed $item): int => (int) $item, $items)));
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $periods
     * @return array<int, array{label?: string|null, fromTime: string, toTime: string}>
     */
    private function normalizePeriods(?array $periods): array
    {
        if ($periods === null) {
            return [];
        }

        return array_values(array_map(
            static function (array $period): array {
                return [
                    'label' => isset($period['label']) ? (string) $period['label'] : null,
                    'fromTime' => (string) ($period['fromTime'] ?? $period['from_time'] ?? ''),
                    'toTime' => (string) ($period['toTime'] ?? $period['to_time'] ?? ''),
                ];
            },
            $periods
        ));
    }

    /**
     * @param  array<int, array{label?: string|null, fromTime: string, toTime: string}>  $periods
     */
    private function calculateNextRunAt(?string $frequencyType, array $weekDays, array $monthDays, array $periods): ?Carbon
    {
        if ($frequencyType === null) {
            return null;
        }

        $now = now();
        $startTime = $this->earliestPeriodStartTime($periods);

        if ($startTime === null) {
            return null;
        }

        if ($frequencyType === 'weekly' && $weekDays !== []) {
            return $this->nextWeeklyRunAt($weekDays, $startTime, $now);
        }

        if ($frequencyType === 'monthly' && $monthDays !== []) {
            return $this->nextMonthlyRunAt($monthDays, $startTime, $now);
        }

        return null;
    }

    /**
     * @param  array<int, array{label?: string|null, fromTime: string, toTime: string}>  $periods
     */
    private function earliestPeriodStartTime(array $periods): ?string
    {
        $filtered = array_filter($periods, static fn (array $period): bool => $period['fromTime'] !== '');

        if ($filtered === []) {
            return null;
        }

        usort($filtered, static fn (array $left, array $right): int => strcmp($left['fromTime'], $right['fromTime']));

        return $filtered[0]['fromTime'];
    }

    /**
     * @param  array<int, int>  $weekDays
     */
    private function nextWeeklyRunAt(array $weekDays, string $startTime, CarbonInterface $now): ?Carbon
    {
        $weekDays = array_values(array_unique(array_map(static fn (int $day): int => max(0, min(6, $day)), $weekDays)));

        for ($offset = 0; $offset <= 14; $offset++) {
            $candidateDate = $now->copy()->startOfDay()->addDays($offset);

            if (! in_array($candidateDate->dayOfWeek, $weekDays, true)) {
                continue;
            }

            $candidate = Carbon::parse($candidateDate->toDateString().' '.$startTime);

            if ($candidate->gt($now)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<int, int>  $monthDays
     */
    private function nextMonthlyRunAt(array $monthDays, string $startTime, CarbonInterface $now): ?Carbon
    {
        $monthDays = array_values(array_unique(array_map(static fn (int $day): int => max(1, min(31, $day)), $monthDays)));

        for ($monthOffset = 0; $monthOffset <= 12; $monthOffset++) {
            $month = $now->copy()->startOfMonth()->addMonthsNoOverflow($monthOffset);
            $lastDay = (int) $month->copy()->endOfMonth()->day;

            foreach ($monthDays as $day) {
                $candidateDay = min($day, $lastDay);
                $candidate = Carbon::parse($month->copy()->day($candidateDay)->toDateString().' '.$startTime);

                if ($candidate->gt($now)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function itemPayload(SmSmartListItem $item): array
    {
        return [
            'id' => $item->id,
            'masterProductId' => $item->master_product_id,
            'name' => $item->masterProduct?->name,
            'quantity' => (float) $item->quantity,
            'unit' => $item->unit,
            'sortOrder' => (int) $item->sort_order,
            'isIncluded' => (bool) ($item->is_included ?? true),
            'createdAt' => $item->created_at?->toDateTimeString(),
            'updatedAt' => $item->updated_at?->toDateTimeString(),
        ];
    }
}
