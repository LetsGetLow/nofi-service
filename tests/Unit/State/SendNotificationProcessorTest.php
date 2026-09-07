<?php

declare(strict_types=1);

namespace Nofi\Tests\Unit\State;

use ApiPlatform\Metadata\Post;
use Doctrine\ORM\EntityManagerInterface;
use Nofi\Application\Notification\SendNotificationService;
use Nofi\Dto\SendNotificationDto;
use Nofi\Entity\User;
use Nofi\Notification\NotificationRecorder;
use Nofi\Notification\State\SendNotificationProcessor;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationCredentialsNotFoundException;

/**
 * The guards only. Sending itself is covered end to end by the application
 * tests, and SendNotificationService is final so it cannot be doubled here.
 */
#[TestDox("SendNotificationProcessor guards")]
final class SendNotificationProcessorTest extends TestCase
{
    #[Test]
    public function anUnauthenticatedCallerIsRefused(): void
    {
        $processor = new SendNotificationProcessor($this->service(), $this->securityFor(null));

        $this->expectException(AuthenticationCredentialsNotFoundException::class);
        $this->expectExceptionMessage('User is not authenticated.');
        $processor->process(new SendNotificationDto(), new Post());
    }

    #[Test]
    public function dataOfTheWrongTypeIsRefused(): void
    {
        $processor = new SendNotificationProcessor(
            $this->service(),
            $this->securityFor(new User()->setUsername('alice')),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Data must be an instance of SendNotificationDto.');
        $processor->process(new stdClass(), new Post());
    }

    /**
     * Never reached: both tests throw in a guard before the service is used.
     */
    private function service(): SendNotificationService
    {
        return new SendNotificationService(
            $this->createStub(MessageBusInterface::class),
            new NotificationRecorder($this->createStub(EntityManagerInterface::class)),
        );
    }

    private function securityFor(?User $user): Security
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return $security;
    }
}
