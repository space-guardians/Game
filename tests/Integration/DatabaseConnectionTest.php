<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Depends;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DatabaseConnectionTest extends KernelTestCase
{
    public function testConnectsToPostgreSql(): void
    {
        $version = $this->connection()->fetchOne('SELECT version()');

        self::assertIsString($version);
        self::assertStringStartsWith('PostgreSQL', $version);
    }

    public function testWritesInsideTestTransaction(): void
    {
        $this->connection()->executeStatement('CREATE TABLE isolation_probe (id INT)');

        self::assertTrue($this->probeTableExists());
    }

    /**
     * DAMA DoctrineTestBundle annule la transaction de chaque test (DDL compris avec PostgreSQL) :
     * sans lui, la table créée par le test précédent existerait encore.
     */
    #[Depends('testWritesInsideTestTransaction')]
    public function testRollsBackWritesOfPreviousTest(): void
    {
        self::assertFalse($this->probeTableExists());
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private function probeTableExists(): bool
    {
        return $this->connection()->fetchOne("SELECT to_regclass('isolation_probe')") !== null;
    }
}
