<?php

declare(strict_types=1);

namespace Nofi\Notification\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use DomainException;
use Nofi\Notification\NotificationLifecycle;
use Nofi\ApiResource\NotificationResource;
use Override;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
    public function __construct(private NotificationLifecycle $lifecycle)
    {
    }

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

        try {
            $notification = $this->lifecycle->cancelNotification($data->id);
        } catch (DomainException $exception) {
            throw new ConflictHttpException($exception->getMessage(), $exception);
        }

        if ($notification === null) {
            throw new NotFoundHttpException("Notification not found.");
        }

        return NotificationResource::fromEntity($notification);
    }
}
