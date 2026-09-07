<?php

declare(strict_types=1);

namespace Nofi\Tests\Application;

use Nofi\Entity\Notification;
use Nofi\Notification\NotificationStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;

/**
 * The access rules that were the original defect: every endpoint was reachable
 * without a token, and every user could read every notification.
 */
#[TestDox("Notification API access control")]
final class NotificationAccessTest extends ApiTestCase
{
    private const string COLLECTION = '/api/v1/notifications';

    #[Test]
    public function theCollectionRejectsAnUnauthenticatedCaller(): void
    {
        $this->request('GET', self::COLLECTION);
        $this->assertResponseStatus(Response::HTTP_UNAUTHORIZED);
    }

    #[Test]
    public function anItemRejectsAnUnauthenticatedCaller(): void
    {
        $this->request('GET', self::COLLECTION . '/01a06341-0000-7000-8000-000000000000');
        $this->assertResponseStatus(Response::HTTP_UNAUTHORIZED);
    }

    #[Test]
    public function sendingRejectsAnUnauthenticatedCaller(): void
    {
        $this->request('POST', '/api/v1/notifications/send', null, $this->emailPayload());
        $this->assertResponseStatus(Response::HTTP_UNAUTHORIZED);
    }

    #[Test]
    public function theDocsRejectAnUnauthenticatedCaller(): void
    {
        $this->client->request('GET', '/api/docs');
        $this->assertResponseStatus(Response::HTTP_UNAUTHORIZED);
    }

    #[Test]
    public function theCollectionOnlyContainsNotificationsTheCallerCreated(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');

        $this->request('POST', '/api/v1/notifications/send', $this->tokenFor($alice), $this->emailPayload());
        $this->assertResponseStatus(Response::HTTP_ACCEPTED);

        $this->request('GET', self::COLLECTION, $this->tokenFor($alice));
        $this->assertResponseStatus(Response::HTTP_OK);
        self::assertSame(1, $this->jsonResponse()['totalItems']);

        $this->request('GET', self::COLLECTION, $this->tokenFor($bob));
        $this->assertResponseStatus(Response::HTTP_OK);
        self::assertSame(0, $this->jsonResponse()['totalItems']);
    }

    #[Test]
    public function readingANotificationAnswersWithItsFieldsAndNotOnlyItsIri(): void
    {
        $alice = $this->createUser('alice');
        $this->request('POST', '/api/v1/notifications/send', $this->tokenFor($alice), $this->emailPayload());

        $this->request('GET', self::COLLECTION, $this->tokenFor($alice));
        $item = $this->jsonResponse()['member'][0];

        // Serialization is filtered by group, and a group nobody annotates
        // filters everything: this used to answer with @id and @type alone,
        // which every status code assertion in this file happily passed.
        self::assertSame('email', $item['channel']);
        self::assertSame('queued', $item['status']);
        self::assertSame('Deployment finished', $item['payload']['subject']);
        self::assertNotEmpty($item['createdAt']);

        // Null is left out of the response entirely, so an unscheduled send
        // has no scheduledAt key at all rather than a null one.
        self::assertArrayNotHasKey('scheduledAt', $item);

        $this->request('GET', '/api/v1/notifications/' . $item['id'], $this->tokenFor($alice));
        $this->assertResponseStatus(Response::HTTP_OK);
        self::assertSame($item['id'], $this->jsonResponse()['id']);
        self::assertSame('queued', $this->jsonResponse()['status']);
    }

    #[Test]
    public function readingANotificationShowsEveryTargetItWasSentTo(): void
    {
        $alice = $this->createUser('alice');
        $this->request('POST', '/api/v1/notifications/send', $this->tokenFor($alice), [
            ...$this->emailPayload(),
            'recipients' => ['a@example.com', 'b@example.com'],
        ]);

        // The targets are the half of the request that payload does not carry,
        // and each one keeps its own status, which is what tells a caller
        // whether a partly failed send reached them.
        self::assertSame(
            [
                ['recipient' => 'a@example.com', 'status' => 'queued', 'sentAt' => null],
                ['recipient' => 'b@example.com', 'status' => 'queued', 'sentAt' => null],
            ],
            $this->jsonResponse()['recipients'],
        );
    }

    #[Test]
    public function aScheduledSendReportsWhenItIsDue(): void
    {
        $alice = $this->createUser('alice');
        $this->request('POST', '/api/v1/notifications/send', $this->tokenFor($alice), [
            ...$this->emailPayload(),
            'scheduledAt' => '2026-12-01T10:00:00+00:00',
        ]);

        $this->request('GET', self::COLLECTION, $this->tokenFor($alice));

        self::assertSame(
            '2026-12-01T10:00:00+00:00',
            $this->jsonResponse()['member'][0]['scheduledAt'],
        );
    }

