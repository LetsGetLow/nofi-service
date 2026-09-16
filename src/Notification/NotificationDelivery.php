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
     * The notification has already been claimed and persisted as processing.
     * Implementations check the concrete payload type. The worker selects the
     * delivery using the payload's channel after checking the stored channel.
     */
    public function deliver(Notification $notification, NotificationPayload $payload): void;
}
