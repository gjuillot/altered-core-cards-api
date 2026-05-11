<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260510000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add -CORE / -COREKS suffix to unique CardGroup slugs so CORE and COREKS variants no longer share a CardGroup';
    }

    public function up(Schema $schema): void
    {
        // Unique card slug pattern: <FACTION>-<NNN>-U-<variant>  (no set suffix yet)
        // We derive the set from the reference of any card in the group.

        // 1. CardGroups that have at least one CORE card → -CORE
        $this->addSql(<<<'SQL'
            UPDATE card_group cg
            SET    slug = cg.slug || '-CORE'
            WHERE  cg.slug ~ '^[A-Z]+-[0-9]{3}-U-[0-9]+$'
            AND EXISTS (
                SELECT 1
                FROM   card c
                WHERE  c.card_group_id = cg.id
                AND    c.reference LIKE 'ALT\_CORE\_%' ESCAPE '\'
            )
        SQL);

        // 2. CardGroups that have ONLY COREKS cards (no CORE) → -COREKS
        $this->addSql(<<<'SQL'
            UPDATE card_group cg
            SET    slug = cg.slug || '-COREKS'
            WHERE  cg.slug ~ '^[A-Z]+-[0-9]{3}-U-[0-9]+$'
            AND NOT EXISTS (
                SELECT 1
                FROM   card c
                WHERE  c.card_group_id = cg.id
                AND    c.reference LIKE 'ALT\_CORE\_%' ESCAPE '\'
            )
            AND EXISTS (
                SELECT 1
                FROM   card c
                WHERE  c.card_group_id = cg.id
                AND    c.reference LIKE 'ALT\_COREKS\_%' ESCAPE '\'
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE card_group
            SET    slug = regexp_replace(slug, '-(CORE|COREKS)$', '')
            WHERE  slug ~ '^[A-Z]+-[0-9]{3}-U-[0-9]+-(CORE|COREKS)$'
        SQL);
    }
}
