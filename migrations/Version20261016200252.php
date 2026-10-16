<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261016200252 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Montée en charge (#81) : index des flottes à l\'arrêt par galaxie, des événements par planète, des classements par catégorie';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE INDEX empire_score_economy_idx ON empire_score (economy)');
        $this->addSql('CREATE INDEX empire_score_research_idx ON empire_score (research)');
        $this->addSql('CREATE INDEX empire_score_military_idx ON empire_score (military)');
        $this->addSql('CREATE INDEX empire_score_exploration_idx ON empire_score (exploration)');
        $this->addSql('CREATE INDEX fleet_stopped_idx ON fleet (location_galaxy_id, status)');
        $this->addSql('CREATE INDEX scheduled_event_planet_due_idx ON scheduled_event (planet_id, status, due_at)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX empire_score_economy_idx');
        $this->addSql('DROP INDEX empire_score_research_idx');
        $this->addSql('DROP INDEX empire_score_military_idx');
        $this->addSql('DROP INDEX empire_score_exploration_idx');
        $this->addSql('DROP INDEX fleet_stopped_idx');
        $this->addSql('DROP INDEX scheduled_event_planet_due_idx');
    }
}
