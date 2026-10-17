<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261017082508 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Convois PNJ à escorter (#218) : guildes marchandes, intervalle entre convois, profil de départ';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE empire ADD npc_last_convoy_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE npc_behavior_profile ADD convoy_interval_hours INT DEFAULT 6 NOT NULL');
        // Contenu de départ, réglable dans le panneau (§4.15) ; valeurs provisoires avant l'équilibrage (§7) : convoi
        // de transporteurs, récompense d'escorte versée par le jeu (champs de récompense du profil)
        $this->addSql("INSERT INTO npc_behavior_profile (code, name, category, raids_enabled, raid_interval_hours, raid_range, fleet_recalc_hours, fleet_strength_percent, loot_percent, fleet_composition, defense_composition, victory_metal, victory_crystal, victory_deuterium, victory_antimatter, convoy_interval_hours) VALUES ('merchant_guild', 'Guilde marchande', 'trader', false, 24, 3000, 24, 100, 50, '{\"small_cargo\": 10, \"large_cargo\": 2}', '{\"rocket_launcher\": 30}', 3000, 2000, 0, 0, 6)");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql("DELETE FROM npc_behavior_profile WHERE code = 'merchant_guild'");
        $this->addSql('ALTER TABLE empire DROP npc_last_convoy_at');
        $this->addSql('ALTER TABLE npc_behavior_profile DROP convoy_interval_hours');
    }
}
