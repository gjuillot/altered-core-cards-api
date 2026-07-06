<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260602000000 extends AbstractMigration
{
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Add slot indexes on card_search for slot-facets performance (5.4M rows)';
    }

    public function up(Schema $schema): void
    {
        // Composite indexes per slot — cover trigger-first lookups (t→c→e cascade)
        // PostgreSQL uses the leftmost columns so (t1,c1,e1) also covers WHERE t1=X AND c1=Y
        $this->addSql('CREATE INDEX CONCURRENTLY idx_cs_slot1 ON card_search (t1, c1, e1)');
        $this->addSql('CREATE INDEX CONCURRENTLY idx_cs_slot2 ON card_search (t2, c2, e2)');
        $this->addSql('CREATE INDEX CONCURRENTLY idx_cs_slot3 ON card_search (t3, c3, e3)');
        $this->addSql('CREATE INDEX CONCURRENTLY idx_cs_echo  ON card_search (et1, ec1, ee1)');

        // Individual indexes for condition-first and effect-first reverse lookups
        $this->addSql('CREATE INDEX CONCURRENTLY idx_cs_c1  ON card_search (c1)');
        $this->addSql('CREATE INDEX CONCURRENTLY idx_cs_c2  ON card_search (c2)');
        $this->addSql('CREATE INDEX CONCURRENTLY idx_cs_c3  ON card_search (c3)');
        $this->addSql('CREATE INDEX CONCURRENTLY idx_cs_ec1 ON card_search (ec1)');
        $this->addSql('CREATE INDEX CONCURRENTLY idx_cs_e1  ON card_search (e1)');
        $this->addSql('CREATE INDEX CONCURRENTLY idx_cs_e2  ON card_search (e2)');
        $this->addSql('CREATE INDEX CONCURRENTLY idx_cs_e3  ON card_search (e3)');
        $this->addSql('CREATE INDEX CONCURRENTLY idx_cs_ee1 ON card_search (ee1)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_cs_slot1');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_cs_slot2');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_cs_slot3');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_cs_echo');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_cs_c1');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_cs_c2');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_cs_c3');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_cs_ec1');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_cs_e1');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_cs_e2');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_cs_e3');
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS idx_cs_ee1');
    }
}
