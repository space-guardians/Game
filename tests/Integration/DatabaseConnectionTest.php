<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DatabaseConnectionTest extends KernelTestCase
{
    public function testConnectsToPostgreSql(): void
    {
        $connection = self::getContainer()->get(Connection::class);

        $version = $connection->fetchOne('SELECT version()');

        self::assertIsString($version);
        self::assertStringStartsWith('PostgreSQL', $version);
    }
}
