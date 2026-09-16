<?php

declare(strict_types=1);

namespace Nofi\MessageHandler;

use DomainException;
use Nofi\Message\DeleteNotification;
use Nofi\Notification\NotificationLifecycle;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Handles delete messages queued before deletion became synchronous. */
#[AsMessageHandler]
final readonly class DeleteNotificationHandler
{
    public function __construct(private NotificationLifecycle $lifecycle)
    {
    }

    public function __invoke(DeleteNotification $message): void
    {
        try {
            $this->lifecycle->deleteNotification($message->getNotificationId());
        } catch (DomainException) {
            // An old queued deletion cannot withdraw work that has since started.
        }
    }
}
