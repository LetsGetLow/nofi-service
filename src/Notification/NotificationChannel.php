<?php

declare(strict_types=1);

namespace Nofi\Notification;

use Nofi\Dto\SendNotificationDto;
use Nofi\Message\NotificationMessage;
use Nofi\Message\SendEmailNotification;
use Nofi\Message\SendPushNotification;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The channels a notification can go out on, and everything that differs
 * between them.
 *
 * The behaviour lives here, on the type, the way NotificationStatus holds its
 * own state machine. It used to be spread over four match statements in four
 * layers — payload construction in SendNotificationService, message
 * construction in NotificationMessageFactory, payload serialisation in
 * NotificationRecorder, and validation in SendNotificationDto — so a new
 * channel had to be added to all four. Three of them raised
 * UnhandledMatchError if it was not, and the fourth, the validation one,
 * carried a "default => null" arm and would have accepted the request with no
 * field checks at all.
 *
 * Delivery is deliberately not here: it needs services, so it is resolved
 * through NotificationDeliveries instead. Adding a channel means a case below,
 * a NotificationPayload, a NotificationMessage, a NotificationDelivery, and
 * the validation method the case names — no edits anywhere else.
 *
 * Every match below is exhaustive on purpose. Leaving a case out is a
 * compile-time-visible failure rather than a silent one.
 */
enum NotificationChannel: string
{
    case EMAIL = "email";
    case PUSH = "push";

    public function payloadFrom(SendNotificationDto $dto): NotificationPayload
    {
        return match ($this) {
            self::EMAIL => EmailNotificationPayload::fromDto($dto),
            self::PUSH => PushNotificationPayload::fromDto($dto),
        };
    }

    /**
     * The message class is what Messenger routes on, so each channel keeps its
     * own. They are handled by one handler, which is registered against the
     * NotificationMessage interface they share.
     */
    public function newMessage(
        string $notificationId,
        SendNotificationDto $dto,
        string $userId,
    ): NotificationMessage {
        return match ($this) {
            self::EMAIL => new SendEmailNotification($notificationId, $dto, $userId),
            self::PUSH => new SendPushNotification($notificationId, $dto, $userId),
        };
    }

    /**
     * The rules that only apply on this channel, including the ones that
     * reject another channel's fields.
     */
    public function validate(SendNotificationDto $dto, ExecutionContextInterface $context): void
    {
        match ($this) {
            self::EMAIL => $dto->validateEmailFields($context),
            self::PUSH => $dto->validatePushFields($context),
        };
    }
}
