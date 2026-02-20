<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\SystemAlertData;
use App\Models\SystemAlert;
use Illuminate\Support\Facades\DB;

final class SystemAlertService
{
    public function update(SystemAlertData $data, SystemAlert $systemAlert): SystemAlert
    {
        return DB::transaction(static function () use ($data, $systemAlert) {
            tap($systemAlert)->update($data->onlyModelAttributes());

            return $systemAlert;
        });
    }
}
