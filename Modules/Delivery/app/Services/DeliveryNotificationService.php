<?php

declare(strict_types=1);

namespace Modules\Delivery\Services;

use App\Models\User;
use App\Notifications\Core\NotificationFeedNormalizer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Modules\Delivery\Models\DeliveryAssignmentAttempt;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Delivery\Notifications\DeliveryCanonicalNotification;

final class DeliveryNotificationService
{
    public function __construct(
        private readonly NotificationFeedNormalizer $feedNormalizer,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function feedForUser(User $user, bool $unreadOnly = false, int $limit = 50): Collection
    {
        $query = $user->notifications()->orderByDesc('created_at');

        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        return $query
            ->limit($limit)
            ->get()
            ->filter(fn (DatabaseNotification $notification): bool => $this->isDeliveryNotification($notification))
            ->map(fn (DatabaseNotification $notification): array => $this->mapNotification($notification))
            ->values();
    }

    public function unreadCountForUser(User $user): int
    {
        return $this->feedForUser($user, unreadOnly: true, limit: 500)->count();
    }

    public function markAsRead(User $user, string $notificationId): void
    {
        $notification = $user->notifications()->where('id', $notificationId)->firstOrFail();
        $notification->markAsRead();
    }

    public function markAllAsRead(User $user): void
    {
        foreach ($this->feedForUser($user, unreadOnly: true, limit: 500) as $item) {
            $this->markAsRead($user, (string) $item['id']);
        }
    }

    public function notifyOrderCompleted(DeliveryOrder $order): void
    {
        $context = ['order_number' => $order->order_number];
        $extraData = [
            'orderId' => $order->id,
            'orderNumber' => $order->order_number,
            'deepLinkTarget' => 'delivery_order_details',
        ];

        $this->notifyCompanyUsers($order->company, 'delivery.order.completed', $context, $extraData);

        $driverUser = $order->driver?->user;

        if ($driverUser instanceof User) {
            $driverUser->notify(new DeliveryCanonicalNotification(
                canonicalType: 'delivery.order.completed',
                templateContext: $context,
                extraData: $extraData,
            ));
        }
    }

    public function notifyFinancialSuspension(DeliveryCompany $company): void
    {
        $account = $company->financialAccount;

        $this->notifyCompanyUsers(
            company: $company,
            canonicalType: 'delivery.financial.suspended',
            templateContext: [
                'company_name' => $company->name,
                'balance' => number_format((float) ($account?->current_balance ?? 0), 2),
                'limit' => number_format((float) ($account?->financial_limit ?? $company->financial_limit), 2),
            ],
            extraData: [
                'companyId' => $company->id,
                'deepLinkTarget' => 'delivery_financial',
            ],
        );
    }

    public function notifyFinancialReactivation(DeliveryCompany $company): void
    {
        $this->notifyCompanyUsers(
            company: $company,
            canonicalType: 'delivery.financial.reactivated',
            templateContext: ['company_name' => $company->name],
            extraData: [
                'companyId' => $company->id,
                'deepLinkTarget' => 'delivery_financial',
            ],
        );
    }

    public function notifyOfferToDriver(DeliveryAssignmentAttempt $attempt): void
    {
        $attempt->loadMissing(['order', 'driver.user']);
        $order = $attempt->order;
        $driver = $attempt->driver;

        if (! $order instanceof DeliveryOrder || ! $driver?->user instanceof User) {
            return;
        }

        $context = ['order_number' => $order->order_number];
        $extraData = [
            'orderId' => $order->id,
            'orderNumber' => $order->order_number,
            'attemptId' => $attempt->id,
            'deepLinkTarget' => 'delivery_offer_details',
        ];

        $driver->user->notify(new DeliveryCanonicalNotification(
            canonicalType: 'delivery.order.offer',
            templateContext: $context,
            extraData: $extraData,
        ));
    }

    public function notifyOfferTimedOut(DeliveryAssignmentAttempt $attempt): void
    {
        $attempt->loadMissing(['order', 'driver.user']);
        $driver = $attempt->driver;

        if (! $attempt->order instanceof DeliveryOrder || ! $driver?->user instanceof User) {
            return;
        }

        $driver->user->notify(new DeliveryCanonicalNotification(
            canonicalType: 'delivery.order.offer_timed_out',
            templateContext: ['order_number' => $attempt->order->order_number],
            extraData: [
                'orderId' => $attempt->order->id,
                'orderNumber' => $attempt->order->order_number,
                'attemptId' => $attempt->id,
                'deepLinkTarget' => 'delivery_offers',
            ],
        ));
    }

    public function notifyOrderAccepted(DeliveryOrder $order): void
    {
        $order->loadMissing(['company.owner', 'company.staff.user', 'driver']);

        $context = ['order_number' => $order->order_number];
        $extraData = [
            'orderId' => $order->id,
            'orderNumber' => $order->order_number,
            'driverId' => $order->driver_id,
            'deepLinkTarget' => 'delivery_order_details',
        ];

        $this->notifyCompanyUsers($order->company, 'delivery.order.accepted', $context, $extraData);
    }

    public function notifyOrderStopped(DeliveryOrder $order, string $reason): void
    {
        $order->loadMissing(['company.owner', 'company.staff.user']);

        $context = [
            'order_number' => $order->order_number,
            'reason' => $reason,
        ];
        $extraData = [
            'orderId' => $order->id,
            'orderNumber' => $order->order_number,
            'reason' => $reason,
            'deepLinkTarget' => 'delivery_order_details',
        ];

        $this->notifyCompanyUsers($order->company, 'delivery.order.stopped', $context, $extraData);
    }

    public function notifyDisputeOpened(DeliveryOrder $order, \App\Models\Dispute $dispute): void
    {
        $context = [
            'order_number' => $order->order_number,
            'ticket_number' => $dispute->ticket_number,
        ];
        $extraData = [
            'orderId' => $order->id,
            'orderNumber' => $order->order_number,
            'disputeId' => $dispute->id,
            'ticketNumber' => $dispute->ticket_number,
            'deepLinkTarget' => 'delivery_dispute_details',
        ];

        $this->notifyCompanyUsers($order->company, 'delivery.dispute.opened', $context, $extraData);

        $driverUser = $order->driver?->user;

        if ($driverUser instanceof User) {
            $driverUser->notify(new DeliveryCanonicalNotification(
                canonicalType: 'delivery.dispute.opened',
                templateContext: $context,
                extraData: $extraData,
            ));
        }
    }

    public function notifyOrderStarted(DeliveryOrder $order): void
    {
        $this->notifyOrderLifecycle($order, 'delivery.order.started');
    }

    public function notifyOrderPickedUp(DeliveryOrder $order): void
    {
        $this->notifyOrderLifecycle($order, 'delivery.order.picked_up');
    }

    public function notifyOrderDelivered(DeliveryOrder $order): void
    {
        $this->notifyOrderLifecycle($order, 'delivery.order.delivered');
    }

    public function notifyDisputeRejected(DeliveryOrder $order, \App\Models\Dispute $dispute): void
    {
        $context = [
            'order_number' => $order->order_number,
            'ticket_number' => $dispute->ticket_number,
        ];
        $extraData = [
            'orderId' => $order->id,
            'orderNumber' => $order->order_number,
            'disputeId' => $dispute->id,
            'ticketNumber' => $dispute->ticket_number,
            'deepLinkTarget' => 'delivery_dispute_details',
        ];

        $this->notifyCompanyUsers($order->company, 'delivery.dispute.rejected', $context, $extraData);

        $driverUser = $order->driver?->user;

        if ($driverUser instanceof User) {
            $driverUser->notify(new DeliveryCanonicalNotification(
                canonicalType: 'delivery.dispute.rejected',
                templateContext: $context,
                extraData: $extraData,
            ));
        }
    }

    public function notifyDisputeResolved(DeliveryOrder $order, \App\Models\Dispute $dispute): void
    {
        $status = $dispute->status instanceof \App\Enums\DisputeStatus
            ? $dispute->status->value
            : (string) $dispute->status;

        $context = [
            'order_number' => $order->order_number,
            'ticket_number' => $dispute->ticket_number,
            'status' => $status,
        ];
        $extraData = [
            'orderId' => $order->id,
            'orderNumber' => $order->order_number,
            'disputeId' => $dispute->id,
            'ticketNumber' => $dispute->ticket_number,
            'status' => $status,
            'deepLinkTarget' => 'delivery_dispute_details',
        ];

        $this->notifyCompanyUsers($order->company, 'delivery.dispute.resolved', $context, $extraData);

        $driverUser = $order->driver?->user;

        if ($driverUser instanceof User) {
            $driverUser->notify(new DeliveryCanonicalNotification(
                canonicalType: 'delivery.dispute.resolved',
                templateContext: $context,
                extraData: $extraData,
            ));
        }
    }

    public function notifyTrustScoreChanged(DeliveryDriver $driver, int $delta, int $scoreAfter, string $reason): void
    {
        $driver->loadMissing('user');

        if (! $driver->user instanceof User) {
            return;
        }

        $driver->user->notify(new DeliveryCanonicalNotification(
            canonicalType: 'delivery.driver.trust_changed',
            templateContext: [
                'delta' => ($delta > 0 ? '+' : '').(string) $delta,
                'score' => (string) $scoreAfter,
                'reason' => $reason,
            ],
            extraData: [
                'driverId' => $driver->id,
                'trustScore' => $scoreAfter,
                'scoreDelta' => $delta,
                'reason' => $reason,
                'deepLinkTarget' => 'delivery_profile',
            ],
        ));
    }

    public function notifyCollectionPosted(DeliveryCompany $company, float $amount, ?string $note = null): void
    {
        $this->notifyCompanyUsers(
            company: $company,
            canonicalType: 'delivery.financial.collection_posted',
            templateContext: [
                'company_name' => $company->name,
                'amount' => number_format($amount, 2),
            ],
            extraData: [
                'companyId' => $company->id,
                'amount' => $amount,
                'note' => $note,
                'deepLinkTarget' => 'delivery_financial',
            ],
        );
    }

    /**
     * @param  array<string, scalar|null>  $templateContext
     * @param  array<string, mixed>  $extraData
     */
    public function notifyCompanyUsers(
        DeliveryCompany $company,
        string $canonicalType,
        array $templateContext = [],
        array $extraData = [],
    ): void {
        foreach ($this->companyNotifiableUsers($company) as $user) {
            $user->notify(new DeliveryCanonicalNotification(
                canonicalType: $canonicalType,
                templateContext: $templateContext,
                extraData: $extraData,
            ));
        }
    }

    public function isDeliveryNotification(DatabaseNotification $notification): bool
    {
        /** @var array<string, mixed> $data */
        $data = is_array($notification->data) ? $notification->data : [];

        if (($data['module'] ?? null) === 'delivery') {
            return true;
        }

        $canonicalType = $data['canonical_type'] ?? $data['canonicalType'] ?? null;

        return is_string($canonicalType) && str_starts_with($canonicalType, 'delivery.');
    }

    private function notifyOrderLifecycle(DeliveryOrder $order, string $canonicalType): void
    {
        $order->loadMissing(['company.owner', 'company.staff.user', 'driver.user']);

        $context = ['order_number' => $order->order_number];
        $extraData = [
            'orderId' => $order->id,
            'orderNumber' => $order->order_number,
            'driverId' => $order->driver_id,
            'deepLinkTarget' => 'delivery_order_details',
        ];

        $this->notifyCompanyUsers($order->company, $canonicalType, $context, $extraData);

        $driverUser = $order->driver?->user;

        if ($driverUser instanceof User) {
            $driverUser->notify(new DeliveryCanonicalNotification(
                canonicalType: $canonicalType,
                templateContext: $context,
                extraData: $extraData,
            ));
        }
    }

    /** @return EloquentCollection<int, User> */
    private function companyNotifiableUsers(DeliveryCompany $company): EloquentCollection
    {
        $users = new EloquentCollection;

        $company->loadMissing(['owner', 'staff.user']);

        if ($company->owner instanceof User) {
            $users->push($company->owner);
        }

        foreach ($company->staff as $staffMember) {
            if ($staffMember->is_active && $staffMember->user instanceof User) {
                $users->push($staffMember->user);
            }
        }

        return $users->unique('id')->values();
    }

    /** @return array<string, mixed> */
    private function mapNotification(DatabaseNotification $notification): array
    {
        $normalized = $this->feedNormalizer->normalize($notification);

        return [
            'id' => $notification->id,
            'title' => $normalized['title'],
            'body' => $normalized['body'],
            'category' => $normalized['category'],
            'priority' => $normalized['priority'],
            'icon' => $normalized['icon'],
            'data' => $normalized['data'],
            'createdAt' => $normalized['createdAt'],
            'readAt' => $normalized['readAt'],
            'isRead' => $notification->read_at !== null,
        ];
    }
}
