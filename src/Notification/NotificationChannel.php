<?php

declare(strict_types=1);

namespace Nofi\Notification;

enum NotificationChannel: string
{
    case EMAIL = "email";
    case PUSH = "push";
}
