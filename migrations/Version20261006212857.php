<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006212857 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Carburant des flottes : réservoirs, panne en route, ravitaillement';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fleet ADD fuel DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE fleet_movement ADD reach DOUBLE PRECISION DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE fleet_order ADD target_fleet_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE fleet ALTER fuel DROP DEFAULT');
        $this->addSql('ALTER TABLE fleet_movement ALTER reach DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fleet DROP fuel');
        $this->addSql('ALTER TABLE fleet_movement DROP reach');
        $this->addSql('ALTER TABLE fleet_order DROP target_fleet_id');
    }
}
