<?php

declare(strict_types=1);

namespace Modules\User\Services;

use App\Services\DeepLinks\CanonicalDeepLinkGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Resturants\Enums\RestaurantGroupVoteStatus;
use Modules\Resturants\Models\CuisineType;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\RestaurantGroupVote;
use Modules\Resturants\Models\RestaurantGroupVoteBallot;
use Modules\Resturants\Models\RestaurantGroupVoteInvite;
use Modules\Resturants\Models\RestaurantGroupVoteOption;

final class RestaurantGroupVoteService
{
    private CanonicalDeepLinkGenerator $deepLinkGenerator;

    public function __construct(
        CanonicalDeepLinkGenerator $deepLinkGenerator,
    ) {
        $this->deepLinkGenerator = $deepLinkGenerator;
    }

    /**
     * @param  list<array{label: string, productId?: int|null}>  $options
     */
    public function create(
        int $creatorUserId,
        int $durationMinutes,
        array $options,
        ?string $foodCategoryHint = null,
        ?int $cuisineTypeId = null,
    ): RestaurantGroupVote {
        return DB::transaction(function () use ($creatorUserId, $durationMinutes, $options, $foodCategoryHint, $cuisineTypeId) {
            $vote = RestaurantGroupVote::create([
                'user_id' => $creatorUserId,
                'cuisine_type_id' => $cuisineTypeId,
                'food_category_hint' => $foodCategoryHint,
                'duration_minutes' => $durationMinutes,
                'ends_at' => now()->addMinutes($durationMinutes),
                'status' => RestaurantGroupVoteStatus::Active,
            ]);

            foreach (array_values($options) as $index => $row) {
                RestaurantGroupVoteOption::create([
                    'vote_id' => $vote->id,
                    'label' => $row['label'],
                    'product_id' => $row['productId'] ?? null,
                    'sort_order' => $index,
                ]);
            }

            return $vote->load(['options', 'cuisineType']);
        });
    }

    public function castBallot(RestaurantGroupVote $vote, int $userId, int $optionId): RestaurantGroupVoteBallot
    {
        $this->finalizeIfExpired($vote);
        $vote->refresh();

        if ($vote->status !== RestaurantGroupVoteStatus::Active) {
            throw ValidationException::withMessages([
                'vote' => ['This vote is no longer active.'],
            ]);
        }

        if (now()->greaterThan($vote->ends_at)) {
            throw ValidationException::withMessages([
                'vote' => ['Voting time has ended.'],
            ]);
        }

        $option = RestaurantGroupVoteOption::query()
            ->where('vote_id', $vote->id)
            ->where('id', $optionId)
            ->first();

        if (! $option) {
            throw ValidationException::withMessages([
                'optionId' => ['Invalid option for this vote.'],
            ]);
        }

        /** @var RestaurantGroupVoteBallot $ballot */
        $ballot = RestaurantGroupVoteBallot::query()->updateOrCreate(
            [
                'vote_id' => $vote->id,
                'user_id' => $userId,
            ],
            [
                'option_id' => $optionId,
            ]
        );

        return $ballot;
    }

    public function endNow(RestaurantGroupVote $vote, int $actorUserId): void
    {
        if ($vote->user_id !== $actorUserId) {
            throw ValidationException::withMessages([
                'vote' => ['Only the vote creator can end it early.'],
            ]);
        }

        if ($vote->status !== RestaurantGroupVoteStatus::Active) {
            throw ValidationException::withMessages([
                'vote' => ['This vote is already closed.'],
            ]);
        }

        DB::transaction(function () use ($vote): void {
            $vote->update([
                'ends_at' => now(),
            ]);
            $this->applyWinner($vote);
        });
    }

