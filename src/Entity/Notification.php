<?php

declare(strict_types=1);

namespace Nofi\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nofi\Notification\NotificationChannel;
use Nofi\Notification\NotificationStatus;
use Nofi\Repository\NotificationRepository;
use LogicException;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: NotificationRepository::class)]
class Notification
{
    public function __construct(?string $id = null)
    {
        $this->id = $id ?? Uuid::v7()->toRfc4122();
        $this->recipients = new ArrayCollection();
    }

    #[ORM\Id]
    #[ORM\Column(type: Types::GUID, unique: true)]
    private string $id;

    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    #[ORM\Column(enumType: NotificationStatus::class)]
    private NotificationStatus $status = NotificationStatus::CREATED;

    #[ORM\Column(enumType: NotificationChannel::class)]
    private NotificationChannel $channel = NotificationChannel::EMAIL;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $scheduledAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[
        ORM\JoinColumn(
            name: "created_by",
            referencedColumnName: "id",
            nullable: false,
            onDelete: "CASCADE",
        ),
    ]
    private ?User $createdBy = null;

    #[
        ORM\OneToMany(
            targetEntity: NotificationRecipient::class,
            mappedBy: "notification",
            cascade: ["persist", "remove"],
        ),
    ]
    private Collection $recipients;

    public function getId(): string
    {
        return $this->id;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }
    public function assignPayload(array $payload): static
    {
        $this->payload = $payload;

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

    /**
     * Stops a send that has not gone out yet. The recipients follow, because a
     * cancelled notification whose targets still read "queued" describes a
     * state that no longer exists; a recipient already contacted keeps what it
     * has.
     */
    public function markCancelled(): static
    {
        $this->transitionToStatus(NotificationStatus::CANCELLED);

        foreach ($this->recipients as $recipient) {
            if ($recipient->getStatus()->isWaiting()) {
                $recipient->transitionToStatus(NotificationStatus::CANCELLED);
            }
        }

        return $this;
    }

    public function markFailed(): static
    {
        return $this->transitionToStatus(NotificationStatus::FAILED);
    }

    public function transitionToStatus(NotificationStatus $status): static
    {
        if (!$this->status->canTransitionTo($status)) {
            throw new LogicException(sprintf(
                "Cannot transition notification from %s to %s",
                $this->status->value,
                $status->value,
            ));
        }

        $this->status = $status;

        return $this;
    }

    public function getChannel(): NotificationChannel
    {
        return $this->channel;
    }

    public function assignChannel(NotificationChannel $channel): static
    {
        $this->channel = $channel;

        return $this;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function recordCreatedAt(DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getScheduledAt(): ?DateTimeImmutable
    {
        return $this->scheduledAt;
    }

    public function schedule(?DateTimeImmutable $scheduledAt): static
    {
        $this->scheduledAt = $scheduledAt;

        return $this;
    }

    /**
     * The property is nullable because the constructor cannot know the creator
     * — assignCreatedBy() runs before the notification is persisted, and the
     * column is NOT NULL. Returning it as a plain User was a promise the class
     * could not keep: before assignment it returned null through a non-nullable
     * return type, which is a TypeError at the call site rather than here.
     */
    public function getCreatedBy(): User
    {
        return $this->createdBy ?? throw new LogicException(sprintf(
            "Notification %s has no creator yet; assignCreatedBy() has not run.",
            $this->id,
        ));
    }

    public function assignCreatedBy(User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function addRecipient(string $recipient): static
    {
        $r = new NotificationRecipient()
            ->attachToNotification($this)
            ->assignRecipient($recipient);
        $this->recipients->add($r);

        return $this;
    }

    /**
     * @return Collection<int, NotificationRecipient>
     */
    public function getRecipients(): Collection
    {
        return $this->recipients;
    }
}
