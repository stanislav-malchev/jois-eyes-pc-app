<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Optional WiFi-first place resolution for CurrentStateTool (see the LLM
 * wiki's concepts/current-state-tool.md) — null until populated via
 * /admin/named-location.
 */
final class Version20260829131152 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable wifi_ssid column to named_location';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE named_location ADD wifi_ssid VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE named_location DROP wifi_ssid');
    }
}
