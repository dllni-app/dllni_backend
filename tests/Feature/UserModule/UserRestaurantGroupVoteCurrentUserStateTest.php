<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Modules\Resturants\Models\RestaurantGroupVote;
use Modules\User\Services\RestaurantGroupVoteService;

function createVoteWithTwoOptionsForCurrentUserStateTest(User $creator): RestaurantGroupVote
{
    $restaurant = Restaurant::factory()->create(['is_active' => true]);
    $p1 = Product::factory()->create(['restaurant_id' => $restaurant->id, 'is_available' => true]);
    $p2 = Product::factory()->create(['restaurant_id' => $restaurant->id, 'is_available' => true]);

    return app(RestaurantGroupVoteService::class)->create(
        creatorUserId: (int) $creator->id,
        durationMinutes: 30,
        options: [
            ['label' => 'A', 'productId' => $p1->id],
            ['label' => 'B', 'productId' => $p2->id],
        ],
    );
}

it('returns authenticated current user state on the public vote show endpoint', function (): void {
    $creator = User::factory()->create();
    $vote = createVoteWithTwoOptionsForCurrentUserStateTest($creator);

    Sanctum::actingAs($creator);

    $this->getJson('/api/v1/user/restaurants/votes/'.$vote->id)
        ->assertSuccessful()
        ->assertJsonPath('data.vote.isCreator', true)
        ->assertJsonPath('data.vote.creator.id', $creator->id)
        ->assertJsonPath('data.vote.creator.isCurrentUser', true)
        ->assertJsonPath('data.vote.currentUserOptionId', null)
        ->assertJsonPath('data.vote.selectedOptionId', 0)
        ->assertJsonPath('data.vote.myVotedOptionId', 0)
        ->assertJsonPath('data.vote.userVoteOptionId', 0)
        ->assertJsonPath('data.vote.hasCurrentUserVoted', false)
        ->assertJsonPath('data.vote.currentUserVote.hasVoted', false)
        ->assertJsonPath('data.vote.currentUserVote.optionId', null);
});

it('returns creator data and selected option for the bearer token user on vote show', function (): void {
    $creator = User::factory()->create(['name' => 'Vote Creator']);
    $vote = createVoteWithTwoOptionsForCurrentUserStateTest($creator);
    $optionId = (int) $vote->options->first()->id;

    app(RestaurantGroupVoteService::class)->castBallot($vote, (int) $creator->id, $optionId);

    $token = $creator->createToken('mobile')->plainTextToken;

    $response = $this
        ->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/user/restaurants/votes/'.$vote->id);

    $response
        ->assertSuccessful()
        ->assertJsonPath('data.vote.isCreator', true)
        ->assertJsonPath('data.vote.creator.id', $creator->id)
        ->assertJsonPath('data.vote.creator.name', 'Vote Creator')
        ->assertJsonPath('data.vote.creator.isCurrentUser', true)
        ->assertJsonPath('data.vote.currentUserOptionId', $optionId)
        ->assertJsonPath('data.vote.selectedOptionId', $optionId)
        ->assertJsonPath('data.vote.myVotedOptionId', $optionId)
        ->assertJsonPath('data.vote.userVoteOptionId', $optionId)
        ->assertJsonPath('data.vote.hasCurrentUserVoted', true)
        ->assertJsonPath('data.vote.currentUserVote.hasVoted', true)
        ->assertJsonPath('data.vote.currentUserVote.optionId', $optionId)
        ->assertJsonPath('data.vote.currentUserVote.optionLabel', 'A');

    $selectedOption = collect($response->json('data.options'))->firstWhere('id', $optionId);
    expect($selectedOption['isSelectedByCurrentUser'])->toBeTrue();
});

