<?php

declare(strict_types=1);

namespace Nofi\Notification;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nofi\Entity\Notification;
use Nofi\Entity\User;
use Nofi\Message\NotificationMessage;

readonly class NotificationRecorder
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function record(NotificationMessage $message, NotificationPayload $payload): Notification
    {
        $dto = $message->getNotificationDto();
        $user = $this->entityManager->getReference(User::class, $message->getUserId());

        $payloadData = $payload->toPayloadData();

        $notification = new Notification($message->getNotificationId())
            ->assignChannel($dto->channel ?? NotificationChannel::EMAIL)
            ->assignPayload($payloadData)
            ->schedule($dto->scheduledAt)
            ->assignCreatedBy($user)
            ->recordCreatedAt(new DateTimeImmutable())
            ->markQueued();

        // Topics become recipient rows too, so a topic send is tracked and
        // retried exactly like a device token.
        foreach ($dto->deliveryTargets() as $target) {
            $notification->addRecipient($target);
        }

        $this->entityManager->persist($notification);
        $this->entityManager->flush();

        return $notification;
    }
}
