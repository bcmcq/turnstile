<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'venues: record the ArenaSize preset the arena was seeded with';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE venues ADD arena_size VARCHAR(8) DEFAULT 'demo' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE venues DROP arena_size');
    }
}
