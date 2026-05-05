<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add index on faction.code for faster findOneByCode lookups during import.
 */
final class Version20260505153919 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add index on faction.code';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_faction_code ON faction (code)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_faction_code');
    }
}
