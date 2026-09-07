<?php

declare(strict_types=1);

namespace Nofi\Tests\Application;

use Nofi\Entity\Notification;
use Nofi\Notification\NotificationStatus;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\Message;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Throwable;

/**
 * The push channel end to end: a request goes in over HTTP, the queued message
 * is consumed the way a worker consumes it, and the assertions are made on the
 * message Firebase is handed.
 *
 * Only the Messaging contract is replaced — the last hop before Google's
 * servers — so the firewall, the validator, the state processor, the transport,
 * the handler, FirebasePushService and the recipient bookkeeping are all the
 * real ones. That is the part nothing else covers: the unit tests build
 * FirebasePushService by hand, so they cannot show that a request reaches FCM
 * with the payload it asked for.
 */
#[TestDox("Push delivery end to end")]
final class PushDeliveryTest extends ApiTestCase
{
    private const string SEND = '/api/v1/notifications/send';

    private string $token;

    /**
     * Every message handed to Firebase, as the wire payload FCM would receive.
     *
     * @var list<array<string, mixed>>
     */
    private array $received = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->tokenFor($this->createUser('alice'));
    }

    #[Test]
    public function aSentPushArrivesAtFirebaseWithTheTitleBodyAndTokenOfTheRequest(): void
    {
        $this->send([
            'channel' => 'push',
            'title' => 'Order 4711 shipped',
            'message' => 'Your parcel is on its way',
            'tokens' => ['device-token-1'],
        ]);
        $this->assertResponseStatus(Response::HTTP_ACCEPTED);

        // Nothing has been delivered yet: the request only queues.
        self::assertSame([], $this->received);

        $this->consumeQueue();

        self::assertCount(1, $this->received);
        self::assertSame('device-token-1', $this->received[0]['token']);
        self::assertSame('Order 4711 shipped', $this->received[0]['notification']['title']);
        self::assertSame('Your parcel is on its way', $this->received[0]['notification']['body']);
    }

    #[Test]
    public function everyDeviceTokenOfTheRequestGetsItsOwnMessage(): void
    {
        $this->send($this->push(['device-token-1', 'device-token-2', 'device-token-3']));
        $this->consumeQueue();

        self::assertSame(
            ['device-token-1', 'device-token-2', 'device-token-3'],
            array_column($this->received, 'token'),
        );
    }

    #[Test]
    public function theIconOfTheRequestArrivesAsTheNotificationImage(): void
    {
        $this->send([
            ...$this->push(['device-token-1']),
            'icon' => 'https://example.com/icon.png',
        ]);
        $this->consumeQueue();

        self::assertSame('https://example.com/icon.png', $this->received[0]['notification']['image']);
    }

    #[Test]
    public function theDataOfTheRequestArrivesAsStringsBecauseFcmCarriesNothingElse(): void
    {
        $this->send([
            ...$this->push(['device-token-1']),
            'data' => [
                'deepLink' => '/orders/4711',
                'orderId' => 4711,
                'urgent' => true,
                'ref' => null,
                'meta' => ['attempt' => 1],
            ],
        ]);
        $this->consumeQueue();

        // Sent as an int, a bool, a null and an object; a client decodes them
        // back. This is the claim the OpenAPI description makes about "data",
        // asserted on what actually leaves the application.
        self::assertSame([
            'deepLink' => '/orders/4711',
            'orderId' => '4711',
            'urgent' => 'true',
            'ref' => 'null',
            'meta' => '{"attempt":1}',
        ], $this->received[0]['data']);
    }

    #[Test]
    public function aTopicIsGivenByNameAndReachesFirebaseAsATopic(): void
    {
        $this->send([
            'channel' => 'push',
            'title' => 'Deployment finished',
            'message' => 'Build 42 is live',
            'topics' => ['test_notif_local_test_notification_v2'],
            'data' => ['probe' => 'true'],
        ]);
        $this->assertResponseStatus(Response::HTTP_ACCEPTED);

        $this->consumeQueue();

        // The caller writes the bare name; the prefix exists only inside the
        // application and must not reach FCM.
        self::assertSame('test_notif_local_test_notification_v2', $this->received[0]['topic']);
        self::assertArrayNotHasKey('token', $this->received[0]);
        self::assertSame('Deployment finished', $this->received[0]['notification']['title']);
        self::assertSame(['probe' => 'true'], $this->received[0]['data']);
    }

    #[Test]
    public function topicsAndTokensCanBeMixedInOneRequest(): void
    {
        $this->send([
            ...$this->push(['device-token-1']),
            'topics' => ['test_notif_all'],
        ]);
        $this->consumeQueue();

        self::assertSame('device-token-1', $this->received[0]['token']);
        self::assertSame('test_notif_all', $this->received[1]['topic']);

        // Both become recipient rows, so both are retried like any other.
        $recipients = [];
        foreach ($this->onlyNotification()->getRecipients() as $recipient) {
            $recipients[] = $recipient->getRecipient();
        }
        self::assertSame(['device-token-1', '/topics/test_notif_all'], $recipients);
    }

    #[Test]
    public function aPushWithOnlyTopicsNeedsNoTokensAtAll(): void
    {
        $this->send([
            'channel' => 'push',
            'title' => 'Deployment finished',
            'message' => 'Build 42 is live',
            'topics' => ['test_notif_all'],
        ]);

        $this->assertResponseStatus(Response::HTTP_ACCEPTED);
    }

    #[Test]
    public function aPushWithNeitherTokensNorTopicsIsRefused(): void
    {
        $this->send(['channel' => 'push', 'title' => 'T', 'message' => 'M']);

        $this->assertResponseStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString(
            'At least 1 token or topic is required',
            $this->firstViolation(),
        );
    }

    #[Test]
    public function aMalformedTopicIsRefusedAtRequestTimeRatherThanInAWorker(): void
    {
        $this->send([...$this->push([]), 'topics' => ['not a topic name']]);

        $this->assertResponseStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('is malformed', $this->firstViolation());

        // Nothing was queued, so no worker can fail on it later.
        self::assertSame([], $this->transport()->getSent());
    }

    #[Test]
    public function aTopicWrittenIntoTokensPointsAtTheTopicsField(): void
    {
        // The prefixed form is the application's internal marker. Accepting it
        // here as well would leave two undocumented ways to send to a topic.
        $this->send($this->push(['/topics/test_notif_all']));

        $this->assertResponseStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        // Read from the decoded body: the raw JSON escapes the quotes.
        self::assertStringContainsString('Use the "topics" field', $this->firstViolation());
    }

    #[Test]
    public function aDeliveredPushMarksTheNotificationAndItsRecipientsSent(): void
    {
        $this->send($this->push(['device-token-1', 'device-token-2']));
        $this->consumeQueue();

        $notification = $this->onlyNotification();
        self::assertSame(NotificationStatus::SENT, $notification->getStatus());

        foreach ($notification->getRecipients() as $recipient) {
            self::assertSame(NotificationStatus::SENT, $recipient->getStatus());
            self::assertNotNull($recipient->getSentAt());
        }
    }

    #[Test]
    public function aTokenFirebaseRejectsFailsOnlyThatRecipient(): void
    {
        $this->send($this->push(['device-token-1', 'unregistered-token']));

        $error = $this->consumeQueue(rejectedToken: 'unregistered-token');

        // The handler raises so Messenger retries, and the retry finds the
        // delivered token already marked sent.
        self::assertInstanceOf(HandlerFailedException::class, $error);
        self::assertStringContainsString('failed for 1 of 2 device tokens', $error->getMessage());

        $statuses = [];
        foreach ($this->onlyNotification()->getRecipients() as $recipient) {
            $statuses[$recipient->getRecipient()] = $recipient->getStatus();
        }
        self::assertSame(NotificationStatus::SENT, $statuses['device-token-1']);
        self::assertSame(NotificationStatus::FAILED, $statuses['unregistered-token']);
        self::assertSame(NotificationStatus::FAILED, $this->onlyNotification()->getStatus());
    }

    /**
     * Consumes the async transport the way a worker does: take what is queued,
     * hand it to the real bus as a received message, acknowledge it. Returns
     * the exception a handler raised, so a failing delivery can be asserted on
     * without aborting the test.
     */
    private function consumeQueue(?string $rejectedToken = null): ?Throwable
    {
        $this->recordWhatFirebaseReceives($rejectedToken);

        $transport = $this->transport();
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $raised = null;

        foreach ($transport->get() as $envelope) {
            try {
                $bus->dispatch($envelope->with(new ReceivedStamp('async')));
            } catch (Throwable $e) {
                $raised = $e;
            }

            $transport->ack($envelope);
        }

        return $raised;
    }

    /**
     * Replaces the Firebase client with one that records the message instead of
     * sending it. Installed from consumeQueue() and nowhere else: the container
     * refuses to replace a service it has already built, and FirebasePushService
     * builds this one the moment the handler runs.
     *
     * FCM answers per token, so a rejected token throws for that message only.
     */
    private function recordWhatFirebaseReceives(?string $rejectedToken): void
    {
        $messaging = $this->createStub(Messaging::class);
        $messaging->method('send')->willReturnCallback(
            function (Message|array $message, bool $validateOnly = false) use ($rejectedToken): array {
                $payload = $message->jsonSerialize();

                if ($rejectedToken !== null && ($payload['token'] ?? null) === $rejectedToken) {
                    throw new RuntimeException('The registration token is not registered');
                }

                $this->received[] = $payload;

                return [];
            },
        );

        self::getContainer()->set(Messaging::class, $messaging);
    }

    private function firstViolation(): string
    {
        return (string) $this->jsonResponse()['violations'][0]['message'];
    }

    private function transport(): InMemoryTransport
    {
        return self::getContainer()->get('messenger.transport.async');
    }

    /**
     * @param list<string> $deviceTokens
     *
     * @return array<string, mixed>
     */
    private function push(array $deviceTokens): array
    {
        return [
            'channel' => 'push',
            'title' => 'Deployment finished',
            'message' => 'Build 42 is live',
            'tokens' => $deviceTokens,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function send(array $payload): void
    {
        $this->request('POST', self::SEND, $this->token, $payload);
    }

    private function onlyNotification(): Notification
    {
        self::entityManager()->clear();
        $all = self::entityManager()->getRepository(Notification::class)->findAll();
        self::assertCount(1, $all);

        return $all[0];
    }
}
