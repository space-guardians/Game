<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261015153547 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Butin et paramétrage PvE (#75) : compositions et récompense de victoire des profils';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE npc_behavior_profile ADD fleet_composition JSON DEFAULT \'{}\' NOT NULL');
        $this->addSql('ALTER TABLE npc_behavior_profile ADD defense_composition JSON DEFAULT \'{}\' NOT NULL');
        $this->addSql('ALTER TABLE npc_behavior_profile ADD victory_metal INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE npc_behavior_profile ADD victory_crystal INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE npc_behavior_profile ADD victory_deuterium INT DEFAULT 0 NOT NULL');
        // Valeurs de départ des profils installés (provisoires, réglables dans le panneau)
        $this->addSql("UPDATE npc_behavior_profile SET fleet_composition = '{\"light_fighter\": 15, \"cruiser\": 3, \"small_cargo\": 5}', defense_composition = '{\"rocket_launcher\": 20}', victory_metal = 5000, victory_crystal = 2500, victory_deuterium = 1000 WHERE code = 'minor_raider'");
        $this->addSql("UPDATE npc_behavior_profile SET fleet_composition = '{\"light_fighter\": 150, \"cruiser\": 60}', defense_composition = '{\"rocket_launcher\": 200}', victory_metal = 200000, victory_crystal = 100000, victory_deuterium = 50000 WHERE code = 'boss_guardian'");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE npc_behavior_profile DROP fleet_composition');
        $this->addSql('ALTER TABLE npc_behavior_profile DROP defense_composition');
        $this->addSql('ALTER TABLE npc_behavior_profile DROP victory_metal');
        $this->addSql('ALTER TABLE npc_behavior_profile DROP victory_crystal');
        $this->addSql('ALTER TABLE npc_behavior_profile DROP victory_deuterium');
    }
}
