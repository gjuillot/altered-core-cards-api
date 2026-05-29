<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260529130429 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add echo effect slot (et1, ec1, ee1) to card_search';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE card_search ADD et1 INT DEFAULT NULL');
        $this->addSql('ALTER TABLE card_search ADD ec1 INT DEFAULT NULL');
        $this->addSql('ALTER TABLE card_search ADD ee1 INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE card_search DROP COLUMN et1');
        $this->addSql('ALTER TABLE card_search DROP COLUMN ec1');
        $this->addSql('ALTER TABLE card_search DROP COLUMN ee1');
    }
}
