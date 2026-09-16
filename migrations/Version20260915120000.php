<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260915120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record when a worker claims a notification';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification ADD processing_started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification DROP processing_started_at');
    }
}
