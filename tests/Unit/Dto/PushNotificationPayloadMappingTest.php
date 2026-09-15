<?php

declare(strict_types=1);

namespace Nofi\Tests\Unit\Dto;

use Nofi\Dto\NotificationRequestMapper;
use Nofi\Dto\SendNotificationDto;
use Nofi\Notification\Push\PushNotificationPayload;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[TestDox("PushNotificationPayload mapping")]
final class PushNotificationPayloadMappingTest extends TestCase
{
    #[Test]
    public function mappingBuildsAnExplicitPayloadObject(): void
    {
        $dto = new SendNotificationDto();
        $dto->title = "Welcome";
        $dto->message = "Hi there";
        $dto->icon = "https://example.com/icon.png";
        $dto->data = ["url" => "https://example.com"];

        $payload = new NotificationRequestMapper()->pushPayload($dto);

        self::assertInstanceOf(PushNotificationPayload::class, $payload);
        self::assertSame("Welcome", $payload->title);
        self::assertSame("Hi there", $payload->message);
        self::assertSame("https://example.com/icon.png", $payload->icon);
        self::assertSame(["url" => "https://example.com"], $payload->data);
    }

    #[Test]
    public function mappingRejectsIncompletePayloads(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new NotificationRequestMapper()->pushPayload(new SendNotificationDto());
    }

    #[Test]
    public function mappingHandlesOptionalIcon(): void
    {
        $dto = new SendNotificationDto();
        $dto->title = "Welcome";
        $dto->message = "Hi there";

        $payload = new NotificationRequestMapper()->pushPayload($dto);

        self::assertNull($payload->icon);
        self::assertSame([], $payload->data);
    }
}