it('returns creator data without personalized selection for anonymous vote show', function (): void {
    $creator = User::factory()->create(['name' => 'Anonymous Creator']);
    $vote = createVoteWithTwoOptionsForCurrentUserStateTest($creator);
    $optionId = (int) $vote->options->first()->id;

    app(RestaurantGroupVoteService::class)->castBallot($vote, (int) $creator->id, $optionId);

    $response = $this->getJson('/api/v1/user/restaurants/votes/'.$vote->id);

    $response
        ->assertSuccessful()
        ->assertJsonPath('data.vote.isCreator', false)
        ->assertJsonPath('data.vote.creator.id', $creator->id)
        ->assertJsonPath('data.vote.creator.name', 'Anonymous Creator')
        ->assertJsonPath('data.vote.creator.isCurrentUser', false)
        ->assertJsonPath('data.vote.currentUserOptionId', null)
        ->assertJsonPath('data.vote.selectedOptionId', 0)
        ->assertJsonPath('data.vote.hasCurrentUserVoted', false)
        ->assertJsonPath('data.vote.currentUserVote.hasVoted', false)
        ->assertJsonPath('data.vote.currentUserVote.optionId', null);

    $selectedOption = collect($response->json('data.options'))->firstWhere('id', $optionId);
    expect($selectedOption['isSelectedByCurrentUser'])->toBeFalse();
});

it('returns a neutral realtime payload that requires client refetch', function (): void {
    $creator = User::factory()->create();
    $vote = createVoteWithTwoOptionsForCurrentUserStateTest($creator);
    $optionId = (int) $vote->options->first()->id;

    app(RestaurantGroupVoteService::class)->castBallot($vote, (int) $creator->id, $optionId);

    $payload = app(RestaurantGroupVoteService::class)->broadcastRefreshPayload($vote);

    expect($payload['refreshRequired'])->toBeTrue();
    expect($payload['vote']['id'])->toBe($vote->id);
    expect($payload['vote']['status'])->toBe('active');
    expect($payload['options'] ?? null)->toBeNull();
    expect($payload['voters'] ?? null)->toBeNull();
    expect($payload['currentUserVote'] ?? null)->toBeNull();
});

it('returns a deselection signal when the current user taps the same vote option again', function (): void {
    $creator = User::factory()->create();
    $vote = createVoteWithTwoOptionsForCurrentUserStateTest($creator);
    $optionId = (int) $vote->options->first()->id;

    Sanctum::actingAs($creator);

    $this->postJson('/api/v1/user/restaurants/votes/'.$vote->id.'/ballots', [
        'optionId' => $optionId,
    ])->assertSuccessful()
        ->assertJsonPath('data.vote.currentUserOptionId', $optionId)
        ->assertJsonPath('data.vote.selectedOptionId', $optionId)
        ->assertJsonPath('data.vote.currentUserVote.hasVoted', true)
        ->assertJsonPath('data.vote.currentUserVote.optionId', $optionId)
        ->assertJsonPath('data.vote.hasCurrentUserVoted', true);

    $this->postJson('/api/v1/user/restaurants/votes/'.$vote->id.'/ballots', [
        'optionId' => $optionId,
    ])->assertSuccessful()
        ->assertJsonPath('data.vote.currentUserOptionId', null)
        ->assertJsonPath('data.vote.selectedOptionId', 0)
        ->assertJsonPath('data.vote.myVotedOptionId', 0)
        ->assertJsonPath('data.vote.userVoteOptionId', 0)
        ->assertJsonPath('data.vote.currentUserVote.hasVoted', false)
        ->assertJsonPath('data.vote.currentUserVote.optionId', null)
        ->assertJsonPath('data.vote.hasCurrentUserVoted', false)
        ->assertJsonCount(0, 'data.voters');
});

it('labels a nameless current voter as self instead of the generic fallback', function (): void {
    $creator = User::factory()->create();
    $voter = User::factory()->create(['name' => '']);
    $vote = createVoteWithTwoOptionsForCurrentUserStateTest($creator);
    $optionId = (int) $vote->options->first()->id;

    Sanctum::actingAs($voter);

    $this->postJson('/api/v1/user/restaurants/votes/'.$vote->id.'/ballots', [
        'optionId' => $optionId,
    ])->assertSuccessful()
        ->assertJsonPath('data.voters.0.name', 'أنت')
        ->assertJsonPath('data.voters.0.isCurrentUser', true);
});
