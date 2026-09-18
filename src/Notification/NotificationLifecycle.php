<?php

declare(strict_types=1);

namespace Nofi\Notification;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use DomainException;
use Nofi\Entity\Notification;

readonly class NotificationLifecycle
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function claimNotificationForDelivery(string $id): ?Notification
    {
        return $this->entityManager->wrapInTransaction(function () use ($id): ?Notification {
            $notification = $this->getLockedNotification($id);
            if ($notification === null) {
                return null;
            }

            $status = $notification->getStatus();
            if ($status === NotificationStatus::PROCESSING) {
                // Keep the queued payload available through Messenger retries
                // and the failed transport if the original worker has crashed.
                throw new DomainException(sprintf('Notification %s is already being processed.', $id));
            }

            if (!$status->isWaiting() && !$status->isResendable()) {
                return null;
            }

            $notification->markProcessing();

            return $notification;
        });
    }

    public function cancelNotification(string $id): ?Notification
    {
        return $this->entityManager->wrapInTransaction(function () use ($id): ?Notification {
            $notification = $this->getLockedNotification($id);
            if ($notification !== null) {
                $this->assertNotificationCanBeCancelled($notification);
                $notification->markCancelled();
            }

            return $notification;
        });
    }

    public function deleteNotification(string $id): void
    {
        $this->entityManager->wrapInTransaction(function () use ($id): void {
            $notification = $this->getLockedNotification($id);
            if ($notification !== null) {
                $this->assertNotificationCanBeDeleted($notification);
                $this->entityManager->remove($notification);
            }
        });
    }

    /** Preserve recipient successes when delivery fails before its normal flush. */
    public function recordDeliveryFailure(Notification $notification): void
    {
        // Delivery may already have flushed its failure and cleared the manager.
        // A database failure can close it; that claim then needs operator recovery.
        if (!$this->entityManager->isOpen() || !$this->entityManager->contains($notification)) {
            return;
        }

        $notification->markFailed();
        foreach ($notification->getRecipients() as $recipient) {
            if ($recipient->getStatus() === NotificationStatus::PROCESSING) {
                $recipient->markFailed();
            }
        }

        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    private function getLockedNotification(string $id): ?Notification
    {
        // Query the row even if the API provider already loaded the entity.
        // Refresh avoids a stale status; a deleted row must return null.
        return $this->entityManager->createQueryBuilder()
            ->select('notification')
            ->from(Notification::class, 'notification')
            ->where('notification.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    private function assertNotificationCanBeCancelled(Notification $notification): void
    {
        if (!$notification->getStatus()->isWithdrawable()) {
            throw new DomainException(sprintf(
                'Notification %s is %s and cannot be cancelled. Only a send that has not started can be withdrawn.',
                $notification->getId(),
                $notification->getStatus()->value,
            ));
        }
    }

    private function assertNotificationCanBeDeleted(Notification $notification): void
    {
        if (!$notification->getStatus()->canBeDeleted()) {
            throw new DomainException(sprintf(
                'Notification %s is %s and cannot be deleted. Only a send that has not started, '
                . 'or one that was cancelled, can be removed.',
                $notification->getId(),
                $notification->getStatus()->value,
            ));
        }
    }
}
