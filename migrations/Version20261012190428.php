<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012190428 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Espionnage : risque de détection des sondes dans les rapports';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE espionage_report ADD detection_chance DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE espionage_report ADD detected BOOLEAN DEFAULT false NOT NULL');
        // Rapports existants : aucun risque couru ; les valeurs par défaut ne servent qu'à les remplir
        $this->addSql('ALTER TABLE espionage_report ALTER detection_chance DROP DEFAULT');
        $this->addSql('ALTER TABLE espionage_report ALTER detected DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE espionage_report DROP detection_chance');
        $this->addSql('ALTER TABLE espionage_report DROP detected');
    }
}
