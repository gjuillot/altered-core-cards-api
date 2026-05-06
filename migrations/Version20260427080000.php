<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260427080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add composite index (rarity_id, id) on card and index on card_translation.card_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_card_rarity_id ON card (rarity_id, id)');
        $this->addSql('CREATE INDEX idx_card_translation_card_id ON card_translation (card_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_card_rarity_id');
        $this->addSql('DROP INDEX idx_card_translation_card_id');
    }
}
