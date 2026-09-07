<?php

declare(strict_types=1);

namespace Nofi\Notification;

use Nofi\Entity\Notification;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Sends what a channel produced, and records what happened to each recipient.
 *
 * The two implementations already had the same shape and the same semantics —
 * skip a recipient that is neither waiting nor resendable, mark the rest,
 * flush, and throw if any failed so Messenger retries — but shared no
 * supertype, so each had its own handler and the pair could only drift apart.
 *
 * Implementations are tagged automatically and resolved by channel through
 * NotificationDeliveries, so a new channel is a new implementation rather than
 * an edit here.
 */
#[AutoconfigureTag("nofi.notification_delivery")]
interface NotificationDelivery
{
    public function channel(): NotificationChannel;

    /**
     * The payload is typed as the interface rather than the channel's own
     * class because PHP requires a parameter type to be contravariant: an
     * implementation cannot narrow it. Each one asserts what it needs instead,
     * the way a Symfony ConstraintValidator does — and the pairing is safe by
     * construction, because the payload and the delivery are chosen from the
     * same NotificationChannel.
     */
    public function deliver(Notification $notification, NotificationPayload $payload): void;
}
