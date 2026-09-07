<?php

declare(strict_types=1);

namespace Nofi\Service\Push;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Whether this deployment is set up to send push at all. Push is optional —
 * an email-only deployment is a normal one — so the question the health check
 * asks is "is it configured", not "is it working": the file being there is
 * what tells the two apart.
 *
 * Deliberately not "is it valid". Proving that the key and the project are
 * accepted takes a request to Google (`validate_only`, which is what
 * tests/Live/RealPushTest.php does), and /health is answered every ten seconds
 * by the container healthcheck. A file that is present but unusable therefore
 * still reads as configured here, and shows up where it always did: per device
 * token in the worker log.
 */
readonly class PushCredentials
{
    public function __construct(
        #[Autowire("%env(resolve:FIREBASE_CREDENTIALS_FILE)%")]
        private string $path,
        #[Autowire("%kernel.project_dir%")]
        private string $projectDir,
    ) {}

    public function isConfigured(): bool
    {
        if ($this->path === "") {
            return false;
        }

        $path = $this->absolutePath();

        // FrankenPHP keeps this process alive across requests, and so does the
        // stat cache: without this, a credentials file that appeared after the
        // first check would keep reading as absent for the life of the worker.
        clearstatcache(true, $path);

        return is_file($path);
    }

    /**
     * The configured value is relative to the project directory — that is how
     * .env writes it and how Kreait resolves it, the working directory being
     * /app — but an absolute path is honoured as given.
     */
    public function absolutePath(): string
    {
        return str_starts_with($this->path, "/")
            ? $this->path
            : $this->projectDir . "/" . $this->path;
    }
}
