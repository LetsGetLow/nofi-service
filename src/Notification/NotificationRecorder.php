<?php

declare(strict_types=1);

namespace Nofi\Notification;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nofi\Entity\Notification;
use Nofi\Entity\User;

final readonly class NotificationRecorder
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function record(string $notificationId, string $userId, NotificationRequest $request): Notification
    {
        $user = $this->entityManager->getReference(User::class, $userId);

        $payloadData = $request->payload->toPayloadData();

        $notification = new Notification($notificationId)
            ->assignChannel($request->payload->channel())
            ->assignPayload($payloadData)
            ->schedule($request->scheduledAt)
            ->assignCreatedBy($user)
            ->recordCreatedAt(new DateTimeImmutable())
            ->markQueued();

        // Topics become recipient rows too, so a topic send is tracked and
        // retried exactly like a device token.
        foreach ($request->targets as $target) {
            $notification->addRecipient($target);
        }

        $this->entityManager->persist($notification);
        $this->entityManager->flush();

        return $notification;
    }
}
