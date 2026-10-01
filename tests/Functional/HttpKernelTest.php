<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HttpKernelTest extends WebTestCase
{
    public function testUnknownRouteReturnsNotFound(): void
    {
        $client = self::createClient();
        $client->request('GET', '/route-inexistante');

        self::assertResponseStatusCodeSame(404);
    }
}
