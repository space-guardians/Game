<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016213900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Récompenses spéciales des boss (#202) : confinement d\'antimatière, croiseur d\'antimatière sur plan (contenu réglable)';
    }

    public function up(Schema $schema): void
    {
        // Contenu de départ, réglable ensuite dans le panneau (§4.14) ; valeurs provisoires avant l'équilibrage (§7).
        // La ressource rare n'entre que dans ce qui vient après la progression normale : aucun joueur n'en dépend sans
        // affronter de boss.
        $this->addSql(
            'INSERT INTO technology (code, name, base_cost_metal, base_cost_crystal, base_cost_deuterium, base_cost_antimatter, cost_factor, sort_order, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            ['antimatter_containment', 'Confinement d’antimatière', 40000, 80000, 40000, 20, 2, 110, 'Maîtrise de l’antimatière arrachée aux civilisations « boss » : ouvre la construction des vaisseaux qui l’emploient.'],
        );
        foreach ([['research_lab', 8], ['hyperspace_drive', 6], ['energy', 8]] as [$required, $level]) {
            $this->addSql(
                'INSERT INTO prerequisite (target_technology_id, required_building_id, required_technology_id, level) VALUES ((SELECT id FROM technology WHERE code = ?), (SELECT id FROM building_type WHERE code = ?), (SELECT id FROM technology WHERE code = ?), ?)',
                ['antimatter_containment', $required, $required, $level],
            );
        }

        $this->addSql(
            'INSERT INTO ship_type (code, name, category, ship_class_id, drive_id, cost_metal, cost_crystal, cost_deuterium, cost_antimatter, requires_blueprint, attack, shield, structure, speed, cargo, fuel_consumption, fuel_capacity, sort_order, description) VALUES (?, ?, \'military\', (SELECT id FROM ship_class WHERE code = \'capital\'), (SELECT id FROM technology WHERE code = \'hyperspace_drive\'), ?, ?, ?, ?, true, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            ['antimatter_cruiser', 'Croiseur d’antimatière', 90000, 70000, 30000, 30, 3500, 900, 160000, 6000, 2000, 1200, 25000, 105, 'Vaisseau de ligne propulsé à l’antimatière ; son plan se remporte sur une civilisation « boss ».'],
        );
        foreach ([['shipyard', 10], ['antimatter_containment', 1]] as [$required, $level]) {
            $this->addSql(
                'INSERT INTO prerequisite (target_ship_id, required_building_id, required_technology_id, level) VALUES ((SELECT id FROM ship_type WHERE code = ?), (SELECT id FROM building_type WHERE code = ?), (SELECT id FROM technology WHERE code = ?), ?)',
                ['antimatter_cruiser', $required, $required, $level],
            );
        }

        $this->addSql(
            'UPDATE npc_behavior_profile SET victory_antimatter = 150, victory_blueprint_id = (SELECT id FROM ship_type WHERE code = \'antimatter_cruiser\') WHERE code = \'boss_guardian\'',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE npc_behavior_profile SET victory_antimatter = 0, victory_blueprint_id = NULL WHERE code = \'boss_guardian\'');
        $this->addSql('DELETE FROM ship_type WHERE code = \'antimatter_cruiser\'');
        $this->addSql('DELETE FROM technology WHERE code = \'antimatter_containment\'');
    }
}
