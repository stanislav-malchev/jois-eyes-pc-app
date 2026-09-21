# Finance Feature & MCP Tool Guide

This document defines the architecture, data model, deduplication logic, and MCP tool interface for the Finance and Accounting module in `joiseyes`.

## Overview

The Finance module handles bank statement imports (CSV, e.g., DSK Bank), receipt OCR parsing, line item extraction, product canonicalization, and financial reconciliation ("The Game" accountability reports).

**Key Rules & Currency**:
- **Default Currency**: EUR (not a flag).
- **Pre-2026 Conversion**: Bank CSV statement imports with dates before January 1st, 2026 (which are in BGN) have currency flagged as `BGN` and are automatically converted to EUR using the official fixed exchange rate of `1 EUR = 1.95583 BGN`.

## Core Data Model

- **Account**: Bank accounts (current, credit card, virtual card, savings).
- **Transaction**: Bank statement transaction row with SHA-256 `fingerprint` for deduplication.
- **Receipt**: Physical or digital receipt linked optionally to a `Transaction`.
- **LineItem**: Individual items from a receipt linked to a canonical `Product`.
- **Product**: Canonical product archetype (`lifetime_quantity`, `lifetime_spend_bgn`).
- **Category**: Expense/income category.

## Deduplication & Soft Deletion

- **Fingerprint**: SHA-256 hash of normalized key fields (date, amount, description, reference) to prevent duplicate transaction imports.
- **Soft Deletion**: Internal transfers (own accounts, credit card revolving payments) are marked `soft_deleted = true` with a `soft_delete_reason` (`own_transfer`, `credit_card_revolving`, `loan_disbursement`) to maintain correct spending totals.

## Finance MCP Tools (`FinanceTool`)

Exposed via `App\MCP\Tools\FinanceTool` at `/mcp`:

- `report`: Run the Accountability Report ("The Game") for a given date range (`start_date`, `end_date`).
- `list_unmatched_receipts`: List receipts not linked to any bank transaction.
- `list_unprocessed_receipts`: List receipts linked to a transaction but missing detailed line items.
- `product_history`: Get purchase history and lifetime statistics for a specific product (`product_name`).
- `merge_products`: Merge two products (`product_name` -> `target_product_name`), re-linking all line items.
- `link_receipt`: Manually link a receipt (`receipt_id`) to a transaction (`transaction_id`) via UUIDs.
- `import_csv`: Import bank statement CSV file into an account (`file_path`, `account`).
- `process_receipts`: Ingest receipt OCR JSON data and link receipts/line items (`ocr_json_path` or `file_path`).

## Developer Guidelines

1. **LLM Wiki Reference**: Consult `/root/.llmwiki/finance-import-concept.md` for background context on architectural decisions and DSK CSV quirks.
2. **MCP Service Registration**:
   - Ensure new tools are listed in `config/packages/klp_mcp_server.yaml`.
   - Ensure explicit `public: true` in `config/services.yaml` to prevent Symfony container compilation removal.
   - Run `php bin/console cache:clear` after updating service or MCP configurations.
3. **Testing**: Run tests with `vendor/bin/phpunit` and test MCP tools via `php bin/console mcp:test-tool finance`.
