<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261015161314 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Modération (#78) : bannissement des comptes, sourdine du chat';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE empire ADD chat_muted_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD banned_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD ban_ends_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD ban_reason VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE empire DROP chat_muted_until');
        $this->addSql('ALTER TABLE "user" DROP banned_at');
        $this->addSql('ALTER TABLE "user" DROP ban_ends_at');
        $this->addSql('ALTER TABLE "user" DROP ban_reason');
    }
}
