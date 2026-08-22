# Architecture

## Data flow

    [Health Connect] ──► HealthConnectIngestWorker ──┐
                                                     ├──► Room DB (records) ──► SyncWorker ──► HTTP over Tailscale ──► PC service
    [Fused Location] ──► LocationIngestWorker ───────┘

Three independent stages:

1. **Ingest** — periodic WorkManager jobs pull new data and insert rows into
   the `records` table. Each stage is idempotent (unique keys prevent dupes).
2. **Store** — Room/SQLite is the single source of truth on the phone.
3. **Sync** — a periodic WorkManager job drains unsynced rows in batches to the
   PC endpoint and marks them synced only after an explicit server ack.

## Package layout (single Gradle module `:app`)

    com.joiseyes.app
    ├── data/            Room: entities, DAOs, database, SyncStateStore
    ├── ingest/
    │   ├── HealthConnectIngestWorker.kt
    │   ├── LocationIngestWorker.kt
    │   └── payload/     mappers: HC record -> canonical JSON payload
    ├── sync/
    │   ├── SyncWorker.kt
    │   └── SyncClient.kt        (OkHttp + kotlinx.serialization)
    ├── settings/        DataStore-backed app settings (endpoint URL, toggles)
    ├── ui/              Compose status screen + permission flows
    └── AppScheduler.kt  WorkManager scheduling (called from Application class)

## Scheduling

| Work                     | Type            | Interval | Constraints          |
|--------------------------|-----------------|----------|----------------------|
| HealthConnectIngestWorker| PeriodicWork    | 15 min   | none                 |
| LocationIngestWorker     | PeriodicWork    | 15 min   | none                 |
| SyncWorker               | PeriodicWork    | 15 min   | NETWORK_CONNECTED    |
| RetentionWorker (optional)| PeriodicWork   | 24 h     | device idle          |

All periodic work is enqueued with `ExistingPeriodicWorkPolicy.UPDATE` from
`Application.onCreate`, plus a `BOOT_COMPLETED` receiver is NOT needed
(WorkManager persists across reboots).

The status screen also offers "Run now" buttons that enqueue one-shot
`OneTimeWorkRequest`s of the same workers for manual testing.

## Error philosophy
- Workers never crash the app; every failure path returns `Result.retry()` or
  `Result.success()` with the error recorded in a `worker_log` table shown on
  the status screen.
- Sync failure of any kind leaves `synced_at = NULL` — data is never lost,
  only delayed.