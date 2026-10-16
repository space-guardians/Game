<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261016193211 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rapports de combat (#209) : propriétaire de la planète défendue';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE combat_report ADD defended_by_id INT DEFAULT NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE
              combat_report
            ADD
              CONSTRAINT FK_6BAB49ED9B4AC53 FOREIGN KEY (defended_by_id) REFERENCES empire (id) ON DELETE
            SET
              NULL NOT DEFERRABLE
        SQL);
        $this->addSql('CREATE INDEX IDX_6BAB49ED9B4AC53 ON combat_report (defended_by_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE combat_report DROP CONSTRAINT FK_6BAB49ED9B4AC53');
        $this->addSql('DROP INDEX IDX_6BAB49ED9B4AC53');
        $this->addSql('ALTER TABLE combat_report DROP defended_by_id');
    }
}
