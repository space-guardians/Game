<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;

final class SymfonyUxSetupTest extends KernelTestCase
{
    public function testTwigExposesComponentAndTurboFunctions(): void
    {
        $twig = self::getContainer()->get(Environment::class);

        self::assertNotNull($twig->getFunction('component'));
        self::assertNotNull($twig->getFunction('turbo_stream_listen'));
        self::assertNotNull($twig->getFunction('stimulus_controller'));
    }

    public function testLiveComponentRouteIsRegistered(): void
    {
        $route = self::getContainer()->get(RouterInterface::class)->getRouteCollection()->get('ux_live_component');

        self::assertNotNull($route);
        self::assertStringStartsWith('/_components/', $route->getPath());
    }
}
