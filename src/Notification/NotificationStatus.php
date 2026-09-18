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
     * The send is decided: no further status transition happens
     * automatically. This does not by itself say whether the record can
     * still be deleted — see canBeDeleted() for that.
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

    /**
     * May a caller still stop this send by cancelling it?
     *
     * PROCESSING is deliberately excluded: a worker is already delivering, so
     * cancelling would race the send rather than prevent it. That is the same
     * rule allowedTransitions() encodes by leaving CANCELLED off PROCESSING —
     * named once here because cancel and delete each used to ask it their own
     * way, and disagreed. Cancel asked isWaiting() and refused; delete asked
     * isFinal() and let a send in flight be removed.
     *
     * Deletion is deliberately not the same question — see canBeDeleted(),
     * which also allows CANCELLED. That is a second, narrower divergence from
     * this method, made on purpose: unlike the PROCESSING bug above, nothing
     * about PROCESSING changes for either method.
     */
    public function isWithdrawable(): bool
    {
        return $this->isWaiting();
    }

    /**
     * May a caller remove this notification's record entirely?
     *
     * Unlike isWithdrawable() (used for cancelling), CANCELLED counts here
     * too: nothing was ever delivered, so there is no delivery outcome to
     * preserve as a record. SENT and FAILED stay excluded because they
     * document a real attempt. PROCESSING stays excluded because a worker may
     * still be delivering — removing the row out from under it would race
     * the send, exactly as it would for cancelling.
     */
    public function canBeDeleted(): bool
    {
        return $this->isWaiting() || $this === self::CANCELLED;
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
