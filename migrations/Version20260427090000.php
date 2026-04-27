<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260427090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add composite index (card_type_id, id) on card_group for paginated cardType filter';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_card_group_card_type_id ON card_group (card_type_id, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_card_group_card_type_id');
    }
}
