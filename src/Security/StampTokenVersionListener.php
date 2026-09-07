<?php

declare(strict_types=1);

namespace Nofi\Security;

use Nofi\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Records who the token was issued to and which generation of their
 * credentials it was issued against, so RejectRevokedTokenListener can refuse
 * it later. The id matters as well as the version: usernames get reused, and
 * a new account starts its version at 1 again.
 */
#[AsEventListener(event: Events::JWT_CREATED)]
final readonly class StampTokenVersionListener
{
    public function __invoke(JWTCreatedEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $event->setData([
            ...$event->getData(),
            "uid" => $user->getId(),
            "tokenVersion" => $user->getTokenVersion(),
        ]);
    }
}
