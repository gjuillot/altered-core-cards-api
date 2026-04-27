<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260427110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add covering index (set_id, card_group_id) on card for set filter subquery';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_card_set_card_group ON card (set_id, card_group_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_card_set_card_group');
    }
}
