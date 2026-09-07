<?php

declare(strict_types=1);

namespace Nofi\Notification;

use function preg_match;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * A push recipient is a device token unless it is written as a topic. FCM
 * addresses a topic as "/topics/<name>" and delivers to every device that
 * subscribed to it, which is how a whole audience is reached with a single
 * recipient and without knowing any device token.
 */
final readonly class PushTopic
{
    public const string PREFIX = "/topics/";

    /**
     * FCM's own rule for a topic name. The hyphen sits last so it cannot be
     * read as a range.
     */
    public const string NAME_PATTERN = '/^[a-zA-Z0-9_.~%-]++$/';

    public static function isTopic(string $recipient): bool
    {
        return str_starts_with($recipient, self::PREFIX);
    }

    /**
     * The name FCM expects, which is the recipient without the prefix.
     */
    public static function nameOf(string $recipient): string
    {
        return substr($recipient, strlen(self::PREFIX));
    }

    public static function isValidName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }
}
