<?php

declare(strict_types=1);

namespace Nofi\Tests\Application;

use Nofi\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;

#[TestDox("A username reused by a different person")]
final class UsernameReuseTest extends ApiTestCase
{
    #[Test]
    public function anOldTokenMustNotUnlockANewAccountWithTheSameUsername(): void
    {
        // Someone leaves. Their token is still inside its hour.
        $original = $this->createUser('alice');
        $token = $this->tokenFor($original);

        $this->request('GET', '/api/v1/notifications', $token);
        $this->assertResponseStatus(Response::HTTP_OK);

        self::entityManager()->remove(
            self::entityManager()->getRepository(User::class)->findOneBy(['username' => 'alice']),
        );
        self::entityManager()->flush();

        $this->request('GET', '/api/v1/notifications', $token);
        $this->assertResponseStatus(Response::HTTP_UNAUTHORIZED);

        // A different person is given the same username. Their token_version
        // starts at 1 again, which is what the old token carries.
        $replacement = $this->createUser('alice');
        self::assertNotSame($original->getId(), $replacement->getId());

        $this->request('GET', '/api/v1/notifications', $token);

        $this->assertResponseStatus(Response::HTTP_UNAUTHORIZED);
    }
}
