<?php

declare(strict_types=1);

namespace Nofi\Notification;

use Nofi\Dto\SendNotificationDto;
use InvalidArgumentException;
use Override;

final readonly class PushNotificationPayload implements NotificationPayload
{
    public function __construct(
        public string $title,
        public string $message,
        public ?string $icon = null,
        public array $data = [],
    ) {}

    public static function fromDto(SendNotificationDto $dto): self
    {
        if ($dto->title === null || $dto->message === null) {
            throw new InvalidArgumentException("Push payload is incomplete.");
        }

        return new self(
            $dto->title,
            $dto->message,
            $dto->icon,
            $dto->data,
        );
    }

    #[Override]
    public function toPayloadData(): array
    {
        return [
            "title" => $this->title,
            "message" => $this->message,
            "icon" => $this->icon,
            "data" => $this->data,
        ];
    }
}
