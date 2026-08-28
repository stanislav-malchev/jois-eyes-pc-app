# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

JoisEyes PC-side companion service. A single-user Android app collects
location + Health Connect telemetry and syncs it to this service in batches
over Tailscale (plain HTTP; WireGuard provides the encryption/identity
boundary). This service ingests, stores, and will eventually expose that
data via an admin panel.

**`docs/04-pc-sync-api.md` is the authoritative, self-contained contract**
this service must implement (`POST /v1/ingest`, `GET /v1/health`, auth
header, idempotent upsert-by-`recordUid`, tolerant of unknown record types).
Read it before touching ingest/health code — it is the spec, not background.

`docs/02-data-model.md` describes the phone-side Room schema; its "Suggested
PC-side storage" section (mirrored table: `record_uid` PK, `device`,
`source`, `type`, `start_time`, `end_time`, `ingested_at`, `received_at`,
`deleted`, `payload_json`) is the intended shape for the Doctrine entity —
not a script to copy literally.

The other docs (`00-03`, `05`) describe the Android side and are
background/context only, not requirements for this repository.

## Current repo state

Scaffolded and running. `symfony/framework-bundle`, Doctrine ORM/migrations,
and EasyAdminBundle are installed; `src/Entity/Record.php` is the mirrored
record table; `IngestController`/`HealthController`/`PingController` cover
`/v1/*`; `Controller/Admin/` has the EasyAdmin dashboard + CRUD browser.
**Dev/prod DB is PostgreSQL 16** (system package, cluster `main` on
`127.0.0.1:5433`, database `joiseyes`, user `root`/trust — no password; DSN
lives in the gitignored `.env.dev.local`, migrated off SQLite 28.08.2026).
`.env`'s committed `DATABASE_URL` is a dummy placeholder only — don't treat
it as live config. **Tests still deliberately use SQLite**
(`var/data_test.db`, set explicitly in `.env.test`) — don't "fix" that.

## Running it

Runs under WSL2 as a systemd service (`systemd=true` already set in
`/etc/wsl.conf`, confirmed present):

