<?php

declare(strict_types=1);

namespace Modules\Supermarket\Policies;

use App\Models\User;
use Modules\Supermarket\Models\SmOfferProduct;

final class SmOfferProductPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SmOfferProduct $smOfferProduct): bool
    {
        return true;
    }
}
