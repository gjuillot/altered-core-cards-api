<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260426194741 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add index on card_set.reference for set filter performance';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_card_set_reference ON card_set (reference)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_card_set_reference');
    }
}
