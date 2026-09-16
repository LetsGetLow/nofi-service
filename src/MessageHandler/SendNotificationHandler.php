<?php

declare(strict_types=1);

namespace Nofi\MessageHandler;

use LogicException;
use Throwable;
use Nofi\Message\NotificationMessage;
use Nofi\Notification\NotificationDeliveries;
use Nofi\Notification\NotificationLifecycle;
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
        private NotificationLifecycle $lifecycle,
        private NotificationDeliveries $deliveries,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(NotificationMessage $message): void
    {
        $notification = $this->lifecycle->claimNotificationForDelivery($message->getNotificationId());
        if ($notification === null) {
            $this->logger->info("Skipping a missing notification or one that cannot be claimed.", [
                "notification" => $message->getNotificationId(),
            ]);

            return;
        }

        // The claim is committed before calling an external provider.
        try {
            $payload = $message->getPayload();
            if ($notification->getChannel() !== $payload->channel()) {
                throw new LogicException(sprintf(
                    "Notification %s has a different channel from its queued payload.",
                    $message->getNotificationId(),
                ));
            }

            $this->deliveries->for($payload->channel())->deliver($notification, $payload);
        } catch (Throwable $exception) {
            $this->lifecycle->recordDeliveryFailure($notification);

            throw $exception;
        }
    }
}
