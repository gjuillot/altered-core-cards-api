<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260506082442 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sync schema: drop stale indexes, fix partial indexes on card_group, cast main_effect.keywords to JSON';
    }

    public function up(Schema $schema): void
    {
        // Drop stale unique indexes on ability tables (removed from entities)
        $this->addSql('DROP INDEX IF EXISTS uniq_ability_condition_altered_id');
        $this->addSql('DROP INDEX IF EXISTS uniq_ability_effect_altered_id');
        $this->addSql('DROP INDEX IF EXISTS uniq_ability_trigger_altered_id');

        // card_group: replace partial indexes (WHERE is_banned = true) with plain indexes
        $this->addSql('DROP INDEX IF EXISTS idx_card_group_is_banned');
        $this->addSql('DROP INDEX IF EXISTS idx_card_group_is_errated');
        $this->addSql('DROP INDEX IF EXISTS idx_card_group_is_suspended');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_card_group_is_banned ON card_group (is_banned)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_card_group_is_errated ON card_group (is_errated)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_card_group_is_suspended ON card_group (is_suspended)');

        // card_search: drop old unused indexes
        $this->addSql('DROP INDEX IF EXISTS idx_cs_keywords');
        $this->addSql('DROP INDEX IF EXISTS idx_cs_t1');
        $this->addSql('DROP INDEX IF EXISTS idx_cs_t2');
        $this->addSql('DROP INDEX IF EXISTS idx_cs_t3');
        $this->addSql('DROP INDEX IF EXISTS idx_cs_slot1');
        $this->addSql('DROP INDEX IF EXISTS idx_cs_slot2');
        $this->addSql('DROP INDEX IF EXISTS idx_cs_slot3');
        $this->addSql('DROP INDEX IF EXISTS idx_cs_has_effect');

        // card_translation: drop stale auto-generated index
        $this->addSql('DROP INDEX IF EXISTS idx_53bd1af54acc9a20');

        // main_effect: normalize FK constraint names + cast keywords JSONB → JSON
        $this->addSql('ALTER TABLE main_effect DROP CONSTRAINT IF EXISTS fk_main_effect_condition');
        $this->addSql('ALTER TABLE main_effect DROP CONSTRAINT IF EXISTS fk_main_effect_effect');
        $this->addSql('ALTER TABLE main_effect DROP CONSTRAINT IF EXISTS fk_main_effect_trigger');
        $this->addSql('ALTER TABLE main_effect DROP CONSTRAINT IF EXISTS FK_297C29E49D9E5937');
        $this->addSql('ALTER TABLE main_effect DROP CONSTRAINT IF EXISTS FK_297C29E452C0E4F7');
        $this->addSql('ALTER TABLE main_effect DROP CONSTRAINT IF EXISTS FK_297C29E46193FE87');
        $this->addSql('DROP INDEX IF EXISTS idx_main_effect_keywords');
        $this->addSql('ALTER TABLE main_effect ALTER keywords TYPE JSON USING keywords::json');
        $this->addSql('ALTER TABLE main_effect ADD CONSTRAINT FK_297C29E49D9E5937 FOREIGN KEY (ability_trigger_id) REFERENCES ability_trigger (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE main_effect ADD CONSTRAINT FK_297C29E452C0E4F7 FOREIGN KEY (ability_condition_id) REFERENCES ability_condition (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE main_effect ADD CONSTRAINT FK_297C29E46193FE87 FOREIGN KEY (ability_effect_id) REFERENCES ability_effect (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_ability_condition_altered_id ON ability_condition (altered_id)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_ability_effect_altered_id ON ability_effect (altered_id)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_ability_trigger_altered_id ON ability_trigger (altered_id)');
        $this->addSql('DROP INDEX IF EXISTS idx_card_group_is_banned');
        $this->addSql('DROP INDEX IF EXISTS idx_card_group_is_errated');
        $this->addSql('DROP INDEX IF EXISTS idx_card_group_is_suspended');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_card_group_is_banned ON card_group (id) WHERE (is_banned = true)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_card_group_is_suspended ON card_group (id) WHERE (is_suspended = true)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_card_group_is_errated ON card_group (id) WHERE (is_errated = true)');
        $this->addSql('ALTER TABLE main_effect DROP CONSTRAINT IF EXISTS FK_297C29E49D9E5937');
        $this->addSql('ALTER TABLE main_effect DROP CONSTRAINT IF EXISTS FK_297C29E452C0E4F7');
        $this->addSql('ALTER TABLE main_effect DROP CONSTRAINT IF EXISTS FK_297C29E46193FE87');
        $this->addSql('ALTER TABLE main_effect DROP CONSTRAINT IF EXISTS fk_main_effect_condition');
        $this->addSql('ALTER TABLE main_effect DROP CONSTRAINT IF EXISTS fk_main_effect_effect');
        $this->addSql('ALTER TABLE main_effect DROP CONSTRAINT IF EXISTS fk_main_effect_trigger');
        $this->addSql('ALTER TABLE main_effect ALTER keywords TYPE JSONB USING keywords::jsonb');
        $this->addSql('ALTER TABLE main_effect ADD CONSTRAINT fk_main_effect_condition FOREIGN KEY (ability_condition_id) REFERENCES ability_condition (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE main_effect ADD CONSTRAINT fk_main_effect_effect FOREIGN KEY (ability_effect_id) REFERENCES ability_effect (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE main_effect ADD CONSTRAINT fk_main_effect_trigger FOREIGN KEY (ability_trigger_id) REFERENCES ability_trigger (id) ON DELETE SET NULL');
    }
}
