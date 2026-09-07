<?php

declare(strict_types=1);

namespace Nofi\Application\Notification;

use LogicException;
use Nofi\Dto\SendNotificationDto;
use Nofi\Entity\Notification;
use Nofi\Entity\User;
use Nofi\Notification\NotificationRecorder;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Uid\Uuid;

final readonly class SendNotificationService
{
    private const int MILLISECONDS_PER_SECOND = 1000;

    public function __construct(
        private MessageBusInterface $messageBus,
        private NotificationRecorder $recorder,
    ) {}

    /**
     * Returns what was recorded rather than only its id, so a caller can
     * answer with the stored state instead of guessing it.
     */
    public function send(User $user, SendNotificationDto $dto): Notification
    {
        $id = Uuid::v7();

        // Validation guarantees a channel by the time a request reaches here,
        // so this only fires for a caller that built the DTO itself.
        $channel = $dto->channel ?? throw new LogicException(
            "A notification cannot be sent without a channel.",
        );

        $payload = $channel->payloadFrom($dto);
        $message = $channel->newMessage($id->toRfc4122(), $dto, $user->getId());
        $notification = $this->recorder->record($message, $payload);

        $stamps = [];
        if ($dto->scheduledAt !== null) {
            $delayMs = max(0, ($dto->scheduledAt->getTimestamp() - time()) * self::MILLISECONDS_PER_SECOND);
            if ($delayMs > 0) {
                $stamps[] = new DelayStamp($delayMs);
            }
        }

        $this->messageBus->dispatch($message, $stamps);

        return $notification;
    }
}
