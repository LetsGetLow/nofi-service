<?php

declare(strict_types=1);

namespace Nofi\Tests\Integration;

use DateTimeImmutable;
use Nofi\Entity\Notification;
use Nofi\Entity\User;
use Nofi\Repository\NotificationRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The ownership filter runs as a real query here. The unit tests mock the
 * repository, so nothing else proves the criteria actually restrict rows.
 */
#[TestDox("NotificationRepository ownership scoping")]
final class NotificationRepositoryTest extends IntegrationTestCase
{
    #[Test]
    public function findOwnedByReturnsOnlyTheOwnersNotifications(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $this->notificationFor($alice, 'a-1');
        $this->notificationFor($alice, 'a-2');
        $this->notificationFor($bob, 'b-1');

        $repository = self::service(NotificationRepository::class);

        // Same timestamp for both, so this also pins the id tiebreaker.
        self::assertSame(['a-2', 'a-1'], array_map(
            static fn (Notification $n): string => $n->getId(),
            $repository->findOwnedBy($alice),
        ));
        self::assertSame(['b-1'], array_map(
            static fn (Notification $n): string => $n->getId(),
            $repository->findOwnedBy($bob),
        ));
    }

    #[Test]
    public function aNullOwnerIsUnrestrictedForAdministrators(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $this->notificationFor($alice, 'a-1');
        $this->notificationFor($bob, 'b-1');

        self::assertCount(2, self::service(NotificationRepository::class)->findOwnedBy(null));
    }

    #[Test]
    public function findOwnedByOrdersNewestFirst(): void
    {
        $alice = $this->createUser('alice');
        $this->notificationFor($alice, 'older', new DateTimeImmutable('2026-01-01 10:00:00'));
        $this->notificationFor($alice, 'newer', new DateTimeImmutable('2026-06-01 10:00:00'));

        $ids = array_map(
            static fn (Notification $n): string => $n->getId(),
            self::service(NotificationRepository::class)->findOwnedBy($alice),
        );

        self::assertSame(['newer', 'older'], $ids);
    }

    #[Test]
    public function findOneOwnedByHidesAnotherUsersNotification(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $this->notificationFor($alice, 'a-1');

        $repository = self::service(NotificationRepository::class);

        self::assertNotNull($repository->findOneOwnedBy('a-1', $alice));
        self::assertNull($repository->findOneOwnedBy('a-1', $bob));
        self::assertNotNull($repository->findOneOwnedBy('a-1', null));
    }

    #[Test]
    public function findOwnedBySupportsPaging(): void
    {
        $alice = $this->createUser('alice');
        foreach (range(1, 5) as $i) {
            $this->notificationFor($alice, 'n-' . $i, new DateTimeImmutable('2026-01-0' . $i . ' 10:00:00'));
        }

        $repository = self::service(NotificationRepository::class);

        self::assertCount(2, $repository->findOwnedBy($alice, 2, 0));
        self::assertCount(1, $repository->findOwnedBy($alice, 2, 4));
        self::assertSame(5, $repository->countOwnedBy($alice));
        self::assertSame(0, $repository->countOwnedBy($this->createUser('carol')));
    }

    #[Test]
    public function orderingIsStableWhenTimestampsCollide(): void
    {
        $alice = $this->createUser('alice');
        $sameSecond = new DateTimeImmutable('2026-05-05 12:00:00');
        foreach (['n-1', 'n-2', 'n-3', 'n-4'] as $id) {
            $this->notificationFor($alice, $id, $sameSecond);
        }

        $repository = self::service(NotificationRepository::class);
        $ids = static fn (array $rows): array => array_map(
            static fn (Notification $n): string => $n->getId(),
            $rows,
        );

        // Paging must not skip or repeat a row just because created_at ties.
        $page1 = $ids($repository->findOwnedBy($alice, 2, 0));
        $page2 = $ids($repository->findOwnedBy($alice, 2, 2));

        self::assertSame(['n-4', 'n-3'], $page1);
        self::assertSame(['n-2', 'n-1'], $page2);
        self::assertSame($ids($repository->findOwnedBy($alice)), [...$page1, ...$page2]);
    }

    private function notificationFor(User $owner, string $id, ?DateTimeImmutable $createdAt = null): void
    {
        $notification = new Notification($id)
            ->assignCreatedBy($owner)
            ->recordCreatedAt($createdAt ?? new DateTimeImmutable())
            ->markQueued();

        self::entityManager()->persist($notification);
        self::entityManager()->flush();
    }
}
