<?php

declare(strict_types=1);

namespace Nofi\Service\Push;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nofi\Entity\Notification;
use Nofi\Notification\NotificationStatus;
use Nofi\Notification\PushNotificationPayload;
use Psr\Log\LoggerInterface;
use RuntimeException;

readonly class PushNotificationDelivery
{
    public function __construct(
        private PushService $pushService,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {}

    public function deliver(Notification $notification, PushNotificationPayload $payload): void
    {
        $notification->markProcessing();

        $deviceTokens = [];
        foreach ($notification->getRecipients() as $recipient) {
            $status = $recipient->getStatus();
            if (!$status->isWaiting() && !$status->isResendable()) {
                continue;
            }
            $recipient->markProcessing();
            $deviceTokens[] = $recipient->getRecipient() ?? throw new RuntimeException("Recipient (device token) is missing.");
        }

        if ($deviceTokens === []) {
            $notification->transitionToStatus(NotificationStatus::SENT);
            $this->entityManager->flush();
            $this->entityManager->clear();

            return;
        }

        $results = $this->pushService->send(
            $notification,
            $deviceTokens,
            $payload->title,
            $payload->message,
            $payload->icon,
            $payload->data,
        );

        $resultsByToken = array_column($results, null, "token");
        $failed = 0;

        foreach ($notification->getRecipients() as $recipient) {
            $result = $resultsByToken[$recipient->getRecipient()] ?? null;
            if ($result === null) {
                continue;
            }

            if ($result["success"]) {
                $recipient->markSent();
                $recipient->markSentAt(new DateTimeImmutable());

                continue;
            }

            $recipient->markFailed();
            ++$failed;
            $this->logger->error("Push notification delivery failed for a device token.", [
                "notification" => $notification->getId(),
                "token" => $recipient->getRecipient(),
                "error" => $result["error"],
            ]);
        }

        $notification->transitionToStatus($failed > 0 ? NotificationStatus::FAILED : NotificationStatus::SENT);
        $this->entityManager->flush();
        $this->entityManager->clear();

        if ($failed > 0) {
            // Thrown so Messenger retries; device tokens already delivered to
            // are skipped on the next attempt.
            throw new RuntimeException(sprintf(
                "Push notification %s failed for %d of %d device tokens.",
                $notification->getId(),
                $failed,
                count($deviceTokens),
            ));
        }
    }
}
