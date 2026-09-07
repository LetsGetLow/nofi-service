<?php

declare(strict_types=1);

namespace Nofi\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nofi\Entity\User;

/**
 * @extends ServiceEntityRepository<User>
 */
final class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Increments in the database rather than writing a value worked out from
     * an earlier read. User::revokeTokens() computes the new number in PHP, so
     * two revocations racing each other would both write the same value, and
     * flushing an entity loaded before someone else's revocation would lower
     * the counter and bring their tokens back. This cannot do either.
     */
    public function revokeTokens(User $user): void
    {
        $this->getEntityManager()
            ->createQuery('UPDATE ' . User::class . ' u SET u.tokenVersion = u.tokenVersion + 1 WHERE u.id = :id')
            ->setParameter('id', $user->getId())
            ->execute();

        // The statement bypasses the identity map, so the in-memory copy would
        // otherwise still hold the previous number.
        $this->getEntityManager()->refresh($user);
    }
}
