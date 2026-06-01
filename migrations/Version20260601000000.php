<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260601000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_main column to ability_trigger, ability_condition, ability_effect';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ability_trigger ADD is_main BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE ability_condition ADD is_main BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE ability_effect ADD is_main BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ability_trigger DROP COLUMN is_main');
        $this->addSql('ALTER TABLE ability_condition DROP COLUMN is_main');
        $this->addSql('ALTER TABLE ability_effect DROP COLUMN is_main');
    }
}
