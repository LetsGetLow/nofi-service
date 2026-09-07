<?php

declare(strict_types=1);

namespace Nofi\MessageHandler;

use Nofi\Message\SendPushNotification;
use Nofi\Notification\NotificationStatus;
use Nofi\Notification\PushNotificationPayload;
use Nofi\Service\Push\PushNotificationDelivery;
use Nofi\Repository\NotificationRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class SendPushNotificationHandler
{
    public function __construct(
        private NotificationRepository $notificationRepository,
        private PushNotificationDelivery $delivery,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(SendPushNotification $message): void
    {
        $notification = $this->notificationRepository->find($message->getNotificationId());
        if ($notification === null) {
            // The notification was deleted between being queued and being
            // consumed, which DeleteNotification makes a normal outcome.
            // Retrying cannot bring it back, so stop instead of exhausting
            // the retry budget and landing in the failed transport.
            $this->logger->info("Skipping a notification that no longer exists.", [
                "notification" => $message->getNotificationId(),
            ]);

            return;
        }

        // A cancellation cannot withdraw the queued message, so this is where
        // it takes effect. Asked as "may this still be delivered" rather than
        // "is it waiting", because a retry of a failed send arrives here as
        // failed and has to get through. Skipped rather than raised: raising
        // would retry five times and then park the message in the failed
        // transport, for a decision that was deliberate.
        if (!$notification->getStatus()->canTransitionTo(NotificationStatus::PROCESSING)) {
            $this->logger->info("Skipping a notification that is no longer waiting to be sent.", [
                "notification" => $message->getNotificationId(),
                "status" => $notification->getStatus()->value,
            ]);

            return;
        }

        $dto = $message->getNotificationDto();
        $payload = PushNotificationPayload::fromDto($dto);

        $this->delivery->deliver($notification, $payload);
    }
}
