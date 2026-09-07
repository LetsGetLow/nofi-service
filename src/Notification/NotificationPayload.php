<?php

declare(strict_types=1);

namespace Nofi\Notification;

/**
 * What a channel decided to send, after the request was validated.
 *
 * Each channel's payload knows how to describe itself for the notification
 * record. That used to be a match on $payload::class inside
 * NotificationRecorder, which meant the recorder had to be edited for every
 * new channel and would raise UnhandledMatchError if it was not.
 */
interface NotificationPayload
{
    /**
     * The payload as it is stored on the notification.
     *
     * Metadata only where the content is large: an email's attachments travel
     * in the queued message and are never persisted a second time.
     *
     * @return array<string, mixed>
     */
    public function toPayloadData(): array;
}
