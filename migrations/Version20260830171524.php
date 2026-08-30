<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds RecordMetricsExtractor's denormalized LocationFix columns to
 * `records`: latitude/longitude/accuracy_meters, same idea as the Steps/
 * HeartRate metric_* columns from Version20260829122510 — nullable, a
 * derived cache of payload_json, null until IngestController/
 * ConsolidateRecordsCommand next call extract() on each row.
 */
final class Version20260830171524 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add denormalized location columns (latitude, longitude, accuracy_meters) to records';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE records ADD latitude DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE records ADD longitude DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE records ADD accuracy_meters DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE records DROP latitude');
        $this->addSql('ALTER TABLE records DROP longitude');
        $this->addSql('ALTER TABLE records DROP accuracy_meters');
    }
}
