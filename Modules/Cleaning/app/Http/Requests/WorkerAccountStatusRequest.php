<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (! $this->boolean('isActive')) {
                return;
            }

            $worker = auth()->user()?->worker;
            if (! $worker) {
                return;
            }

            if ($worker->home_address === null || mb_trim($worker->home_address) === '') {
                $validator->errors()->add('isActive', 'Set home location before activating your account.');

                return;
            }

            if ($worker->home_latitude === null || $worker->home_longitude === null) {
                $validator->errors()->add('isActive', 'Set home location before activating your account.');
            }
        });
    }
}
