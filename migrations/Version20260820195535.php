<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260820195535 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TEMPORARY TABLE __temp__records AS SELECT id, record_uid, source, type, start_time, end_time, ingested_at, received_at, deleted, payload FROM records');
        $this->addSql('DROP TABLE records');
        $this->addSql('CREATE TABLE records (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, record_uid VARCHAR(255) NOT NULL, source VARCHAR(32) NOT NULL, type VARCHAR(64) NOT NULL, start_time DATETIME NOT NULL, end_time DATETIME DEFAULT NULL, ingested_at DATETIME NOT NULL, received_at DATETIME NOT NULL, deleted BOOLEAN NOT NULL, payload CLOB NOT NULL)');
        $this->addSql('INSERT INTO records (id, record_uid, source, type, start_time, end_time, ingested_at, received_at, deleted, payload) SELECT id, record_uid, source, type, start_time, end_time, ingested_at, received_at, deleted, payload FROM __temp__records');
        $this->addSql('DROP TABLE __temp__records');
        $this->addSql('CREATE UNIQUE INDEX uniq_records_record_uid ON records (record_uid)');
        $this->addSql('CREATE INDEX idx_records_type_start_time ON records (type, start_time)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE records ADD COLUMN device VARCHAR(64) NOT NULL');
    }
}
