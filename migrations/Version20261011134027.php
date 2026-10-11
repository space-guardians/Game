<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011134027 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Combat : butin du pillage dans les rapports de combat';
    }

    public function up(Schema $schema): void
    {
        // Rapports existants : aucun butin noté
        $this->addSql('ALTER TABLE combat_report ADD plunder JSON DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE combat_report ALTER plunder DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE combat_report DROP plunder');
    }
}
