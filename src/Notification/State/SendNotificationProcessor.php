<?php

declare(strict_types=1);

namespace Nofi\Notification\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Nofi\Application\Notification\SendNotificationService;
use Nofi\Dto\SendNotificationDto;
use Nofi\Entity\User;
use Nofi\ApiResource\NotificationResource;
use InvalidArgumentException;
use Override;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationCredentialsNotFoundException;

/**
 * @implements ProcessorInterface<SendNotificationDto, NotificationResource>
 */
final readonly class SendNotificationProcessor implements ProcessorInterface
{
    public function __construct(private SendNotificationService $service, private Security $security) {}

    #[Override]
    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): object {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AuthenticationCredentialsNotFoundException("User is not authenticated.");
        }

        if (!$data instanceof SendNotificationDto) {
            throw new InvalidArgumentException("Data must be an instance of SendNotificationDto.");
        }

        // Built from the stored notification: answering with a bare resource
        // meant the class defaults were serialised, so a push was reported
        // back as an email that had not been queued yet.
        return NotificationResource::fromEntity($this->service->send($user, $data));
    }
}
