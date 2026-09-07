<?php

declare(strict_types=1);

namespace Nofi\Service\Push;

use Nofi\Entity\Notification;
use Nofi\Notification\PushTopic;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as PushNotification;
use Override;
use Throwable;

readonly class FirebasePushService implements PushService
{
    public function __construct(
        private Messaging $messaging,
    ) {}

    #[Override]
    public function send(
        Notification $notification,
        array $deviceTokens,
        string $title,
        string $message,
        ?string $icon = null,
        array $data = [],
    ): array {
        $results = [];

        foreach ($deviceTokens as $token) {
            try {
                $cloudMessage = self::addressed(CloudMessage::new(), $token)
                    ->withData(self::encodeData($data))
                    ->withNotification(PushNotification::create($title, $message, $icon));

                $this->messaging->send($cloudMessage);

                $results[] = [
                    'token' => $token,
                    'success' => true,
                    'error' => null,
                ];
            } catch (Throwable $e) {
                $results[] = [
                    'token' => $token,
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * A recipient is a device token, or a topic when it is written as
     * "/topics/<name>". The prefix is stripped here rather than handed to
     * Kreait: its Topic::fromValue() only strips a singular "/topic/", so
     * "/topics/news" would be sent as the topic "topics/news".
     */
    private static function addressed(CloudMessage $message, string $recipient): CloudMessage
    {
        return PushTopic::isTopic($recipient)
            ? $message->withTopic(PushTopic::nameOf($recipient))
            : $message->withToken($recipient);
    }

    /**
     * FCM data payloads carry string values only, so anything else is JSON
     * encoded and a client gets the original type back by decoding it. Without
     * this, an integer, boolean, null or nested object raised a TypeError that
     * the loop above turned into an unexplained per-token failure, even though
     * the documented schema accepts arbitrary values.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, string>
     */
    private static function encodeData(array $data): array
    {
        return array_map(
            static fn (mixed $value): string => is_string($value)
                ? $value
                : json_encode($value, JSON_THROW_ON_ERROR),
            $data,
        );
    }
}
