<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260721083000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create card_patch_log ledger table for the card-patches batch-update system';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE card_patch_log (
                id SERIAL NOT NULL,
                filename VARCHAR(255) NOT NULL,
                checksum VARCHAR(64) NOT NULL,
                applied_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                rows_updated INT NOT NULL,
                rows_skipped INT NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_card_patch_log_filename ON card_patch_log (filename)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE card_patch_log');
    }
}
