<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds RecordMetricsExtractor's closest_to_id column to `records`: the
 * NamedLocation id nearest a LocationFix row's lat/lon (see
 * NamedLocationRepository::findClosestAmong()), same idea/lifecycle as
 * latitude/longitude/accuracy_meters from Version20260830171524 — a plain
 * id, not a foreign key, since it's a derived cache recomputed on every
 * extract() call rather than a real relation.
 */
final class Version20260830173416 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add denormalized closest_to_id column to records';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE records ADD closest_to_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE records DROP closest_to_id');
    }
}
