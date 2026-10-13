<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261013130429 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rapports automatiques (#84) : catégorie des messages du jeu, lien vers l’écran concerné';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE conversation ADD category VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD link VARCHAR(255) DEFAULT NULL');
        // Messages du jeu déjà envoyés : des annonces
        $this->addSql("UPDATE conversation SET category = 'annonce' WHERE is_system = true");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE conversation DROP category');
        $this->addSql('ALTER TABLE message DROP link');
    }
}
