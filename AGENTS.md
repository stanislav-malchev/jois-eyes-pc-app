# AGENTS.md

Orientation for any coding agent (Claude Code, or others) working in this
repository.

## Start here

1. `README.md` — how to run/operate the service day-to-day.
2. `CLAUDE.md` — this repo's own conventions, stack decisions, and
   deviations from the docs. Authoritative for *this repo's* code.
3. **The LLM wiki**, `/root/.llmwiki` (also reachable from Windows at
   `\\wsl.localhost\Joi\root\.llmwiki`) — a separate interlinked-markdown
   wiki covering this whole box's setup across multiple projects, not just
   this repo. Read its `index.md` first; it catalogs every page.

## Why the wiki matters here

Dozens of comments in this codebase (migrations, `src/Service/PcState/*`,
`src/Service/CurrentState/*`, `src/Command/ConsolidateRecordsCommand.php`,
`src/Backdoor/SnapshotClient.php`, `.env`, tests) say things like "see the
LLM wiki's concepts/consolidation.md" or "the wiki page's decoration spec"
without saying where that is. Those pages hold background/design context
that doesn't belong duplicated into this repo (Tailscale topology, the
phone-side backdoor contract, the PC-presence-agent prototype, the
services-map for the whole box, etc.). If a comment references the wiki and
you need that context, go read it there rather than guessing.

Relevant wiki pages as of 30.08.2026 (check `index.md` for the current
list — this repo doesn't own the wiki and it will drift):

- `concepts/consolidation.md` — the consolidation scheduler plan
- `concepts/pc-presence-agent.md` — the Windows `/idle` agent this repo's
  `PcState/*` classes talk to
- `concepts/services-map.md` — port/unit inventory for the whole box
- `concepts/pc-sync-contract.md` — the `/v1/ingest` contract (mirrors
  `docs/04-pc-sync-api.md` in this repo)
- `concepts/snapshot-contract.md` / `raw/joiseyes/joiseyes-backdoor-v1.md` —
  the phone's `/v1/snapshot` backdoor contract this repo's
  `src/Backdoor/SnapshotClient.php` consumes
- `features/current-state-tool.md`, `features/pc-state-tool.md`, `features/finance-feature.md` — design
  notes and skill guides for the MCP tools in `src/MCP/Tools/` (see also
  `docs/finance-feature.md` in this repo; note: default currency is EUR (not a flag), and pre-2026 CSV import values in BGN before Jan 1st 2026 are flagged as BGN and automatically converted to EUR using the official fixed exchange rate of 1 EUR = 1.95583 BGN).

## Division of labor

- **This repo's `CLAUDE.md`/`docs/`** — what this service does and how,
  including deliberate deviations (no auth token, no `device` field, etc.).
  Don't move that content into the wiki.
- **The wiki** — cross-project/background knowledge and design history.
  `raw/` pages there are immutable; corrections go in `entities/`/`concepts/`
  pages, not by editing `raw/`.
- Don't copy wiki content into this repo wholesale, and don't copy this
  repo's `CLAUDE.md` into the wiki — link, don't duplicate.
