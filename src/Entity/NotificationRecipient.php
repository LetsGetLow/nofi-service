<?php

declare(strict_types=1);

namespace Nofi\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nofi\Notification\NotificationStatus;
use LogicException;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
class NotificationRecipient
{
    public function __construct()
    {
        $this->id = Uuid::v7()->toRfc4122();
    }

    #[ORM\Id]
    #[ORM\Column(type: Types::GUID, unique: true)]
    private string $id;

    #[ORM\Column(length: 255)]
    private ?string $recipient = null;

    #[ORM\Column(enumType: NotificationStatus::class)]
    private NotificationStatus $status = NotificationStatus::QUEUED;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $sentAt = null;

    // inversedBy names the collection on the other side, without which the
    // relation is only half declared and doctrine:schema:validate refuses it.
    // onDelete matches what the migration built: the foreign key carries the
    // cascade, so removing a notification takes its recipients with it even
    // when nothing loads them into memory first.
    #[ORM\ManyToOne(targetEntity: Notification::class, inversedBy: "recipients")]
    #[ORM\JoinColumn(
        name: "notification_id",
        referencedColumnName: "id",
        nullable: false,
        onDelete: "CASCADE",
    )]
    private ?Notification $notification = null;

    public function getId(): string
    {
        return $this->id;
    }

    public function getRecipient(): ?string
    {
        return $this->recipient;
    }

    public function assignRecipient(string $recipient): static
    {
        $this->recipient = $recipient;

        return $this;
    }

    public function getSentAt(): ?DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function markSentAt(?DateTimeImmutable $sentAt): static
    {
        $this->sentAt = $sentAt;

        return $this;
    }

    public function getStatus(): NotificationStatus
    {
        return $this->status;
    }

    public function markQueued(): static
    {
        return $this->transitionToStatus(NotificationStatus::QUEUED);
    }

    public function markProcessing(): static
    {
        return $this->transitionToStatus(NotificationStatus::PROCESSING);
    }

    public function markSent(): static
    {
        return $this->transitionToStatus(NotificationStatus::SENT);
    }

    public function markFailed(): static
    {
        return $this->transitionToStatus(NotificationStatus::FAILED);
    }

    public function transitionToStatus(NotificationStatus $status): static
    {
        if (!$this->status->canTransitionTo($status)) {
            throw new LogicException(sprintf(
                "Cannot transition recipient from %s to %s",
                $this->status->value,
                $status->value,
            ));
        }

        $this->status = $status;

        return $this;
    }

    public function getNotification(): Notification
    {
        return $this->notification;
    }

    public function attachToNotification(Notification $notification): static
    {
        $this->notification = $notification;

        return $this;
    }
}
