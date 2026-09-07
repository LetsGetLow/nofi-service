<?php

declare(strict_types=1);

namespace Nofi\Application\Notification;

use Nofi\Message\DeleteNotification;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class DeleteNotificationService
{
    public function __construct(private MessageBusInterface $messageBus) {}

    public function delete(string $notificationId): void
    {
        $this->messageBus->dispatch(new DeleteNotification($notificationId));
    }
}
