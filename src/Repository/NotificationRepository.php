<?php

namespace Nofi\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nofi\Entity\Notification;
use Nofi\Entity\User;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /**
     * A null $owner means "no ownership restriction" and is reserved for administrators.
     *
     * @return list<Notification>
     */
    public function findOwnedBy(?User $owner, ?int $limit = null, int $offset = 0): array
    {
        // created_at has second precision, so it ties often enough to make
        // paging skip or repeat rows. Ids are UUIDv7 and therefore ordered by
        // creation time too, which makes them a stable tiebreaker.
        return $this->findBy(
            $owner === null ? [] : ['createdBy' => $owner],
            ['createdAt' => 'DESC', 'id' => 'DESC'],
            $limit,
            $offset,
        );
    }

    /**
     * A null $owner means "no ownership restriction" and is reserved for administrators.
     */
    public function countOwnedBy(?User $owner): int
    {
        return $this->count($owner === null ? [] : ['createdBy' => $owner]);
    }

    /**
     * A null $owner means "no ownership restriction" and is reserved for administrators.
     */
    public function findOneOwnedBy(string $id, ?User $owner): ?Notification
    {
        $criteria = ['id' => $id];
        if ($owner !== null) {
            $criteria['createdBy'] = $owner;
        }

        return $this->findOneBy($criteria);
    }
}
