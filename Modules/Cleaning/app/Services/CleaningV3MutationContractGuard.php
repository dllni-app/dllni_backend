<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use DomainException;

/**
 * Pure V2/V3 mutation admission decision. The caller MUST first prove that the
 * actor owns or is assigned to the booking; the allowed IDs must be filtered
 * by both booking association and the actor's current visit authorization.
 *
 * This does not authorize a booking and is not installed on legacy routes.
 * A transactional V3 writer must enforce this under lock before any mutation.
 */
final class CleaningV3MutationContractGuard
{
    /**
     * @param  list<int>  $authorizedCurrentVisitIds
     * @return array{allowed:bool,httpStatus:int,code:string}
     */
    public function decide(
        string $bookingContractVersion,
        bool $clientHasV3VisitCapability,
        ?int $requestedVisitId,
        array $authorizedCurrentVisitIds,
    ): array {
        if ($bookingContractVersion !== 'v3') {
            // Never reinterpret V2 action semantics or quote identities.
            return ['allowed' => true, 'httpStatus' => 200, 'code' => 'V2_UNCHANGED'];
        }
        if (! $clientHasV3VisitCapability) {
            return ['allowed' => false, 'httpStatus' => 426, 'code' => 'UPDATE_REQUIRED'];
        }
        if ($requestedVisitId === null) {
            return ['allowed' => false, 'httpStatus' => 409, 'code' => 'VISIT_REQUIRED'];
        }
        if ($requestedVisitId < 1 || ! in_array($requestedVisitId, $authorizedCurrentVisitIds, true)) {
            // Do not disclose whether the session belongs to a sibling booking
            // or an unrelated customer.
            return ['allowed' => false, 'httpStatus' => 404, 'code' => 'VISIT_NOT_FOUND'];
        }

        if (count($authorizedCurrentVisitIds) !== count(array_unique($authorizedCurrentVisitIds))) {
            throw new DomainException('INVALID_AUTHORIZED_VISIT_SET');
        }

        return ['allowed' => true, 'httpStatus' => 200, 'code' => 'V3_VISIT_SCOPED'];
    }
}
