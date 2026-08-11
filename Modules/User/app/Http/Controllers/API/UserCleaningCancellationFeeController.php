<?php

declare(strict_types=1);

namespace Modules\User\Http\Controllers\API;

use App\Models\CleaningFinancialSetting;
use Illuminate\Http\JsonResponse;

final class UserCleaningCancellationFeeController
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'amount' => CleaningFinancialSetting::currentUserCancellationFee(),
            'currency' => config('app.currency', 'SYP'),
        ]);
    }
}
