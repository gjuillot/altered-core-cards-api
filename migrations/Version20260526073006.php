<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260526073006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add set_date denormalized column to card + covering indexes for ORDER BY performance';
    }

    public function isTransactional(): bool
    {
        return false; // required for CREATE INDEX CONCURRENTLY
    }

    public function up(Schema $schema): void
    {
        // Column may already exist if added manually — skip if so.
        $this->addSql("
            DO \$\$ BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_name = 'card' AND column_name = 'set_date'
                ) THEN
                    ALTER TABLE card ADD set_date DATE DEFAULT NULL;
                END IF;
            END \$\$
        ");
        $this->addSql('UPDATE card c SET set_date = cs.date::date FROM card_set cs WHERE c.set_id = cs.id AND c.set_date IS NULL');
        $this->addSql('CREATE INDEX CONCURRENTLY idx_card_set_date_collector ON card (set_date, collector_number_formated_id)');
        $this->addSql('CREATE INDEX CONCURRENTLY idx_card_rarity_set_date_collector ON card (rarity_id, set_date, collector_number_formated_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_card_set_date_collector');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_card_rarity_set_date_collector');
    }
}
