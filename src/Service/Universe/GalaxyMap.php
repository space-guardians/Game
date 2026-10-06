<?php

declare(strict_types=1);

namespace App\Service\Universe;

use App\Entity\Empire;
use App\Entity\Galaxy;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Données de la carte (§2.3, §5.5) pour la zone visible : systèmes avec leurs chiffres agrégés (vue galaxie) et, en
 * zoomant, les planètes de ces systèmes à leur position (vue système). Requêtes SQL directes : la carte lit beaucoup
 * de lignes et n'a besoin d'aucune entité.
 */
final readonly class GalaxyMap
{
    /** Systèmes au plus par réponse (une galaxie en compte un millier environ) */
    public const int MAX_SYSTEMS = 2000;

    /** Au-delà, les planètes ne sont pas détaillées : la vue est trop large pour les distinguer */
    public const int MAX_DETAILED_SYSTEMS = 60;

    public function __construct(
        private Connection $connection,
    ) {}

    /**
     * Systèmes de la galaxie dans le rectangle donné (coordonnées globales), avec planètes totales, libres, occupées,
     * et celles de l'empire du joueur.
     *
     * @return list<array{id: int, number: int, x: float, y: float, planets: int, free: int, occupied: int, mine: int}>
     */
    public function systems(Galaxy $galaxy, float $minX, float $minY, float $maxX, float $maxY, ?Empire $viewer): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT s.id, s.number, s.x, s.y,
                    COUNT(p.id) AS planets,
                    COUNT(p.id) FILTER (WHERE p.owner_id IS NULL) AS free,
                    COUNT(p.id) FILTER (WHERE p.owner_id IS NOT NULL) AS occupied,
                    COUNT(p.id) FILTER (WHERE p.owner_id = :viewer) AS mine
             FROM star_system s
             LEFT JOIN planet p ON p.system_id = s.id
             WHERE s.galaxy_id = :galaxy AND s.x BETWEEN :minX AND :maxX AND s.y BETWEEN :minY AND :maxY
             GROUP BY s.id
             ORDER BY s.number
             LIMIT :limit',
            [
                'viewer' => $viewer?->getId() ?? 0,
                'galaxy' => $galaxy->getId(),
                'minX' => $minX,
                'maxX' => $maxX,
                'minY' => $minY,
                'maxY' => $maxY,
                'limit' => self::MAX_SYSTEMS,
            ],
        );

        return array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'number' => (int) $row['number'],
            'x' => (float) $row['x'],
            'y' => (float) $row['y'],
            'planets' => (int) $row['planets'],
            'free' => (int) $row['free'],
            'occupied' => (int) $row['occupied'],
            'mine' => (int) $row['mine'],
        ], $rows);
    }

    /**
     * Planètes des systèmes donnés, à leur position globale (centre du système + position locale).
     *
     * @param list<int> $systemIds
     *
     * @return list<array{id: int, system: int, orbit: int, x: float, y: float, address: string, empire: ?string, mine: bool}>
     */
    public function planets(Galaxy $galaxy, array $systemIds, ?Empire $viewer): array
    {
        if ([] === $systemIds) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT p.id, p.system_id, p.orbit, s.x + p.radius * cos(p.angle) AS x, s.y + p.radius * sin(p.angle) AS y,
                    s.number AS system_number, e.name AS empire, e.id AS empire_id
             FROM planet p
             JOIN star_system s ON s.id = p.system_id
             LEFT JOIN empire e ON e.id = p.owner_id
             WHERE s.id IN (:systems)
             ORDER BY s.number, p.orbit',
            ['systems' => $systemIds],
            ['systems' => ArrayParameterType::INTEGER],
        );

        return array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'system' => (int) $row['system_id'],
            'orbit' => (int) $row['orbit'],
            'x' => (float) $row['x'],
            'y' => (float) $row['y'],
            'address' => \sprintf('%d:%d:%d', $galaxy->getNumber(), (int) $row['system_number'], (int) $row['orbit']),
            'empire' => \is_string($row['empire']) ? $row['empire'] : null,
            'mine' => null !== $viewer && (int) $row['empire_id'] === $viewer->getId(),
        ], $rows);
    }
}
