<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Combat;

use App\Entity\ClassMatchup;
use App\Exception\Combat\InvalidMatchupMatrix;
use App\Repository\ClassMatchupRepository;
use App\Service\Combat\ClassMatchups;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Matrice des classes (§4.7) : contenu de départ, puis édition de la grille complète.
 */
final class ClassMatchupsTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testStartingMatrixIsRockPaperScissors(): void
    {
        $matrix = $this->repository()->matrix();

        self::assertSame(1.5, $matrix->multiplier('interceptor', 'bomber'));
        self::assertSame(0.75, $matrix->multiplier('interceptor', 'cruiser'));
        self::assertSame(1.5, $matrix->multiplier('cruiser', 'interceptor'));
        self::assertSame(1.0, $matrix->multiplier('support', 'capital'));
    }

    public function testSavingTheGridAddsUpdatesAndRemovesPairs(): void
    {
        $grid = $this->repository()->matrix()->toArray();
        $grid['interceptor']['bomber'] = '1,8';   // modifiée, virgule décimale
        $grid['interceptor']['cruiser'] = '1';    // redevient neutre : supprimée
        $grid['capital']['interceptor'] = '';     // vidée : supprimée
        $grid['support']['support'] = '0.5';      // nouvelle

        $changed = $this->editor()->save($grid);

        self::assertSame(4, $changed);
        $matrix = $this->repository()->matrix();
        self::assertSame(1.8, $matrix->multiplier('interceptor', 'bomber'));
        self::assertSame(1.0, $matrix->multiplier('interceptor', 'cruiser'));
        self::assertSame(1.0, $matrix->multiplier('capital', 'interceptor'));
        self::assertSame(0.5, $matrix->multiplier('support', 'support'));
        self::assertSame(1.5, $matrix->multiplier('cruiser', 'interceptor'));
        self::assertSame(0, $this->editor()->save($this->repository()->matrix()->toArray()));
    }

    public function testInvalidValuesRejectTheWholeGrid(): void
    {
        $grid = $this->repository()->matrix()->toArray();
        $grid['interceptor']['bomber'] = '42';
        $grid['bomber']['cruiser'] = 'beaucoup';
        $grid['support']['support'] = '2';

        try {
            $this->editor()->save($grid);
            self::fail('La grille aurait dû être refusée.');
        } catch (InvalidMatchupMatrix $exception) {
            self::assertSame([
                'Intercepteur contre Bombardier : un multiplicateur va de 0,1 à 10.',
                'Bombardier contre Croiseur : un multiplicateur va de 0,1 à 10.',
            ], $exception->violations);
        }
        // Rien n'est enregistré, pas même la case valide
        self::assertSame(1.0, $this->repository()->matrix()->multiplier('support', 'support'));
        self::assertSame(1.5, $this->repository()->matrix()->multiplier('interceptor', 'bomber'));
    }

    public function testMultiplierIsBounded(): void
    {
        $matchup = $this->repository()->findAllWithClasses()[0];

        $this->expectException(\InvalidArgumentException::class);

        $matchup->setMultiplier(ClassMatchup::MAX + 1);
    }

    private function repository(): ClassMatchupRepository
    {
        return self::getContainer()->get(ClassMatchupRepository::class);
    }

    private function editor(): ClassMatchups
    {
        return self::getContainer()->get(ClassMatchups::class);
    }
}