    #[Test]
    public function theCollectionIsPagedAndTheCallerMayChooseThePageSize(): void
    {
        $alice = $this->createUser('alice');
        $token = $this->tokenFor($alice);
        for ($i = 0; $i < 3; ++$i) {
            $this->request('POST', '/api/v1/notifications/send', $token, $this->emailPayload());
        }

        // itemsPerPage is discarded unless the client is allowed to send it,
        // which is off by default: without that setting every request here
        // would answer with all three and no next link.
        $this->request('GET', self::COLLECTION . '?itemsPerPage=2', $token);
        $first = $this->jsonResponse();
        self::assertSame(3, $first['totalItems']);
        self::assertCount(2, $first['member']);
        self::assertSame('/api/v1/notifications?itemsPerPage=2&page=2', $first['view']['next']);

        $this->request('GET', self::COLLECTION . '?itemsPerPage=2&page=2', $token);
        $second = $this->jsonResponse();
        self::assertCount(1, $second['member']);

        // A page that repeats what the previous one showed would still count
        // as three items across two responses, so compare the ids.
        $ids = [
            ...array_column($first['member'], 'id'),
            ...array_column($second['member'], 'id'),
        ];
        self::assertCount(3, array_unique($ids));
    }

    #[Test]
    public function anotherUsersNotificationIsNotReadable(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');

        $this->request('POST', '/api/v1/notifications/send', $this->tokenFor($alice), $this->emailPayload());
        $id = $this->jsonResponse()['@id'];

        $this->request('GET', $id, $this->tokenFor($alice));
        $this->assertResponseStatus(Response::HTTP_OK);

        // 404 rather than 403: bob must not learn that the id exists.
        $this->request('GET', $id, $this->tokenFor($bob));
        $this->assertResponseStatus(Response::HTTP_NOT_FOUND);
    }

    #[Test]
    public function anAdministratorSeesEveryNotification(): void
    {
        $alice = $this->createUser('alice');
        $admin = $this->createUser('admin', ['ROLE_ADMIN']);

        $this->request('POST', '/api/v1/notifications/send', $this->tokenFor($alice), $this->emailPayload());

        $this->request('GET', self::COLLECTION, $this->tokenFor($admin));
        $this->assertResponseStatus(Response::HTTP_OK);
        self::assertSame(1, $this->jsonResponse()['totalItems']);
    }

    #[Test]
    public function aNotificationCannotBePatched(): void
    {
        $alice = $this->createUser('alice');
        $this->request('POST', '/api/v1/notifications/send', $this->tokenFor($alice), $this->emailPayload());
        $id = $this->jsonResponse()['@id'];

        // The status belongs to the system: the worker moves it while
        // delivering, and cancelling ends it. There is nothing here for a
        // caller to set, so the operation does not exist — asserted so it
        // cannot creep back in unnoticed.
        $this->client->request(
            'PATCH',
            $id,
            server: [
                'CONTENT_TYPE' => 'application/merge-patch+json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($alice),
            ],
            content: json_encode(['status' => 'delivered'], JSON_THROW_ON_ERROR),
        );

        $this->assertResponseStatus(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    #[Test]
    public function deletingRequiresAnAdministrator(): void
    {
        $alice = $this->createUser('alice');

        $this->request('POST', '/api/v1/notifications/send', $this->tokenFor($alice), $this->emailPayload());
        $id = $this->jsonResponse()['@id'];

        // Even the owner may not delete: the operation requires ROLE_ADMIN.
        $this->request('DELETE', $id, $this->tokenFor($alice));
        $this->assertResponseStatus(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function anAdministratorMayDelete(): void
    {
        $alice = $this->createUser('alice');
        $admin = $this->createUser('admin', ['ROLE_ADMIN']);

        $this->request('POST', '/api/v1/notifications/send', $this->tokenFor($alice), $this->emailPayload());
        $id = $this->jsonResponse()['@id'];

        $this->request('DELETE', $id, $this->tokenFor($admin));
        $this->assertResponseStatus(Response::HTTP_NO_CONTENT);
    }

    #[Test]
    public function deletingANotificationThatWasAlreadySentIsRefused(): void
    {
        $alice = $this->createUser('alice');
        $admin = $this->createUser('admin', ['ROLE_ADMIN']);

        $this->request('POST', '/api/v1/notifications/send', $this->tokenFor($alice), $this->emailPayload());
        $id = $this->jsonResponse()['@id'];

        // The handler ignores a notification that already went out, so a 204
        // here would promise a deletion that never happens.
        $notification = self::entityManager()->find(Notification::class, basename($id));
        $notification->markProcessing()->transitionToStatus(NotificationStatus::SENT);
        self::entityManager()->flush();

        $this->request('DELETE', $id, $this->tokenFor($admin));
        $this->assertResponseStatus(Response::HTTP_CONFLICT);
        self::assertStringContainsString('cannot be deleted', $this->jsonResponse()['detail']);

        self::entityManager()->clear();
        self::assertNotNull(self::entityManager()->find(Notification::class, basename($id)));
    }

    /**
     * @return array<string, mixed>
     */
    private function emailPayload(): array
    {
        return [
            'channel' => 'email',
            'sender' => 'noreply@example.com',
            'subject' => 'Deployment finished',
            'message' => '<p>Build 42 is live</p>',
            'recipients' => ['ops@example.com'],
        ];
    }
}
