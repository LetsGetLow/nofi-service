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
        // that already went out. Refusing here as well is what makes the
        // answer honest: without it the caller is told 204 No Content for a
        // request that will quietly do nothing.
        if ($data->status->isFinal()) {
            throw new ConflictHttpException(sprintf(
                'Notification %s is %s and cannot be deleted. Only a send that has '
                . 'not gone out yet can be cancelled.',
                $data->id,
                $data->status->value,
            ));
        }

        $this->service->delete($data->id);

        return null;
    }
}
