<?php

declare(strict_types=1);

namespace Nofi\Message;

use Nofi\Dto\SendNotificationDto;

/**
 * What NotificationRecorder needs to record a send.
 *
 * All three accessors are declared here because the recorder calls all three.
 * It used to declare only getNotificationId() while record() also called
 * getNotificationDto() and getUserId(), which type-checked purely because both
 * implementations happened to have them: a third implementation honouring the
 * published contract would have fatalled at runtime instead.
 */
interface NotificationMessage
{
    public function getNotificationId(): string;

    public function getNotificationDto(): SendNotificationDto;

    public function getUserId(): string;
}
