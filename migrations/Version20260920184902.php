<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260920184902 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE finance_accounts (id UUID NOT NULL, name VARCHAR(255) NOT NULL, iban VARCHAR(255) DEFAULT NULL, bank VARCHAR(255) DEFAULT NULL, currency VARCHAR(10) NOT NULL, type VARCHAR(50) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE finance_categories (id UUID NOT NULL, name VARCHAR(255) NOT NULL, icon VARCHAR(255) DEFAULT NULL, parent_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_848FDB1727ACA70 ON finance_categories (parent_id)');
        $this->addSql('CREATE TABLE finance_line_items (id UUID NOT NULL, description TEXT NOT NULL, quantity NUMERIC(12, 3) DEFAULT 1 NOT NULL, unit VARCHAR(50) DEFAULT NULL, unit_price_bgn NUMERIC(12, 2) DEFAULT NULL, total_bgn NUMERIC(12, 2) DEFAULT NULL, category_hint VARCHAR(255) DEFAULT NULL, receipt_id UUID NOT NULL, product_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_2C2175172B5CA896 ON finance_line_items (receipt_id)');
        $this->addSql('CREATE INDEX IDX_2C2175174584665A ON finance_line_items (product_id)');
        $this->addSql('CREATE TABLE finance_products (id UUID NOT NULL, name VARCHAR(255) NOT NULL, default_unit VARCHAR(50) DEFAULT NULL, typical_price_bgn NUMERIC(12, 2) DEFAULT NULL, lifetime_quantity NUMERIC(12, 3) DEFAULT 0 NOT NULL, lifetime_spend_bgn NUMERIC(12, 2) DEFAULT 0 NOT NULL, tags JSON DEFAULT NULL, first_seen DATE DEFAULT NULL, last_seen DATE DEFAULT NULL, category_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_62ECAF4712469DE2 ON finance_products (category_id)');
        $this->addSql('CREATE TABLE finance_receipts (id UUID NOT NULL, merchant VARCHAR(255) DEFAULT NULL, date DATE DEFAULT NULL, total_bgn NUMERIC(12, 2) DEFAULT NULL, tax_bgn NUMERIC(12, 2) DEFAULT NULL, currency VARCHAR(10) NOT NULL, ocr_source TEXT DEFAULT NULL, ocr_raw_text TEXT DEFAULT NULL, ocr_model VARCHAR(255) DEFAULT NULL, ocr_confidence NUMERIC(5, 4) DEFAULT NULL, status VARCHAR(50) NOT NULL, imported_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, transaction_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CCBD16BF2FC0CB0F ON finance_receipts (transaction_id)');
        $this->addSql('CREATE TABLE finance_transactions (id UUID NOT NULL, date DATE NOT NULL, description TEXT DEFAULT NULL, counterparty TEXT DEFAULT NULL, counterparty_account TEXT DEFAULT NULL, transaction_type TEXT DEFAULT NULL, reference TEXT DEFAULT NULL, debit_bgn NUMERIC(12, 2) DEFAULT NULL, credit_bgn NUMERIC(12, 2) DEFAULT NULL, fingerprint VARCHAR(64) NOT NULL, source_file TEXT DEFAULT NULL, imported_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, soft_deleted BOOLEAN DEFAULT false NOT NULL, soft_delete_reason TEXT DEFAULT NULL, account_id UUID NOT NULL, paired_with_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_E6E13CA59B6B5FBA ON finance_transactions (account_id)');
        $this->addSql('CREATE INDEX IDX_E6E13CA52CB7BFF2 ON finance_transactions (paired_with_id)');
        $this->addSql('CREATE INDEX idx_finance_transactions_fingerprint ON finance_transactions (fingerprint)');
        $this->addSql('CREATE INDEX idx_finance_transactions_date ON finance_transactions (date)');
        $this->addSql('ALTER TABLE finance_categories ADD CONSTRAINT FK_848FDB1727ACA70 FOREIGN KEY (parent_id) REFERENCES finance_categories (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE finance_line_items ADD CONSTRAINT FK_2C2175172B5CA896 FOREIGN KEY (receipt_id) REFERENCES finance_receipts (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE finance_line_items ADD CONSTRAINT FK_2C2175174584665A FOREIGN KEY (product_id) REFERENCES finance_products (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE finance_products ADD CONSTRAINT FK_62ECAF4712469DE2 FOREIGN KEY (category_id) REFERENCES finance_categories (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE finance_receipts ADD CONSTRAINT FK_CCBD16BF2FC0CB0F FOREIGN KEY (transaction_id) REFERENCES finance_transactions (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE finance_transactions ADD CONSTRAINT FK_E6E13CA59B6B5FBA FOREIGN KEY (account_id) REFERENCES finance_accounts (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE finance_transactions ADD CONSTRAINT FK_E6E13CA52CB7BFF2 FOREIGN KEY (paired_with_id) REFERENCES finance_transactions (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE finance_categories DROP CONSTRAINT FK_848FDB1727ACA70');
        $this->addSql('ALTER TABLE finance_line_items DROP CONSTRAINT FK_2C2175172B5CA896');
        $this->addSql('ALTER TABLE finance_line_items DROP CONSTRAINT FK_2C2175174584665A');
        $this->addSql('ALTER TABLE finance_products DROP CONSTRAINT FK_62ECAF4712469DE2');
        $this->addSql('ALTER TABLE finance_receipts DROP CONSTRAINT FK_CCBD16BF2FC0CB0F');
        $this->addSql('ALTER TABLE finance_transactions DROP CONSTRAINT FK_E6E13CA59B6B5FBA');
        $this->addSql('ALTER TABLE finance_transactions DROP CONSTRAINT FK_E6E13CA52CB7BFF2');
        $this->addSql('DROP TABLE finance_accounts');
        $this->addSql('DROP TABLE finance_categories');
        $this->addSql('DROP TABLE finance_line_items');
        $this->addSql('DROP TABLE finance_products');
        $this->addSql('DROP TABLE finance_receipts');
        $this->addSql('DROP TABLE finance_transactions');
    }
}
