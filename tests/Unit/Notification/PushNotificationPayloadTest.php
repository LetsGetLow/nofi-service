<?php

declare(strict_types=1);

namespace Nofi\Tests\Unit\Notification;

use Nofi\Dto\SendNotificationDto;
use Nofi\Notification\PushNotificationPayload;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[TestDox("PushNotificationPayload behavior")]
final class PushNotificationPayloadTest extends TestCase
{
    #[Test]
    public function fromDtoBuildsAnExplicitPayloadObject(): void
    {
        $dto = new SendNotificationDto();
        $dto->title = "Welcome";
        $dto->message = "Hi there";
        $dto->icon = "https://example.com/icon.png";
        $dto->data = ["url" => "https://example.com"];

        $payload = PushNotificationPayload::fromDto($dto);

        self::assertInstanceOf(PushNotificationPayload::class, $payload);
        self::assertSame("Welcome", $payload->title);
        self::assertSame("Hi there", $payload->message);
        self::assertSame("https://example.com/icon.png", $payload->icon);
        self::assertSame(["url" => "https://example.com"], $payload->data);
    }

    #[Test]
    public function fromDtoRejectsIncompletePayloads(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PushNotificationPayload::fromDto(new SendNotificationDto());
    }

    #[Test]
    public function fromDtoHandlesOptionalIcon(): void
    {
        $dto = new SendNotificationDto();
        $dto->title = "Welcome";
        $dto->message = "Hi there";

        $payload = PushNotificationPayload::fromDto($dto);

        self::assertNull($payload->icon);
        self::assertSame([], $payload->data);
    }
}
