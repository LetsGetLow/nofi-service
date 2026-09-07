<?php

declare(strict_types=1);

namespace Nofi\MessageHandler;

use LogicException;
use Nofi\Message\NotificationMessage;
use Nofi\Notification\NotificationDeliveries;
use Nofi\Notification\NotificationStatus;
use Nofi\Repository\NotificationRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Sends a notification on whichever channel it was created for.
 *
 * This replaced SendEmailNotificationHandler and SendPushNotificationHandler,
 * which were identical apart from three type names. It is registered against
 * the NotificationMessage interface rather than a concrete message class:
 * Messenger resolves handlers for a message's interfaces as well as its own
 * class, so both SendEmailNotification and SendPushNotification arrive here,
 * and a third channel's message would too without this file changing.
 */
#[AsMessageHandler(handles: NotificationMessage::class)]
final readonly class SendNotificationHandler
{
    public function __construct(
        private NotificationRepository $notificationRepository,
        private NotificationDeliveries $deliveries,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(NotificationMessage $message): void
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
        $channel = $dto->channel ?? throw new LogicException(sprintf(
            "Notification %s was queued without a channel.",
            $message->getNotificationId(),
        ));

        $this->deliveries->for($channel)->deliver($notification, $channel->payloadFrom($dto));
    }
}
