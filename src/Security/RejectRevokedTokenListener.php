<?php

declare(strict_types=1);

namespace Nofi\Security;

use Nofi\Repository\UserRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTDecodedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Tokens are stateless and live for an hour, so without this a password change
 * would leave every token issued beforehand working until it expired. Marking
 * the token invalid here produces a clean 401 rather than an exception.
 */
#[AsEventListener(event: Events::JWT_DECODED)]
final readonly class RejectRevokedTokenListener
{
    public function __construct(private UserRepository $users) {}

    public function __invoke(JWTDecodedEvent $event): void
    {
        $payload = $event->getPayload();
        $username = $payload["username"] ?? null;

        if (!is_string($username)) {
            $event->markAsInvalid();

            return;
        }

        $user = $this->users->findOneBy(["username" => $username]);
        if ($user === null) {
            // The authenticator rejects an unknown user on its own; saying so
            // here as well would only duplicate the decision.
            return;
        }

        // The id first: a username can be reused by a different person, and
        // their version counter starts at 1 again, so matching on the version
        // alone would let a token from the previous holder unlock the new
        // account. A token minted before these claims existed carries neither
        // and is treated as stale.
        if (($payload["uid"] ?? null) !== $user->getId()) {
            $event->markAsInvalid();

            return;
        }

        if (($payload["tokenVersion"] ?? null) !== $user->getTokenVersion()) {
            $event->markAsInvalid();
        }
    }
}
