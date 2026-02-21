<?php

declare(strict_types=1);

namespace Modules\Supermarket\Services;

use Illuminate\Support\Facades\DB;
use Modules\Supermarket\Data\SmOrderDisputeMessageData;
use Modules\Supermarket\Models\SmOrderDisputeMessage;

final class SmOrderDisputeMessageService
{
    public function store(SmOrderDisputeMessageData $data): SmOrderDisputeMessage
    {
        return DB::transaction(static function () use ($data) {
            $message = SmOrderDisputeMessage::create($data->onlyModelAttributes());

            return $message;
        });
    }

    public function update(SmOrderDisputeMessageData $data, SmOrderDisputeMessage $message): SmOrderDisputeMessage
    {
        return DB::transaction(static function () use ($data, $message) {
            tap($message)->update($data->onlyModelAttributes());

            return $message;
        });
    }
}
