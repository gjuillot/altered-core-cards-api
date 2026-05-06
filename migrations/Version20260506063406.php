<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260506063406 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_public column to card_search table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE card_search ADD is_public BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE card_search ALTER is_public DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE card_search DROP is_public');
    }
}
