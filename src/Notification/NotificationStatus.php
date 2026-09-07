<?php

declare(strict_types=1);

namespace Nofi\Notification;

enum NotificationStatus: string
{
    case CREATED = "created";
    case QUEUED = "queued";
    case PROCESSING = "processing";
    case FAILED = "failed";
    case SENT = "sent";
    case CANCELLED = "cancelled";

    /**
     * Cancelled counts as final: the send is over, and what is over is a
     * record. That is what stops it from being deleted or edited afterwards.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::SENT, self::FAILED, self::CANCELLED], true);
    }

    public function isWaiting(): bool
    {
        return $this === self::CREATED || $this === self::QUEUED;
    }

    public function isResendable(): bool
    {
        return $this === self::FAILED;
    }

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::CREATED => [self::QUEUED, self::PROCESSING, self::FAILED, self::CANCELLED],
            self::QUEUED => [self::PROCESSING, self::FAILED, self::CANCELLED],
            // Not cancellable: a worker is already delivering, so an abort
            // would race the send rather than prevent it.
            self::PROCESSING => [self::SENT, self::FAILED],
            // Not back to queued: nothing re-dispatches a message, so that
            // edge only ever promised a resend the API does not offer. A
            // retry comes from Messenger and goes straight to processing.
            self::FAILED => [self::PROCESSING, self::FAILED],
            self::SENT => [],
            self::CANCELLED => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return $this === $next || in_array($next, $this->allowedTransitions(), true);
    }
}
