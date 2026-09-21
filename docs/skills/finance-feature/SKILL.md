---
name: finance-feature
description: Use this skill when working on the Finance module, bank statement imports, receipt processing, reconciliation ("The Game"), or Finance MCP tools.
---

### Finance Feature & LLM Wiki Guide

When working on any task involving the finance module (`src/Service/Finance/`, `src/Entity/`, `src/MCP/Tools/FinanceTool.php`, etc.), you must consult the LLM Wiki page at `/root/.llmwiki/finance-import-concept.md`.

#### Key Concepts & Data Model
- **Account**: Bank account with system name, defaulting to EUR currency.
- **Transaction**: Bank statement transaction row with SHA-256 `fingerprint` for deduplication, default currency `EUR` (pre-2026 CSV values in BGN from before Jan 1st 2026 have currency flagged as `BGN` and are automatically converted to EUR using the official fixed exchange rate of `1 EUR = 1.95583 BGN`), and `exchangeRate`.
- **Receipt**: Physical/digital receipt linked optionally to a Transaction.
- **LineItem**: Items from a receipt linked to a canonical Product.
- **Product**: Canonical product archetype (`lifetime_quantity`, `lifetime_spend_bgn`).
- **Category**: Expense/income category.

#### Deduplication & Soft Deletion
- **Fingerprint**: SHA-256 of normalized key fields to prevent duplicate transaction imports.
- **Soft Deletion**: Internal transfers (own accounts, credit card revolving) are marked `soft_deleted = true` with a `soft_delete_reason` so they can be traced without polluting spending totals.

#### Finance MCP Tools (`FinanceTool`)
Exposes tools via MCP for review and reconciliation:
- `report`: Run the Accountability Report ("The Game") for a period.
- `list_unmatched_receipts`: Show receipts not linked to any bank transaction.
- `list_unprocessed_receipts`: Show receipts linked to transactions but missing line items.
- `product_history`: Get purchase history for a specific product by name.
- `merge_products`: Merge two products (`source_name` -> `target_name`).
- `link_receipt`: Manually link a receipt (UUID) to a transaction (UUID).
- `import_csv`: Import bank statement CSV file into an account (`file_path`, `account`).
- `process_receipts`: Ingest receipt OCR JSON data and link receipts/line items (`ocr_json_path` or `file_path`).

#### Guidelines for Developers & Agents
1. Always reference `/root/.llmwiki/finance-import-concept.md` for background context on architectural decisions, DSK CSV quirks, and reconciliation rules.
2. Ensure new finance MCP tools are registered in `config/packages/klp_mcp_server.yaml`, wired in `config/services.yaml` with `public: true`, and tested in `tests/MCP/Tools/FinanceToolTest.php`.
3. Run `php bin/console cache:clear` after updating service/MCP configurations.
