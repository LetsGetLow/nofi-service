<?php

declare(strict_types=1);

namespace Nofi\Notification\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Nofi\ApiResource\NotificationResource;
use Nofi\Entity\Notification;
use Override;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use function sprintf;

/**
 * Stops a send that has not gone out yet, and keeps it as a record of the
 * decision. Deleting would remove the trace instead.
 *
 * The queued message is left where it is: a delayed one cannot be withdrawn
 * from the transport reliably. The cancellation takes effect when a worker
 * picks the message up and finds the notification no longer waiting, which is
 * what SendNotificationHandler checks.
 *
 * @implements ProcessorInterface<NotificationResource, NotificationResource>
 */
final readonly class CancelNotificationProcessor implements ProcessorInterface
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    #[Override]
    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): NotificationResource {
        // A POST does not turn an empty read into a 404 by itself, so the
        // answer for someone else's notification has to be made here. Any
        // other status would tell the caller that it exists.
        if (!$data instanceof NotificationResource) {
            throw new NotFoundHttpException("Notification not found.");
        }

        $notification = $this->entityManager->find(Notification::class, $data->id);
        if (!$notification instanceof Notification) {
            throw new NotFoundHttpException("Notification not found.");
        }

        if (!$notification->getStatus()->isWithdrawable()) {
            throw new ConflictHttpException(sprintf(
                'Notification %s is %s and cannot be cancelled. Only a send that has '
                . 'not gone out yet can be stopped.',
                $notification->getId(),
                $notification->getStatus()->value,
            ));
        }

        $notification->markCancelled();
        $this->entityManager->flush();

        return NotificationResource::fromEntity($notification);
    }
}
