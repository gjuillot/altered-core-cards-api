<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260518164457 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add display_ocean_power, display_mountain_power, display_forest_power to card_group';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE card_group ADD display_ocean_power VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE card_group ADD display_mountain_power VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE card_group ADD display_forest_power VARCHAR(30) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE card_group DROP display_ocean_power');
        $this->addSql('ALTER TABLE card_group DROP display_mountain_power');
        $this->addSql('ALTER TABLE card_group DROP display_forest_power');
    }
}
