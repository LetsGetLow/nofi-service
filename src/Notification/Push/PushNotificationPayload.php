<?php

declare(strict_types=1);

namespace Nofi\Notification\Push;

use Nofi\Notification\NotificationPayload;
use Nofi\Notification\NotificationChannel;
use Override;

final readonly class PushNotificationPayload implements NotificationPayload
{
    public function __construct(
        public string $title,
        public string $message,
        public ?string $icon = null,
        public array $data = [],
    ) {
    }

    #[Override]
    public function channel(): NotificationChannel
    {
        return NotificationChannel::PUSH;
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
