<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds RecordMetricsExtractor's denormalized cache columns to `records`:
 * data_origin (any type), metric_value/min/max/sample_count (Steps/
 * HeartRate only for now). All nullable, all a derived cache of
 * payload_json — nothing reads them yet, existing rows stay null until
 * a backfill pass (not part of this migration) or a future resync
 * repopulates them via IngestController.
 */
final class Version20260829122510 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add denormalized metric columns (data_origin, metric_value/min/max, sample_count) to records';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE records ADD data_origin VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE records ADD metric_value DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE records ADD metric_min DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE records ADD metric_max DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE records ADD sample_count INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE records DROP data_origin');
        $this->addSql('ALTER TABLE records DROP metric_value');
        $this->addSql('ALTER TABLE records DROP metric_min');
        $this->addSql('ALTER TABLE records DROP metric_max');
        $this->addSql('ALTER TABLE records DROP sample_count');
    }
}
