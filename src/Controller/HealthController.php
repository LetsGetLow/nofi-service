<?php

declare(strict_types=1);

namespace Nofi\Controller;

use Doctrine\DBAL\Connection;
use Nofi\Service\Push\PushCredentials;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * Answers the container health check. Caddy used to serve /health as a static
 * string, which stayed green while PHP or the database were unreachable.
 *
 * Only the database decides the status code. Push is reported beside it but
 * never fails the check: it is optional, and an email-only deployment that
 * answered 503 here would be restarted by Compose forever.
 */
final class HealthController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly PushCredentials $pushCredentials,
    ) {}

    #[Route("/health", name: "health", methods: ["GET"])]
    public function __invoke(): JsonResponse
    {
        try {
            $this->connection->executeQuery("SELECT 1");
        } catch (Throwable $e) {
            $this->logger->error("Health check failed: the database is unreachable.", [
                "exception" => $e,
            ]);

            return new JsonResponse(
                ["status" => "error", "database" => "unreachable", "push" => $this->push()],
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        return new JsonResponse(["status" => "ok", "database" => "ok", "push" => $this->push()]);
    }

    /**
     * "enabled" and "disabled" rather than the status vocabulary the other
     * fields use, because this one answers whether push is set up, not whether
     * anything is wrong: "disabled" is the correct and healthy answer for
     * every deployment that only sends mail.
     */
    private function push(): string
    {
        return $this->pushCredentials->isConfigured() ? "enabled" : "disabled";
    }
}
