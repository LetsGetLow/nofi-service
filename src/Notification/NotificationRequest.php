<?php

declare(strict_types=1);

namespace Nofi\Notification;

use DateTimeImmutable;

final readonly class NotificationRequest
{
    /** @param list<string> $targets */
    public function __construct(
        public NotificationPayload $payload,
        public array $targets,
        public ?DateTimeImmutable $scheduledAt = null,
    ) {
    }
}
