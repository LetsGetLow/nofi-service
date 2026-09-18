<?php

declare(strict_types=1);

namespace Nofi\Notification\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Nofi\ApiResource\NotificationResource;
use DomainException;
use Nofi\Notification\NotificationAttachmentCleaner;
use Nofi\Notification\NotificationLifecycle;
use Override;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * @implements ProcessorInterface<NotificationResource, null>
 */
final readonly class DeleteNotificationProcessor implements ProcessorInterface
{
    public function __construct(
        private NotificationLifecycle $lifecycle,
        private NotificationAttachmentCleaner $attachmentCleaner,
    ) {
    }

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

        try {
            $notification = $this->lifecycle->deleteNotification($data->id);
        } catch (DomainException $exception) {
            throw new ConflictHttpException($exception->getMessage(), $exception);
        }

        // Never delivered, so nothing will ever read its attachment files again.
        if ($notification !== null) {
            $this->attachmentCleaner->cleanUpFor($notification);
        }

        return null;
    }
}
