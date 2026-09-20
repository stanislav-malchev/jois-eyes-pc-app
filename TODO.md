- move to a postgres DB? (Done)
- host on GitHub. Squish all to single commit without DB first
- mobile app: add oxygen and rebuild

### Finance / Accounting (Phase 2 Complete)
- [x] Database entities for Finance (Account, Transaction, etc.)
- [x] Bank CSV Importer with fingerprinting and internal transfer detection
- [x] CLI command `app:finance:import`
- [x] Phase 2: Receipt OCR integration and Product linking
  - [x] ReceiptOcrProcessor for structured OCR ingestion
  - [x] ReceiptTransactionLinker for matching receipts to bank flow
  - [x] ProductLinker for LineItem parsing and auto-categorization
  - [x] CLI command `app:finance:process-receipts`
- [x] Phase 3: Reconciliation dashboard and variance analysis
  - [x] Accountability Report ("The Game") logic
  - [x] CLI command `app:finance:report`
  - [x] Finance MCP tools for review and manual overrides
