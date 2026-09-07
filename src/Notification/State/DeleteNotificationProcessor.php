<?php

declare(strict_types=1);

namespace Nofi\Notification\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Nofi\ApiResource\NotificationResource;
use Nofi\Application\Notification\DeleteNotificationService;
use Override;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

use function sprintf;

/**
 * @implements ProcessorInterface<NotificationResource, null>
 */
final readonly class DeleteNotificationProcessor implements ProcessorInterface
{
    public function __construct(private DeleteNotificationService $service) {}

    #[Override]
    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): null {
        if (!$data instanceof NotificationResource) {
            return null;
        }

        // Deleting is asynchronous, and the handler refuses a notification
        // that can no longer be withdrawn. Refusing here as well is what makes
        // the answer honest: without it the caller is told 204 No Content for a
        // request that will quietly do nothing.
        //
        // Asked as isWithdrawable() rather than isFinal(), because PROCESSING
        // is neither final nor stoppable: isFinal() let a DELETE remove a
        // notification while a worker was mid-delivery, which is exactly what
        // the cancel operation refuses to do.
        if (!$data->status->isWithdrawable()) {
            throw new ConflictHttpException(sprintf(
                'Notification %s is %s and cannot be deleted. Only a send that has '
                . 'not started yet can be withdrawn.',
                $data->id,
                $data->status->value,
            ));
        }

        $this->service->delete($data->id);

        return null;
    }
}
