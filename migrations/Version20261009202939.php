<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009202939 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Quêtes : statut brouillon / publiée / archivée à la place de « active »';
    }

    public function up(Schema $schema): void
    {
        // Les quêtes actives restent en jeu (publiées), les autres sont retirées (archivées)
        $this->addSql('ALTER TABLE quest_template ADD status VARCHAR(20) DEFAULT \'draft\' NOT NULL');
        $this->addSql('UPDATE quest_template SET status = CASE WHEN active THEN \'published\' ELSE \'archived\' END');
        $this->addSql('ALTER TABLE quest_template ALTER status DROP DEFAULT');
        $this->addSql('ALTER TABLE quest_template DROP active');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quest_template ADD active BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('UPDATE quest_template SET active = (status = \'published\')');
        $this->addSql('ALTER TABLE quest_template ALTER active DROP DEFAULT');
        $this->addSql('ALTER TABLE quest_template DROP status');
    }
}
