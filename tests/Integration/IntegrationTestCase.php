<?php

declare(strict_types=1);

namespace Nofi\Tests\Integration;

use Nofi\Tests\Support\InteractsWithDatabase;
use Override;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

abstract class IntegrationTestCase extends KernelTestCase
{
    use InteractsWithDatabase;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetDatabase();
    }

    #[Override]
    protected static function testContainer(): ContainerInterface
    {
        return static::getContainer();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    protected static function service(string $id): object
    {
        return static::getContainer()->get($id);
    }
}
