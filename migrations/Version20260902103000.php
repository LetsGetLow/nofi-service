<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Version20260325093534 used to seed "admin" and "defaultUser" with password
 * hashes committed to the repository, so their credentials were public. The
 * seeding is gone, but databases that already ran it still carry the accounts.
 *
 * Removing them cascades to notification and notification_recipient through
 * notification.created_by. That is intended: the rows belong to accounts that
 * should never have existed. Create real accounts with
 * "bin/console nofi:user:create".
 */
final class Version20260902103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Remove the accounts seeded with committed password hashes";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DELETE FROM nofi_user WHERE username IN ('admin', 'defaultUser')");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            "Accounts with publicly known credentials are deliberately not restored.",
        );
    }
}
