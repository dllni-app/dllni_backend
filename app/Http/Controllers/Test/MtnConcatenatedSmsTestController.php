<?php

declare(strict_types=1);

namespace App\Http\Controllers\Test;

use App\Actions\Sms\SendMtnConcatenatedSmsAction;
use App\Data\Sms\MtnSmsPayloadData;
use App\Http\Requests\Test\SendMtnConcatenatedSmsTestRequest;
use App\Http\Resources\Sms\MtnSmsSendResource;
use Symfony\Component\HttpFoundation\Response;

final class MtnConcatenatedSmsTestController
{
    public function __invoke(
        SendMtnConcatenatedSmsTestRequest $request,
        SendMtnConcatenatedSmsAction $action,
    ): MtnSmsSendResource {
        abort_unless(
            (bool) config('services.mtn_sms.test_endpoint_enabled', false),
            Response::HTTP_NOT_FOUND
        );

        $result = $action->execute(new MtnSmsPayloadData(
            gsm: $request->validated('gsm'),
            message: $request->validated('message'),
            lang: $request->validated('lang'),
        ));

        return new MtnSmsSendResource($result);
    }
}
