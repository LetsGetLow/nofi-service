<?php

declare(strict_types=1);

namespace Nofi\Tests\Integration;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The documentation has to say that a token is required, or Swagger UI offers
 * a field for one and then sends every request without it. The scheme alone is
 * not enough — that was the state this pins against.
 */
#[TestDox("The documented security")]
final class OpenApiSecurityTest extends IntegrationTestCase
{
    #[Test]
    public function theDocumentRequiresTheJwtSchemeItRegisters(): void
    {
        $openApi = self::service(OpenApiFactoryInterface::class)();

        self::assertSame([["JWT" => []]], $openApi->getSecurity());
        self::assertArrayHasKey(
            "JWT",
            (array) $openApi->getComponents()->getSecuritySchemes(),
            "the requirement names a scheme that must exist",
        );
    }

    #[Test]
    public function theLoginIsTheOneOperationThatNeedsNoToken(): void
    {
        $openApi = self::service(OpenApiFactoryInterface::class)();

        $login = null;
        foreach ($openApi->getPaths()->getPaths() as $path => $pathItem) {
            if (str_ends_with($path, "/auth/login")) {
                $login = $pathItem->getPost();
            }
        }

        self::assertNotNull($login, "the login has to be in the documentation");
        // An empty list is how OpenAPI overrides the document-wide
        // requirement; null would inherit it and lock the door on the way in.
        self::assertSame([], $login->getSecurity());
    }

    #[Test]
    public function everyNotificationOperationInheritsTheRequirement(): void
    {
        $openApi = self::service(OpenApiFactoryInterface::class)();

        foreach ($openApi->getPaths()->getPaths() as $path => $pathItem) {
            if (!str_contains($path, "/notifications")) {
                continue;
            }

            foreach (["getPost", "getGet", "getPatch", "getDelete"] as $method) {
                $operation = $pathItem->{$method}();
                if ($operation === null) {
                    continue;
                }

                // Inheriting means saying nothing of its own. An empty list
                // here would quietly make the operation public.
                self::assertNull(
                    $operation->getSecurity(),
                    sprintf("%s %s must not override the requirement", $method, $path),
                );
            }
        }
    }
}
