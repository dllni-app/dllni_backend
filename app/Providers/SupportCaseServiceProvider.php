<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\SupportCase;
use App\Models\SupportCaseMessage;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

final class SupportCaseServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Relation::morphMap([
            'support_case' => SupportCase::class,
            'support_case_message' => SupportCaseMessage::class,
        ]);
    }
}
