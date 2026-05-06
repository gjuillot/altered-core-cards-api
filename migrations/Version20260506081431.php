<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260506081431 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Covering index on card(set_id, rarity_id, card_group_id) for faction+rarity+set filter';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_card_set_rarity_group ON card (set_id, rarity_id, card_group_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_card_set_rarity_group');
    }
}
