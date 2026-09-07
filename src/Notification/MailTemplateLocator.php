<?php

declare(strict_types=1);

namespace Nofi\Notification;

use Twig\Environment;

use function preg_match;
use function sprintf;

/**
 * Turns the bare template name a caller sends into the Twig reference for the
 * one directory templates may come from.
 */
final readonly class MailTemplateLocator
{
    public const string NAMESPACE = "mail";

    /**
     * Deliberately excludes dots and slashes: the name is concatenated into a
     * template path, and this is what keeps a request inside the directory.
     */
    public const string NAME_PATTERN = '/^[A-Za-z0-9_-]++$/';

    public function __construct(private Environment $twig) {}

    public static function isValidName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    public function reference(string $name): string
    {
        return sprintf("@%s/%s.html.twig", self::NAMESPACE, $name);
    }

    public function exists(string $name): bool
    {
        return self::isValidName($name)
            && $this->twig->getLoader()->exists($this->reference($name));
    }

    public function render(string $name, array $context): string
    {
        return $this->twig->render($this->reference($name), $context);
    }
}
