<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260423120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove echoEffect from card_group - moved to card_group_translation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE card_group DROP COLUMN echo_effect');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE card_group ADD echo_effect TEXT DEFAULT NULL');
    }
}