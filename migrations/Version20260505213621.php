<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260505213621 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_public column to card table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE card ADD is_public BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE card ALTER is_public DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE card DROP is_public');
    }
}
