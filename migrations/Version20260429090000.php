<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260429090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill card_number from collector_number_formated_id (positions 4-6) and add composite index for ordering';
    }

    public function up(Schema $schema): void
    {
        // Composite index to support ORDER BY card_number within a set
        $this->addSql('CREATE INDEX idx_card_set_card_number ON card (set_id, card_number)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_card_set_card_number');
    }
}
