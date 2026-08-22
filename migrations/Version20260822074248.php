<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260822074248 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'records_import staging table (copy of records schema) for reviewing bulk imports before merging';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE records_import (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, record_uid VARCHAR(255) NOT NULL, source VARCHAR(32) NOT NULL, type VARCHAR(64) NOT NULL, start_time DATETIME NOT NULL, end_time DATETIME DEFAULT NULL, ingested_at DATETIME NOT NULL, received_at DATETIME NOT NULL, deleted BOOLEAN NOT NULL, payload CLOB NOT NULL)');
        $this->addSql('CREATE INDEX idx_records_import_type_start_time ON records_import (type, start_time)');
        $this->addSql('CREATE UNIQUE INDEX uniq_records_import_record_uid ON records_import (record_uid)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE records_import');
    }
}
