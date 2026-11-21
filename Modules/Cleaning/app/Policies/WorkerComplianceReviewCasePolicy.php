<?php

declare(strict_types=1);

namespace Modules\Cleaning\Policies;

use App\Models\User;
use Modules\Cleaning\Models\WorkerComplianceReviewCase;

final class WorkerComplianceReviewCasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('cleaning.worker_compliance.cases.view');
    }

    public function view(User $user, WorkerComplianceReviewCase $case): bool
    {
        return $this->viewAny($user)
            && $case->worker()->whereHas('user', static fn ($query) => $query->where('module_type', 'cleaning_worker'))->exists();
    }

    public function claim(User $user, WorkerComplianceReviewCase $case): bool
    {
        return $this->view($user, $case)
            && $user->can('cleaning.worker_compliance.cases.claim');
    }

    public function review(User $user, WorkerComplianceReviewCase $case): bool
    {
        return $this->view($user, $case)
            && $user->can('cleaning.worker_compliance.cases.review');
    }

    public function confirm(User $user, WorkerComplianceReviewCase $case): bool
    {
        return $this->view($user, $case)
            && $user->can('cleaning.worker_compliance.cases.confirm');
    }
}
