<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RestaurantGroupVoteCastBallotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $voteId = $this->route('vote');

        return [
            'optionId' => [
                'required',
                'integer',
                Rule::exists('restaurant_group_vote_options', 'id')->where(
                    fn ($q) => $q->where('vote_id', (int) $voteId)
                ),
            ],
        ];
    }
}
