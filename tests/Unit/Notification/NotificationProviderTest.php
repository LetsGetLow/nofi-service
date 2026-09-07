<?php

declare(strict_types=1);

namespace Nofi\Tests\Unit\Notification;

use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\Pagination\PaginatorInterface;
use DateTimeImmutable;
use Nofi\ApiResource\NotificationResource;
use Nofi\Entity\Notification;
use Nofi\Entity\User;
use Nofi\Notification\NotificationChannel;
use Nofi\Notification\NotificationStatus;
use Nofi\Notification\State\NotificationProvider;
use Nofi\Repository\NotificationRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[TestDox("NotificationProvider mapping")]
final class NotificationProviderTest extends TestCase
{
    #[Test]
    public function provideMapsNotificationEntitiesToResources(): void
    {
        $user = new User()->setUsername('owner');
        $notification = new Notification('notif-1');
        $notification->assignPayload(['sender' => 'noreply@example.com']);
        $notification->markQueued()->markProcessing()->markSent();
        $notification->assignChannel(NotificationChannel::EMAIL);
        $notification->recordCreatedAt(new DateTimeImmutable('2026-08-10 12:00:00'));
        $notification->schedule(new DateTimeImmutable('2026-08-10 13:00:00'));

        $repository = $this->createMock(NotificationRepository::class);
        $repository->expects(self::once())
            ->method('findOneOwnedBy')
            ->with('notif-1', $user)
            ->willReturn($notification);

        $provider = new NotificationProvider($repository, $this->securityFor($user), $this->pagination(false));
        $resource = $provider->provide(new Get(), ['id' => 'notif-1']);

        self::assertInstanceOf(NotificationResource::class, $resource);
        self::assertSame('notif-1', $resource->id);
        self::assertSame(['sender' => 'noreply@example.com'], $resource->payload);
        self::assertSame(NotificationStatus::SENT, $resource->status);
        self::assertSame(NotificationChannel::EMAIL, $resource->channel);
        self::assertEquals(new DateTimeImmutable('2026-08-10 12:00:00'), $resource->createdAt);
        self::assertEquals(new DateTimeImmutable('2026-08-10 13:00:00'), $resource->scheduledAt);
    }

    #[Test]
    public function provideScopesTheCollectionToTheCurrentUser(): void
    {
        $user = new User()->setUsername('owner');

        $repository = $this->createMock(NotificationRepository::class);
        $repository->expects(self::once())
            ->method('findOwnedBy')
            ->with($user)
            ->willReturn([]);

        $provider = new NotificationProvider($repository, $this->securityFor($user), $this->pagination(false));

        self::assertSame([], $provider->provide(new GetCollection()));
    }

    #[Test]
    public function provideLetsAdministratorsSeeEveryNotification(): void
    {
        $admin = new User()->setUsername('admin');

        $repository = $this->createMock(NotificationRepository::class);
        $repository->expects(self::once())
            ->method('findOwnedBy')
            ->with(null)
            ->willReturn([]);

        $provider = new NotificationProvider($repository, $this->securityFor($admin, isAdmin: true), $this->pagination(false));

        self::assertSame([], $provider->provide(new GetCollection()));
    }

    #[Test]
    public function provideHidesNotificationsOwnedBySomeoneElse(): void
    {
        $user = new User()->setUsername('owner');

        $repository = $this->createMock(NotificationRepository::class);
        $repository->expects(self::once())
            ->method('findOneOwnedBy')
            ->with('someone-elses-id', $user)
            ->willReturn(null);

        $provider = new NotificationProvider($repository, $this->securityFor($user), $this->pagination(false));

        self::assertNull($provider->provide(new Get(), ['id' => 'someone-elses-id']));
    }

    #[Test]
    public function provideRejectsAnonymousCallers(): void
    {
        $repository = $this->createMock(NotificationRepository::class);
        $repository->expects(self::never())->method('findOwnedBy');

        $provider = new NotificationProvider($repository, $this->securityFor(null), $this->pagination(false));

        $this->expectException(AccessDeniedException::class);
        $provider->provide(new GetCollection());
    }

    private function securityFor(?User $user, bool $isAdmin = false): Security
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $security->method('isGranted')->willReturn($isAdmin);

        return $security;
    }

    #[Test]
    public function provideReturnsAPaginatorScopedToTheCurrentUser(): void
    {
        $user = new User()->setUsername('owner');

        $repository = $this->createMock(NotificationRepository::class);
        $repository->expects(self::once())
            ->method('findOwnedBy')
            ->with($user, 30, 30)
            ->willReturn([]);
        $repository->expects(self::once())
            ->method('countOwnedBy')
            ->with($user)
            ->willReturn(97);

        $provider = new NotificationProvider(
            $repository,
            $this->securityFor($user),
            $this->pagination(true),
        );

        $result = $provider->provide(new GetCollection(), [], ['filters' => ['page' => 2]]);

        self::assertInstanceOf(PaginatorInterface::class, $result);
        self::assertSame(2.0, $result->getCurrentPage());
        self::assertSame(30.0, $result->getItemsPerPage());
        self::assertSame(97.0, $result->getTotalItems());
    }

    private function pagination(bool $enabled): Pagination
    {
        return new Pagination(['enabled' => $enabled, 'items_per_page' => 30]);
    }
}
