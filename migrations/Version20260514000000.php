<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260514000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add echo_effect1_id foreign key to card_group';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE card_group ADD echo_effect1_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE card_group ADD CONSTRAINT FK_card_group_echo_effect1 FOREIGN KEY (echo_effect1_id) REFERENCES main_effect (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_card_group_echo_effect1 ON card_group (echo_effect1_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE card_group DROP CONSTRAINT FK_card_group_echo_effect1');
        $this->addSql('DROP INDEX IDX_card_group_echo_effect1');
        $this->addSql('ALTER TABLE card_group DROP COLUMN echo_effect1_id');
    }
}