    /**
     * @param  list<int>  $userIds
     * @return list<int>
     */
    public function inviteUsers(RestaurantGroupVote $vote, int $actorUserId, array $userIds): array
    {
        $this->finalizeIfExpired($vote);
        $vote->refresh();

        if ($vote->user_id !== $actorUserId) {
            throw ValidationException::withMessages([
                'vote' => ['Only the vote creator can invite users.'],
            ]);
        }

        if ($vote->status !== RestaurantGroupVoteStatus::Active) {
            throw ValidationException::withMessages([
                'vote' => ['This vote is no longer active.'],
            ]);
        }

        $filteredUserIds = collect($userIds)
            ->map(fn(int $id): int => (int) $id)
            ->unique()
            ->reject(fn(int $id): bool => $id === $vote->user_id)
            ->values()
            ->all();

        foreach ($filteredUserIds as $userId) {
            RestaurantGroupVoteInvite::query()->firstOrCreate([
                'vote_id' => $vote->id,
                'user_id' => $userId,
            ]);
        }

        return $filteredUserIds;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function activeVotesForUser(int $userId): array
    {
        $votes = RestaurantGroupVote::query()
            ->where('status', RestaurantGroupVoteStatus::Active)
            ->where(function ($query) use ($userId): void {
                $query->where('user_id', $userId)
                    ->orWhereHas('invites', fn($inviteQuery) => $inviteQuery->where('user_id', $userId));
            })
            ->orderBy('ends_at')
            ->get();

        $activePayloads = [];

        foreach ($votes as $vote) {
            $this->finalizeIfExpired($vote);
            $vote->refresh();

            if ($vote->status !== RestaurantGroupVoteStatus::Active) {
                continue;
            }

            $activePayloads[] = $this->publicPayload($vote, $userId);
        }

        return $activePayloads;
    }

    public function finalizeIfExpired(RestaurantGroupVote $vote): void
    {
        if ($vote->status !== RestaurantGroupVoteStatus::Active) {
            return;
        }

        if (now()->lessThanOrEqualTo($vote->ends_at)) {
            return;
        }

        DB::transaction(function () use ($vote): void {
            $this->applyWinner($vote);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function publicPayload(RestaurantGroupVote $vote, ?int $currentUserId): array
    {
        $this->finalizeIfExpired($vote);
        $vote->refresh();
        $vote->load(['options.product', 'ballots.user', 'ballots.option', 'cuisineType', 'winningOption', 'invites.user']);

        $totalBallots = $vote->ballots->count();
        $countsByOption = $vote->ballots->groupBy('option_id')->map->count();

        $optionsPayload = $vote->options->map(function (RestaurantGroupVoteOption $option) use ($countsByOption, $totalBallots): array {
            $count = (int) ($countsByOption->get($option->id) ?? 0);
            $percent = $totalBallots > 0 ? round(($count / $totalBallots) * 100, 1) : 0.0;

            $price = null;
            if ($option->relationLoaded('product') && $option->product) {
                $d = $option->product->discounted_price;
                $price = (float) (($d !== null && (float) $d > 0) ? $d : $option->product->price);
            }

            return [
                'id' => $option->id,
                'label' => $option->label,
                'productId' => $option->product_id,
                'voteCount' => $count,
                'percent' => $percent,
                'unitPrice' => $price,
            ];
        })->values()->all();

        $votersPayload = $vote->ballots->sortBy('id')->values()->map(function (RestaurantGroupVoteBallot $ballot): array {
            return [
                'userId' => $ballot->user_id,
                'name' => $ballot->user->name,
                'optionId' => $ballot->option_id,
                'optionLabel' => $ballot->option->label,
            ];
        })->all();

        $winnerPayload = null;
        if ($vote->status === RestaurantGroupVoteStatus::Ended && $vote->winning_option_id) {
            $win = $vote->winningOption;
            if ($win) {
                $winnerPayload = [
                    'optionId' => $win->id,
                    'label' => $win->label,
                    'productId' => $win->product_id,
                ];
            }
        }

        $invitedUsersPayload = $vote->invites->sortBy('id')->values()->map(function (RestaurantGroupVoteInvite $invite): array {
            return [
                'userId' => $invite->user_id,
                'name' => $invite->user->name,
            ];
        })->all();

        $isInvited = $currentUserId !== null
            ? $vote->invites->contains(fn(RestaurantGroupVoteInvite $invite): bool => $invite->user_id === $currentUserId)
            : false;

        $secondsRemaining = 0;
        if ($vote->status === RestaurantGroupVoteStatus::Active) {
            $secondsRemaining = max(0, (int) ($vote->ends_at->getTimestamp() - now()->getTimestamp()));
        }

        return [
            'vote' => [
                'id' => $vote->id,
                'shareUrl' => $this->deepLinkGenerator->vote((int) $vote->id),
                'status' => $vote->status->value,
                'foodCategoryHint' => $vote->food_category_hint,
                'cuisineTypeId' => $vote->cuisine_type_id,
                'cuisineType' => $vote->cuisineType ? [
                    'id' => $vote->cuisineType->id,
                    'name' => $vote->cuisineType->name,
                    'slug' => $vote->cuisineType->slug,
                ] : null,
                'durationMinutes' => $vote->duration_minutes,
                'endsAt' => $vote->ends_at->toIso8601String(),
                'secondsRemaining' => $secondsRemaining,
                'creatorUserId' => $vote->user_id,
                'isCreator' => $currentUserId !== null && $currentUserId === $vote->user_id,
                'isInvited' => $isInvited,
                'createdAt' => $vote->created_at->toIso8601String(),
            ],
            'options' => $optionsPayload,
            'voters' => $votersPayload,
            'invitedUsers' => $invitedUsersPayload,
            'winner' => $winnerPayload,
        ];
    }

    /**
     * @return array{
     *     durationMinutesPresets: list<int>,
     *     cuisineTypes: list<array{id:int,name:string,slug:string}>,
     *     suggestions: list<array{id:int,name:string,unitPrice:float,restaurantId:int,restaurantName:string}>
     * }
     */
    public function suggestionsCatalog(?string $search, ?int $cuisineTypeId, int $limit = 20): array
    {
        $durationMinutesPresets = [15, 30, 45, 60, 90, 120];

        $cuisineTypes = CuisineType::query()
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name', 'slug'])
            ->map(fn(CuisineType $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'slug' => (string) $row->slug,
            ])
            ->values()
            ->all();

        $term = is_string($search) ? mb_trim($search) : '';
        $escaped = $term !== '' ? addcslashes($term, '%_\\') : null;

        $products = Product::query()
            ->where('is_available', true)
            ->whereHas('restaurant', function ($q): void {
                $q->where('is_active', true)
                    ->where('is_temporarily_closed', false)
                    ->where(fn($qq) => $qq->whereNull('suspension_until')->orWhere('suspension_until', '<=', now()));
            })
            ->when($cuisineTypeId !== null, fn($q) => $q->whereHas(
                'restaurant.cuisineTypes',
                fn($q) => $q->where('cuisine_types.id', $cuisineTypeId)
            ))
            ->when($escaped !== null, fn($q) => $q->where(
                fn($q) => $q
                    ->where('name', 'like', '%' . $escaped . '%')
                    ->orWhere('description', 'like', '%' . $escaped . '%')
            ))
            ->with(['restaurant:id,name'])
            ->orderByDesc('is_featured')
            ->orderBy('name')
            ->limit($limit)
            ->get();

        $suggestions = $products->map(function (Product $product): array {
            $d = $product->discounted_price;
            $unit = (float) (($d !== null && (float) $d > 0) ? $d : $product->price);

            return [
                'id' => (int) $product->id,
                'name' => (string) $product->name,
                'unitPrice' => round($unit, 2),
                'restaurantId' => (int) $product->restaurant_id,
                'restaurantName' => (string) ($product->restaurant->name ?? ''),
            ];
        })->values()->all();

        return [
            'durationMinutesPresets' => $durationMinutesPresets,
            'cuisineTypes' => $cuisineTypes,
            'suggestions' => $suggestions,
        ];
    }

    private function applyWinner(RestaurantGroupVote $vote): void
    {
        $vote->load('ballots');

        $counts = $vote->ballots->groupBy('option_id')->map->count();

        if ($counts->isEmpty()) {
            $vote->update([
                'status' => RestaurantGroupVoteStatus::Ended,
                'winning_option_id' => null,
            ]);

            return;
        }

        $max = $counts->max();
        $winnerId = $counts
            ->filter(fn(int $c): bool => $c === $max)
            ->keys()
            ->map(fn($id) => (int) $id)
            ->sort()
            ->first();

        $vote->update([
            'status' => RestaurantGroupVoteStatus::Ended,
            'winning_option_id' => $winnerId,
        ]);
    }
}
