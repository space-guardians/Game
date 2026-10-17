<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261017072948 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Antimatière en cargaison et au marché (#214)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE fleet ADD cargo_antimatter DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE market_listing ADD offered_antimatter DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE market_listing ADD asked_antimatter DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE merchant_holding ADD antimatter DOUBLE PRECISION DEFAULT 0 NOT NULL');
        // Une annonce peut ne proposer ou ne demander que de l'antimatière
        $this->addSql('ALTER TABLE market_listing DROP CONSTRAINT market_listing_amounts');
        $this->addSql('ALTER TABLE market_listing ADD CONSTRAINT market_listing_amounts CHECK (offered_metal >= 0 AND offered_crystal >= 0 AND offered_deuterium >= 0 AND offered_antimatter >= 0 AND asked_metal >= 0 AND asked_crystal >= 0 AND asked_deuterium >= 0 AND asked_antimatter >= 0 AND offered_metal + offered_crystal + offered_deuterium + offered_antimatter > 0 AND asked_metal + asked_crystal + asked_deuterium + asked_antimatter > 0)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE market_listing DROP CONSTRAINT market_listing_amounts');
        $this->addSql('ALTER TABLE market_listing ADD CONSTRAINT market_listing_amounts CHECK (offered_metal >= 0 AND offered_crystal >= 0 AND offered_deuterium >= 0 AND asked_metal >= 0 AND asked_crystal >= 0 AND asked_deuterium >= 0 AND offered_metal + offered_crystal + offered_deuterium > 0 AND asked_metal + asked_crystal + asked_deuterium > 0)');
        $this->addSql('ALTER TABLE fleet DROP cargo_antimatter');
        $this->addSql('ALTER TABLE market_listing DROP offered_antimatter');
        $this->addSql('ALTER TABLE market_listing DROP asked_antimatter');
        $this->addSql('ALTER TABLE merchant_holding DROP antimatter');
    }
}
