<?php

declare(strict_types=1);

namespace Modules\Supermarket\Data;

use Carbon\Carbon;
use Spatie\LaravelData\Attributes\Validation\Date;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;

final class SmProductExpirationData extends Data
{
    public function __construct(
        #[Required, Date]
        public Carbon $expires_at,
    ) {}
}
