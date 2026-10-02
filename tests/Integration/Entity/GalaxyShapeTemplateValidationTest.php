<?php

declare(strict_types=1);

namespace App\Tests\Integration\Entity;

use App\Entity\GalaxyShapeTemplate;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Les bornes de validation du gabarit garantissent qu'il produit toujours une forme acceptée par l'algorithme.
 */
final class GalaxyShapeTemplateValidationTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, int|float}>
     */
    public static function extremeValidValues(): iterable
    {
        yield '1 branche' => ['arms', 1];
        yield '12 branches' => ['arms', 12];
        yield 'branches droites' => ['armTightness', 0.0];
        yield 'branches les plus fines' => ['armWidth', 0.05];
        yield 'plus petit bulbe' => ['coreRadius', 100.0];
        yield 'plus petit disque' => ['diskScale', 500.0];
        yield 'vide entre les branches' => ['interArmDensity', 0.0];
        yield 'aussi dense entre les branches' => ['interArmDensity', 1.0];
    }

    #[DataProvider('extremeValidValues')]
    public function testExtremeValidValuesProduceUsableShape(string $property, int|float $value): void
    {
        $template = $this->template($property, $value);

        self::assertCount(0, self::getContainer()->get(ValidatorInterface::class)->validate($template));
        $template->toShape();
    }

    /**
     * @return iterable<string, array{string, int|float}>
     */
    public static function invalidValues(): iterable
    {
        yield 'aucune branche' => ['arms', 0];
        yield '13 branches' => ['arms', 13];
        yield 'enroulement négatif' => ['armTightness', -1.0];
        yield 'branches sans largeur' => ['armWidth', 0.0];
        yield 'bulbe nul' => ['coreRadius', 0.0];
        yield 'disque nul' => ['diskScale', 0.0];
        yield 'densité négative' => ['interArmDensity', -0.1];
        yield 'densité supérieure à 1' => ['interArmDensity', 1.5];
    }

    #[DataProvider('invalidValues')]
    public function testRejectsOutOfRangeValues(string $property, int|float $value): void
    {
        $violations = self::getContainer()->get(ValidatorInterface::class)->validate($this->template($property, $value));

        self::assertCount(1, $violations);
        self::assertSame($property, $violations->get(0)->getPropertyPath());
    }

    private function template(string $property, int|float $value): GalaxyShapeTemplate
    {
        $template = new GalaxyShapeTemplate('Gabarit de test');
        $template->{'set' . ucfirst($property)}($value);

        return $template;
    }
}
