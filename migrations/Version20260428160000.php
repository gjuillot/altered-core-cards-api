<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260428160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add index on card.collector_number_formated_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_card_collector_number ON card (collector_number_formated_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_card_collector_number');
    }
}
