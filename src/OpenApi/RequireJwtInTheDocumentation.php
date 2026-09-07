<?php

declare(strict_types=1);

namespace Nofi\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\OpenApi;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * The JWT security scheme is registered by LexikJWTAuthenticationBundle, but
 * nothing declared that any operation requires it: the document carried an
 * empty security list, so Swagger UI offered a place to paste a token and then
 * sent every request without it — every call from the console answered 401.
 *
 * Set as a document-wide requirement rather than per operation, so an
 * operation added later is covered without anyone remembering to say so. Only
 * the login is exempt, because that is where a token comes from.
 */
// Priority below Lexik's own decorator, which registers the scheme and adds
// the login path: a lower priority wraps it, so this sees the finished
// document instead of one the login has not been added to yet.
#[AsDecorator("api_platform.openapi.factory", priority: -10)]
final readonly class RequireJwtInTheDocumentation implements OpenApiFactoryInterface
{
    /**
     * Matches the scheme name LexikJWTAuthenticationBundle registers under
     * components.securitySchemes.
     */
    private const string SCHEME = "JWT";

    public function __construct(private OpenApiFactoryInterface $decorated) {}

    #[Override]
    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context)->withSecurity([[self::SCHEME => []]]);

        $paths = $openApi->getPaths();
        foreach ($paths->getPaths() as $path => $pathItem) {
            $login = $pathItem->getPost();
            if ($login === null || !str_ends_with($path, "/auth/login")) {
                continue;
            }

            // An empty list overrides the document-wide requirement, which is
            // how OpenAPI expresses "this one needs nothing".
            $paths->addPath($path, $pathItem->withPost($login->withSecurity([])));
        }

        return $openApi->withPaths($paths);
    }
}
