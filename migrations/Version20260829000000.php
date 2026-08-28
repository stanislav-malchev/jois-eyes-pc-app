<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * records_import (RecordImport entity) is dropped: its one-off job — staging
 * the Xiaomi CSV import for review before ImportXiaomiCsvCommand/
 * MergeRecordsImportCommand merged it into records — is done, and it isn't
 * kept as an audit trail (decided 29.08.2026, see LLM wiki
 * concepts/consolidation.md open question 5).
 */
final class Version20260829000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop records_import staging table (RecordImport entity removed)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE records_import');
    }

    /**
     * Mirrors the original creation migration's (SQLite-only) column types —
     * fine for the test DB, but note this down() would need adjusting
     * (AUTOINCREMENT/CLOB aren't valid Postgres syntax) before ever running
     * it for real against the live Postgres database.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE records_import (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, record_uid VARCHAR(255) NOT NULL, source VARCHAR(32) NOT NULL, type VARCHAR(64) NOT NULL, start_time DATETIME NOT NULL, end_time DATETIME DEFAULT NULL, ingested_at DATETIME NOT NULL, received_at DATETIME NOT NULL, deleted BOOLEAN NOT NULL, payload CLOB NOT NULL)');
        $this->addSql('CREATE INDEX idx_records_import_type_start_time ON records_import (type, start_time)');
        $this->addSql('CREATE UNIQUE INDEX uniq_records_import_record_uid ON records_import (record_uid)');
    }
}
