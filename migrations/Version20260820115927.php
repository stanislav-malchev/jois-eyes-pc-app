<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260820115927 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE records (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, record_uid VARCHAR(255) NOT NULL, device VARCHAR(64) NOT NULL, source VARCHAR(32) NOT NULL, type VARCHAR(64) NOT NULL, start_time DATETIME NOT NULL, end_time DATETIME DEFAULT NULL, ingested_at DATETIME NOT NULL, received_at DATETIME NOT NULL, deleted BOOLEAN NOT NULL, payload CLOB NOT NULL)');
        $this->addSql('CREATE INDEX idx_records_type_start_time ON records (type, start_time)');
        $this->addSql('CREATE UNIQUE INDEX uniq_records_record_uid ON records (record_uid)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE records');
    }
}