- Unit file: `/etc/systemd/system/joiseyes.service` (not tracked in this
  git repo — it's host config, lives only on this machine).
- Runs `php -S 0.0.0.0:9091 -t public public/router.php` as `www-data`.
  Deliberately **not** `symfony server:start` — its local proxy on this
  machine was found to always report HTTP 200 to clients regardless of the
  app's real status code, which broke the phone's success/failure
  detection. Plain `php -S` relays status codes correctly.
- Router script is `public/router.php`, **not** `public/index.php`
  directly. When `php -S` is given a router script, it sets
  `SCRIPT_FILENAME` to whatever static file was requested once that file
  exists on disk; Symfony's `autoload_runtime.php` then does `require
  $_SERVER['SCRIPT_FILENAME']` expecting the app-returning closure, and
  instead gets raw asset bytes back — a Fatal `TypeError`, plus the
  response ships with the wrong `Content-Type` (`text/html`), which is why
  browsers silently drop EasyAdmin's CSS/JS/fonts. `router.php` checks
  `is_file()` on the mapped path and returns `false` to let the built-in
  server serve real static files itself, falling through to
  `require __DIR__.'/index.php'` only for actual app requests. Don't point
  `ExecStart` back at `index.php` directly.
- `ExecStartPre=+chown -R www-data:www-data var/` runs on every start
  (even though the unit runs as `www-data`, the `+` prefix escalates just
  this line to root) — guards against `var/` reverting to root ownership
  if someone runs `composer`/`bin/console` as root, which previously caused
  "attempt to write a readonly database" errors.
- `systemctl {status,restart,stop} joiseyes` / `journalctl -u joiseyes -f`
  for day-to-day operation. It's enabled, so it comes up on every WSL boot.
- **Database service:** system PostgreSQL 16 (`pg_lsclusters` → cluster
  `main` on port 5433), managed independently of `joiseyes.service` — check
  it's `online` if `/v1/health` fails to connect. (This replaces an earlier
  "SQLite is just a file, nothing to start" assumption — no longer true for
  dev/prod, only for the test env.)

## Stack decisions already made (do not revisit)

- Symfony + Doctrine ORM. **PostgreSQL 16** storage for dev/prod (migrated
  off SQLite 28.08.2026; tests still run on SQLite, see above), mirroring
  the envelope in `docs/04-pc-sync-api.md`.
- Listens on port 9091, all interfaces (`0.0.0.0`) — not yet restricted to
  the Tailscale interface (`docs/04-pc-sync-api.md`'s suggested port 8787
  and interface-only binding are not implemented; revisit if this box gets
  exposed beyond the tailnet).
- **No auth token** — the `X-Auth-Token` requirement described in
  `docs/04-pc-sync-api.md` was deliberately removed at the user's explicit
  request (single-user tailnet, "I am the only user"). `/v1/*` accepts any
  request. Don't reintroduce it without being asked.
- **No `device` field** — `docs/04-pc-sync-api.md`'s envelope has a
  `device` field, but the real Android app's envelope omits it, and it
  wasn't useful in a single-phone setup, so it was removed entirely at the
  user's request: no `Record::$device` column, `IngestController` doesn't
  parse it, `RecordRepository::upsertByRecordUid()` doesn't take it. If
  the envelope includes it anyway, it's silently ignored (unknown-field
  tolerance). `sentAt` is read but not required either.
- All datetime columns (`start_time`, `end_time`, `ingested_at`,
  `received_at`) are stored in **UTC**, matching the phone's ISO-8601 `Z`
  timestamps and PHP's `date.timezone=UTC` — this is correct/intentional,
  not a bug. The admin panel (EasyAdmin `DateTimeField::setTimezone()` in
  `RecordCrudController`, and the `|date(...)` filter in
  `templates/admin/dashboard.html.twig`) converts to `Europe/Sofia` for
  display only. If times look off in the admin UI, check the display-side
  timezone conversion before suspecting the stored value.
- Admin panel over the same Doctrine entities via EasyAdminBundle at
  `/admin`, intentionally unauthenticated, with a stats dashboard and a
  read-only `Record` browser (new/edit/delete disabled — the phone is the
  only writer). Design entities so this stays easy (plain Doctrine
  entities/repositories, no ad-hoc raw-SQL access paths that would need to
  be duplicated for EasyAdmin).

## MCP layer

`klapaudius/symfony-mcp-server` (v1.9) exposes an MCP server at `/mcp`
(streamable HTTP + SSE transports) so an LLM agent can query synced data.
Same trust model as the rest of `/v1/*` and `/admin` — **no auth**,
tailnet-only, single user.

- `config/packages/klp_mcp_server.yaml` — server config, tool list.
  Written by hand (the vendored default config file in
  `vendor/klapaudius/symfony-mcp-server/src/Resources/config/packages/klp_mcp_server.yaml`
  has a stale key, `ping.enable` — the actual schema wants
  `ping.enabled`). Uses the `cache` SSE adapter, not `redis` — this box has
  no Redis (see "no separate database service" above).
- `config/routes.yaml` imports `@KlpMcpServerBundle/Resources/config/routes.php`.
- Tools live in `src/MCP/Tools/`, implement `StreamableToolInterface`, and
  must be listed in `klp_mcp_server.yaml`'s `tools:`. They're resolved by
  class name at runtime by the bundle's `ToolRepository`
  (`$container->get($toolClassName)`), not wired in as a constructor
  `Reference` — Symfony's container would otherwise treat them as unused
  private services and compile them away, so each tool has an explicit
  `public: true` override in `config/services.yaml`. Don't drop that when
  adding a tool.
- `daily-vitals-summary` — steps + avg heart rate for today
  (`Europe/Sofia` calendar day), aggregated from `StepsRecord` /
  `HeartRateRecord` payloads via `RecordRepository::findByTypeSince()`.
  `exercise_minutes` / `perceived_stress` are hardcoded `null` — there's no
  workout-session or stress record type yet. When sleep/exercise records
  start syncing (planned), extend this tool rather than adding a new one;
  the output shape is meant to stay stable as fields go from null to real.
- `location-context` — near/away-from-home check using the latest
  `location`-source record vs. `HOME_LATITUDE`/`HOME_LONGITUDE` (in
  `.env.local`, gitignored — real coordinates must never land in `.env` or
  get committed). 500m haversine radius to absorb GPS drift.
- Test tools from the CLI without a real MCP client:
  `php bin/console mcp:test-tool --list` /
  `php bin/console mcp:test-tool <name>`.

## API contract summary (see docs/04-pc-sync-api.md for full detail — but see the deviations above)

- `POST /v1/ingest` — batch upsert by `recordUid`; a record with
  `payload == {"deleted": true}` is a tombstone (soft-delete, keep the row);
  per-record validation failures go in `rejected`, never fail the whole
  batch; response `{ackedUids, rejected}`; phone only marks synced on
  `ackedUids`. An empty `records` array is accepted as a no-op (200), not
  rejected.
- `GET /v1/health` — `{status, time, recordsStored}`.
- `GET /v1/ping` — manual smoke-test route; inserts one record and returns
  a plain-text summary, so you can hit it from a phone browser.
- `type` is an open set — unknown types must be stored verbatim, never
  rejected.
- Batches: up to 1000 records; expect bursts after PC downtime.
- Batch-level rejections (bad JSON / missing envelope fields) are logged
  to `var/log/ingest_rejected.log` with the raw body, for debugging.
