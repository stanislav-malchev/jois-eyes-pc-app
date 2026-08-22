# Android implementation plan

Execute milestones in order. Each has a Definition of Done (DoD). Do not start
a milestone before the previous one's DoD passes on the physical S22.

## Milestone 0 — Project plumbing
- Add version catalog entries and dependencies:
    - Room (runtime, ktx, compiler via KSP)
    - WorkManager (work-runtime-ktx)
    - Health Connect client (`androidx.health.connect:connect-client`)
    - Play services location (`com.google.android.gms:play-services-location`)
    - OkHttp, kotlinx-serialization-json
    - DataStore preferences
- Add KSP and kotlinx-serialization Gradle plugins.
- Create `Application` subclass, register in manifest, call `AppScheduler`.
- minSdk 29+, targetSdk latest installed.
- DoD: app builds, installs, empty status screen shows.

## Milestone 1 — Storage layer
- Implement Room database, entities, DAOs per 02-data-model.md.
- DAO operations needed:
    - `insertIgnore(record)` / `upsertResetSync(record)`
    - `pendingBatch(limit)` — `WHERE synced_at IS NULL ORDER BY id LIMIT :limit`
    - `markSynced(ids, now)`
    - counts: total, pending, by type; last ingested_at; last synced_at
    - sync_state get/put; worker_log insert + trim + latest N
- Unit tests for DAO logic (in-memory Room).
- Status screen v1: shows counts (total / pending), last ingest, last sync,
  last 20 worker_log lines.
- DoD: tests pass; screen shows zeros on device.

## Milestone 2 — Location ingest
- Permissions flow in UI: ACCESS_FINE_LOCATION, then ACCESS_BACKGROUND_LOCATION
  (separate step, sends user to settings as Android requires).
- `LocationIngestWorker`: FusedLocationProviderClient.getCurrentLocation with
  PRIORITY_BALANCED_POWER_ACCURACY, 30 s timeout; on success insert a
  `LocationFix` record with `record_uid = "loc-" + UUID`.
- Schedule 15-min periodic work + "Run now" button.
- DoD: pressing "Run now" adds a row; periodic rows appear over an hour with
  screen off.

## Milestone 3 — Health Connect ingest
- Manifest: HC permissions for HeartRate, Steps, SleepSession read +
  `READ_HEALTH_DATA_IN_BACKGROUND`; HC intent filter/queries entries required
  by the connect-client library.
- UI: availability check (HC installed/updated), permission request launcher.
- `HealthConnectIngestWorker` algorithm:
    1. token = sync_state[`hc_changes_token`]; if absent, do an initial backfill
       (readRecords per type, last 30 days) then `getChangesToken` and store it.
    2. else `getChanges(token)` loop over pages: map upserts via payload mappers
       and `upsertResetSync`; map deletions to tombstones; store `nextChangesToken`.
    3. If token expired (HC throws), log and restart from step 1 backfill of the
       last 7 days (UNIQUE record_uid makes overlap harmless).
- Payload mappers per 02-data-model.md, including generic fallback mapper.
- DoD: after a walk + a night of sleep, Steps/HeartRate/SleepSession rows exist
  and match Samsung Health values approximately.

## Milestone 4 — Sync
- Settings screen fields (DataStore): endpoint base URL
  (e.g. `http://pc.tailnet.ts.net:8787`), shared token string, batch size
  (default 500), sync enabled toggle.
- `SyncClient` implements the contract in 04-pc-sync-api.md exactly:
  POST /v1/ingest with the batch envelope; parse acks; treat non-2xx and
  malformed responses as retryable failures.
- `SyncWorker`: loop { batch = pendingBatch(500); if empty stop; POST; on ack
  markSynced(ackedIds) } with max 10 batches per run; NETWORK_CONNECTED
  constraint; exponential backoff.
- Status screen: pending count, last sync result, "Sync now" button.
- DoD: with a stub server (any HTTP echo implementing the ack), pending drains
  to 0 and re-running sends nothing.

## Milestone 5 — Hardening & retention
- `REQUEST_IGNORE_BATTERY_OPTIMIZATIONS` prompt from the status screen.
- Status screen shows warnings if: battery-optimized, background location
  missing, HC permissions missing, endpoint unset.
- Optional `RetentionWorker`: delete synced rows older than N days (default:
  disabled / keep forever).
- Soak test: 7 days unattended; DoD: no gaps > 1 h in location rows while the
  phone was on and connected, and HC data keeps flowing.

## Testing notes for the agent
- Unit-test: payload mappers (given fake HC records → expected JSON),
  DAO queries, SyncClient against OkHttp MockWebServer (ack parsing, partial
  ack, 500 retry, malformed JSON).
- Do not attempt instrumentation tests for HC/location; verify on device
  manually via the status screen.