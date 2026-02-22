<?php

declare(strict_types=1);

namespace DevKandil\NotiFire\Contracts;

use DevKandil\NotiFire\Enums\MessagePriority;

interface FcmServiceInterface
{
    /**
     * Set the notification title.
     */
    public function withTitle(string $title): self;

    /**
     * Set the notification body.
     */
    public function withBody(string $body): self;

    /**
     * Set the notification image.
     */
    public function withImage(string $image): self;

    /**
     * Set the notification sound.
     */
    public function withSound(string $sound): self;

    /**
     * Set the notification click action.
     */
    public function withClickAction(string $action): self;

    /**
     * Set the notification icon.
     */
    public function withIcon(string $icon): self;

    /**
     * Set the notification color.
     */
    public function withColor(string $color): self;

    /**
     * Set the notification priority.
     */
    public function withPriority(MessagePriority $priority): self;

    /**
     * Set additional data for the notification.
     */
    public function withAdditionalData(array $data): self;

    /**
     * Send a notification to a specific FCM token.
     */
    public function sendNotification(string $token): bool;

    /**
     * Set a raw FCM message to be sent later.
     */
    public function fromRaw(array $message): self;

    /**
     * Send a notification to one or multiple topics.
     *
     * @param  string|array  $topics  Single topic (string) or multiple topics (array)
     */
    public function sendToTopics(string|array $topics): bool;
}
