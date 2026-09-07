<?php

declare(strict_types=1);

namespace Nofi\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Nofi\Entity\Notification;
use Nofi\Message\DeleteNotification;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class DeleteNotificationHandler
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function __invoke(DeleteNotification $message): void
    {
        $notification = $this->entityManager->find(
            Notification::class,
            $message->getNotificationId(),
        );

        if (!$notification instanceof Notification) {
            return;
        }

        if ($notification->getStatus()->isFinal()) {
            return;
        }

        $this->entityManager->remove($notification);
        $this->entityManager->flush();
    }
}
