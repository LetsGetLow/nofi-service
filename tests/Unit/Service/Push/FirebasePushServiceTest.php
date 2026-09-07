<?php

declare(strict_types=1);

namespace Nofi\Tests\Unit\Service\Push;

use Nofi\Entity\Notification;
use Nofi\Service\Push\FirebasePushService;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\Message;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[TestDox("FirebasePushService delivery")]
final class FirebasePushServiceTest extends TestCase
{
    #[Test]
    public function sendDeliversOneMessagePerDeviceTokenWithTheSameBody(): void
    {
        $sent = [];
        $messaging = $this->messagingRecording($sent);

        $results = new FirebasePushService($messaging)
            ->send(new Notification('notif-1'), ['token-a', 'token-b'], 'Title', 'Body');

        self::assertCount(2, $sent);
        self::assertSame(['token-a', 'token-b'], array_column($sent, 'token'));
        self::assertSame(['Body', 'Body'], array_column($sent, 'body'));
        self::assertSame(['Title', 'Title'], array_column($sent, 'title'));
        self::assertSame([true, true], array_column($results, 'success'));
    }

    #[Test]
    public function sendSucceedsWhenNoIconIsProvided(): void
    {
        $sent = [];
        $messaging = $this->messagingRecording($sent);

        $results = new FirebasePushService($messaging)
            ->send(new Notification('notif-1'), ['token-a'], 'Title', 'Body', null);

        self::assertTrue($results[0]['success']);
        self::assertNull($results[0]['error']);
        self::assertNull($sent[0]['image']);
    }

    #[Test]
    public function sendPassesTheIconThroughAsTheNotificationImage(): void
    {
        $sent = [];
        $messaging = $this->messagingRecording($sent);

        new FirebasePushService($messaging)->send(
            new Notification('notif-1'),
            ['token-a'],
            'Title',
            'Body',
            'https://example.com/icon.png',
        );

        self::assertSame('https://example.com/icon.png', $sent[0]['image']);
    }

    #[Test]
    public function sendReportsPerTokenFailuresWithoutAbortingTheRest(): void
    {
        $messaging = $this->createStub(Messaging::class);
        $messaging->method('send')->willReturnCallback(
            static function (Message $message): array {
                $target = $message->jsonSerialize()['token'] ?? null;
                if ($target === 'token-broken') {
                    throw new RuntimeException('token is not registered');
                }

                return [];
            },
        );

        $results = new FirebasePushService($messaging)->send(
            new Notification('notif-1'),
            ['token-broken', 'token-ok'],
            'Title',
            'Body',
        );

        self::assertFalse($results[0]['success']);
        self::assertSame('token is not registered', $results[0]['error']);
        self::assertTrue($results[1]['success']);
    }

    #[Test]
    public function aRecipientWrittenAsATopicIsSentToThatTopicWithoutThePrefix(): void
    {
        $sent = [];
        $messaging = $this->messagingRecording($sent);

        $results = new FirebasePushService($messaging)->send(
            new Notification('notif-1'),
            ['/topics/test_notif_all', 'token-a'],
            'Title',
            'Body',
        );

        // Kreait strips a singular "/topic/" only, so the prefix has to be
        // gone before withTopic() sees it or the topic becomes "topics/...".
        self::assertSame('test_notif_all', $sent[0]['topic']);
        self::assertNull($sent[0]['token']);

        // A token in the same request is still addressed as a token.
        self::assertSame('token-a', $sent[1]['token']);
        self::assertNull($sent[1]['topic']);

        self::assertSame([true, true], array_column($results, 'success'));
    }

    /**
     * @param list<array{token: mixed, topic: mixed, data: mixed, title: mixed, body: mixed, image: mixed}> $sent
     */
    private function messagingRecording(array &$sent): Messaging
    {
        $messaging = $this->createStub(Messaging::class);
        $messaging->method('send')->willReturnCallback(
            static function (Message $message) use (&$sent): array {
                $payload = $message->jsonSerialize();
                $sent[] = [
                    'token' => $payload['token'] ?? null,
                    'topic' => $payload['topic'] ?? null,
                    'data' => $payload['data'] ?? null,
                    'title' => $payload['notification']['title'] ?? null,
                    'body' => $payload['notification']['body'] ?? null,
                    'image' => $payload['notification']['image'] ?? null,
                ];

                return [];
            },
        );

        return $messaging;
    }

    #[Test]
    public function sendEncodesNonStringDataValuesSoFirebaseAcceptsThem(): void
    {
        $sent = [];
        $messaging = $this->messagingRecording($sent);

        $results = new FirebasePushService($messaging)->send(
            new Notification('notif-1'),
            ['token-a'],
            'Title',
            'Body',
            null,
            [
                'deepLink' => '/orders/42',
                'orderId' => 42,
                'urgent' => true,
                'ref' => null,
                'meta' => ['attempt' => 1],
            ],
        );

        self::assertTrue($results[0]['success']);
        self::assertSame([
            'deepLink' => '/orders/42',
            'orderId' => '42',
            'urgent' => 'true',
            'ref' => 'null',
            'meta' => '{"attempt":1}',
        ], $sent[0]['data']);
    }
}
