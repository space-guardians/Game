<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002194647 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Double authentification des comptes d\'administration';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE admin_user ADD totp_secret VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE admin_user ADD totp_confirmed BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE admin_user DROP totp_secret');
        $this->addSql('ALTER TABLE admin_user DROP totp_confirmed');
    }
}
