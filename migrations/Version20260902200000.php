<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the generation counter that lets an already issued token be refused.
 * Existing rows start at 1, and every token issued before this migration
 * carries no version at all, so all of them stop working: intended, since the
 * point of the column is to be able to cut tokens off.
 */
final class Version20260902200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Add nofi_user.token_version so issued tokens can be revoked";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE nofi_user ADD token_version INT DEFAULT 1 NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE nofi_user DROP token_version");
    }
}
