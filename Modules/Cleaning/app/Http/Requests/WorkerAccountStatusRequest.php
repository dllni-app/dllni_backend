<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Requests;

use App\Models\Worker;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class WorkerAccountStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) auth()->user()?->worker;
    }

    public function rules(): array
    {
        return [
            'isActive' => ['required', 'boolean'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->boolean('isActive')) {
                    return;
                }

                $worker = $this->user()?->worker;
                if (! $worker instanceof Worker) {
                    return;
                }

                if (
                    $worker->home_address === null
                    || mb_trim((string) $worker->home_address) === ''
                    || $worker->home_latitude === null
                    || $worker->home_longitude === null
                ) {
                    $validator->errors()->add(
                        'isActive',
                        'Worker home location is required before activating the account.',
                    );
                }
            },
        ];
    }
}
