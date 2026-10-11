<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011135802 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Défenses planétaires : types de défenses et leurs prérequis (contenu réglable)';
    }

    public function up(Schema $schema): void
    {
        // Contenu de départ, réglable ensuite dans le panneau (§4.8) ; valeurs provisoires avant l'équilibrage (§7)
        foreach (self::DEFENSES as $order => [$code, $name, $metal, $crystal, $deuterium, $attack, $shield, $structure, $description]) {
            $this->addSql(
                'INSERT INTO ship_type (code, name, category, ship_class_id, drive_id, cost_metal, cost_crystal, cost_deuterium, attack, shield, structure, speed, cargo, fuel_consumption, fuel_capacity, sort_order, description) VALUES (?, ?, \'defense\', NULL, NULL, ?, ?, ?, ?, ?, ?, 0, 0, 0, 0, ?, ?)',
                [$code, $name, $metal, $crystal, $deuterium, $attack, $shield, $structure, 200 + ($order + 1) * 10, $description],
            );
        }
        foreach (self::PREREQUISITES as [$target, $required, $level]) {
            $this->addSql(
                'INSERT INTO prerequisite (target_ship_id, required_building_id, required_technology_id, level) VALUES ((SELECT id FROM ship_type WHERE code = ?), (SELECT id FROM building_type WHERE code = ?), (SELECT id FROM technology WHERE code = ?), ?)',
                [$target, $required, $required, $level],
            );
        }
    }

    /** Code, nom, coût (métal, cristal, deutérium), attaque, bouclier, structure, description */
    private const array DEFENSES = [
        ['rocket_launcher', 'Lanceur de missiles', 2000, 0, 0, 80, 20, 2000, 'Défense rudimentaire et bon marché.'],
        ['light_laser', 'Laser léger', 1500, 500, 0, 100, 25, 2000, 'Tir précis contre les petits vaisseaux.'],
        ['heavy_laser', 'Laser lourd', 6000, 2000, 0, 250, 100, 8000, 'Version renforcée du laser léger.'],
        ['ion_cannon', 'Canon à ions', 5000, 3000, 0, 150, 500, 8000, 'Bouclier épais, tient bien la première ligne.'],
        ['gauss_cannon', 'Canon de Gauss', 20000, 15000, 2000, 1100, 200, 35000, 'Projectiles accélérés, redoutables contre les coques lourdes.'],
        ['plasma_turret', 'Lanceur de plasma', 50000, 50000, 30000, 3000, 300, 100000, 'Défense lourde de fin de partie.'],
        ['small_shield_dome', 'Petit bouclier planétaire', 10000, 10000, 0, 1, 2000, 20000, 'Dôme protecteur : absorbe beaucoup, ne tire presque pas.'],
        ['large_shield_dome', 'Grand bouclier planétaire', 50000, 50000, 0, 1, 10000, 100000, 'Dôme protecteur renforcé.'],
    ];

    /** Cible (défense), requis (bâtiment ou technologie), niveau requis */
    private const array PREREQUISITES = [
        ['rocket_launcher', 'shipyard', 1],
        ['light_laser', 'shipyard', 2],
        ['light_laser', 'energy', 1],
        ['heavy_laser', 'shipyard', 4],
        ['heavy_laser', 'energy', 3],
        ['heavy_laser', 'shielding', 1],
        ['ion_cannon', 'shipyard', 4],
        ['ion_cannon', 'energy', 4],
        ['ion_cannon', 'shielding', 2],
        ['gauss_cannon', 'shipyard', 6],
        ['gauss_cannon', 'weapons', 3],
        ['gauss_cannon', 'energy', 6],
        ['plasma_turret', 'shipyard', 8],
        ['plasma_turret', 'weapons', 8],
        ['plasma_turret', 'energy', 8],
        ['small_shield_dome', 'shipyard', 1],
        ['small_shield_dome', 'shielding', 2],
        ['large_shield_dome', 'shipyard', 6],
        ['large_shield_dome', 'shielding', 6],
    ];

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM ship_type WHERE category = \'defense\'');
    }
}
