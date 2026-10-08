<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'runs: drop failed_jobs, it always mirrored dead_lettered_jobs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE runs DROP failed_jobs');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE runs ADD failed_jobs INT UNSIGNED DEFAULT 0 NOT NULL');
    }
}
