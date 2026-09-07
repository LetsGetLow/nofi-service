<?php

declare(strict_types=1);

namespace Nofi\Notification\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\Pagination\PaginatorInterface;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use ArrayIterator;
use Nofi\ApiResource\NotificationResource;
use Nofi\Entity\User;
use Nofi\Repository\NotificationRepository;
use Override;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * @implements ProviderInterface<NotificationResource>
 */
final readonly class NotificationProvider implements ProviderInterface
{
    public function __construct(
        private NotificationRepository $repository,
        private Security $security,
        private Pagination $pagination,
    ) {}

    #[Override]
    public function provide(
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): iterable|NotificationResource|null {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException("Notifications require an authenticated user.");
        }

        // Administrators are not restricted to the notifications they created themselves.
        $owner = $this->security->isGranted("ROLE_ADMIN") ? null : $user;

        if (isset($uriVariables["id"])) {
            $notification = $this->repository->findOneOwnedBy((string) $uriVariables["id"], $owner);

            return $notification === null ? null : NotificationResource::fromEntity($notification);
        }

        if (!$this->pagination->isEnabled($operation, $context)) {
            return array_map(NotificationResource::fromEntity(...), $this->repository->findOwnedBy($owner));
        }

        return $this->paginate($operation, $context, $owner);
    }

    private function paginate(Operation $operation, array $context, ?User $owner): PaginatorInterface
    {
        [$page, $offset, $limit] = $this->pagination->getPagination($operation, $context);

        $resources = array_map(
            NotificationResource::fromEntity(...),
            $this->repository->findOwnedBy($owner, $limit, $offset),
        );

        return new TraversablePaginator(
            new ArrayIterator($resources),
            $page,
            $limit,
            $this->repository->countOwnedBy($owner),
        );
    }

}
