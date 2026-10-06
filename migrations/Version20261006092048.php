<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006092048 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stock de ressources des planètes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planet ADD metal DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE planet ADD crystal DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE planet ADD deuterium DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE planet ADD resources_updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        // Planètes mères déjà fondées : dotation de départ (app.economy.starting_resources) et production dès maintenant
        $this->addSql('UPDATE planet SET metal = 500, crystal = 500, resources_updated_at = NOW() WHERE owner_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planet DROP metal');
        $this->addSql('ALTER TABLE planet DROP crystal');
        $this->addSql('ALTER TABLE planet DROP deuterium');
        $this->addSql('ALTER TABLE planet DROP resources_updated_at');
    }
}
