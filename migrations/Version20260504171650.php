<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260504171650 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_exclusive, lower_price and card_product fields to card';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE card ADD is_exclusive BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE card ADD lower_price DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE card ADD card_product VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE card DROP is_exclusive');
        $this->addSql('ALTER TABLE card DROP lower_price');
        $this->addSql('ALTER TABLE card DROP card_product');
    }
}
