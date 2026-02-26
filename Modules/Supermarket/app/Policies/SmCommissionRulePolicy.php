<?php

declare(strict_types=1);

namespace Modules\Supermarket\Policies;

use App\Models\User;
use Modules\Supermarket\Models\SmCommissionRule;

final class SmCommissionRulePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SmCommissionRule $smCommissionRule): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, SmCommissionRule $smCommissionRule): bool
    {
        return true;
    }

    public function delete(User $user, SmCommissionRule $smCommissionRule): bool
    {
        return true;
    }
}
