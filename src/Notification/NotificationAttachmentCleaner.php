<?php

declare(strict_types=1);

namespace Nofi\Notification;

use Nofi\Entity\Notification;
use Nofi\Notification\Email\AttachmentStorage;

/**
 * Channel-agnostic on purpose: called from processors that handle any
 * notification, even though only email ever produces attachment files today.
 * A no-op for push, and for an email notification with none.
 */
final readonly class NotificationAttachmentCleaner
{
    public function __construct(private AttachmentStorage $storage)
    {
    }

    public function cleanUpFor(Notification $notification): void
    {
        foreach ($notification->getPayload()["attachments"] ?? [] as $attachment) {
            if (($attachment["path"] ?? null) !== null) {
                $this->storage->delete($attachment["path"]);
            }
        }
    }
}
