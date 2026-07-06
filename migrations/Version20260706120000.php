<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260706120000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false; // required for CREATE INDEX CONCURRENTLY
    }

    public function getDescription(): string
    {
        return 'Add gameplay_format TEXT[] column on card_group + GIN index for containment filters';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE card_group ADD gameplay_format TEXT[] NOT NULL DEFAULT '{}'");
        $this->addSql('CREATE INDEX CONCURRENTLY idx_card_group_gameplay_format ON card_group USING GIN (gameplay_format)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_card_group_gameplay_format');
        $this->addSql('ALTER TABLE card_group DROP gameplay_format');
    }
}
