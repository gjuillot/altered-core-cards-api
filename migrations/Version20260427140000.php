<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260427140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add covering index (card_group_id, set_id) on card for nested loop set membership check';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_card_card_group_set ON card (card_group_id, set_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_card_card_group_set');
    }
}
