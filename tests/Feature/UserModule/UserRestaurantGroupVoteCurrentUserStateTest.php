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
        ->assertJsonPath('data.vote.currentUserOptionId', null)
        ->assertJsonPath('data.vote.selectedOptionId', 0)
        ->assertJsonPath('data.vote.myVotedOptionId', 0)
        ->assertJsonPath('data.vote.userVoteOptionId', 0)
        ->assertJsonPath('data.vote.hasCurrentUserVoted', false);
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
        ->assertJsonPath('data.vote.hasCurrentUserVoted', true);

    $this->postJson('/api/v1/user/restaurants/votes/'.$vote->id.'/ballots', [
        'optionId' => $optionId,
    ])->assertSuccessful()
        ->assertJsonPath('data.vote.currentUserOptionId', null)
        ->assertJsonPath('data.vote.selectedOptionId', 0)
        ->assertJsonPath('data.vote.myVotedOptionId', 0)
        ->assertJsonPath('data.vote.userVoteOptionId', 0)
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
