<?php

declare(strict_types=1);

namespace Nofi\Notification;

use LogicException;
use Nofi\Message\SendEmailNotification;
use Nofi\Message\SendPushNotification;
use Nofi\Notification\Email\EmailNotificationPayload;
use Nofi\Notification\Push\PushNotificationPayload;
use Nofi\Entity\Notification;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Uid\Uuid;

final readonly class SendNotificationService
{
    private const int MILLISECONDS_PER_SECOND = 1000;

    public function __construct(
        private MessageBusInterface $messageBus,
        private NotificationRecorder $recorder,
    ) {
    }

    /**
     * Returns what was recorded rather than only its id, so a caller can
     * answer with the stored state instead of guessing it.
     */
    public function send(string $userId, NotificationRequest $request): Notification
    {
        $id = Uuid::v7();

        $payload = $request->payload;
        $message = match (true) {
            $payload instanceof EmailNotificationPayload => new SendEmailNotification($id->toRfc4122(), $payload),
            $payload instanceof PushNotificationPayload => new SendPushNotification($id->toRfc4122(), $payload),
            default => throw new LogicException("Unsupported notification payload: " . $payload::class),
        };
        $notification = $this->recorder->record($id->toRfc4122(), $userId, $request);

        $stamps = [];
        if ($request->scheduledAt !== null) {
            $delayMs = max(0, ($request->scheduledAt->getTimestamp() - time()) * self::MILLISECONDS_PER_SECOND);
            if ($delayMs > 0) {
                $stamps[] = new DelayStamp($delayMs);
            }
        }

        $this->messageBus->dispatch($message, $stamps);

        return $notification;
    }
}
