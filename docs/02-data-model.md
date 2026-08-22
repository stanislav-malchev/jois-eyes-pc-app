# Data model

## Room database `joiseyes.db`, version 1

### Table: `records`

| column       | type    | notes                                              |
|--------------|---------|----------------------------------------------------|
| id           | INTEGER | PK autoincrement                                   |
| record_uid   | TEXT    | UNIQUE, NOT NULL. HC record UID for Health Connect rows; `loc-<UUIDv4>` for location rows |
| source       | TEXT    | `health_connect` \| `location`                     |
| type         | TEXT    | e.g. `HeartRate`, `Steps`, `SleepSession`, `LocationFix` |
| start_time   | INTEGER | epoch millis UTC, NOT NULL                         |
| end_time     | INTEGER | epoch millis UTC, nullable (instant records: NULL) |
| payload      | TEXT    | canonical JSON, see below                          |
| ingested_at  | INTEGER | epoch millis UTC, NOT NULL                         |
| synced_at    | INTEGER | epoch millis UTC, NULL = pending sync              |

Indexes: UNIQUE(record_uid); INDEX(synced_at); INDEX(type, start_time).

Deleted/updated HC records: when the Changes API reports an update, upsert by
`record_uid` (replace payload, reset `synced_at` to NULL so the change is
re-sent). When it reports a deletion, keep the row but replace payload with
`{"deleted": true}` and reset `synced_at` to NULL. The PC service handles
tombstones (see API doc).

### Table: `sync_state`

| column | type | notes                                  |
|--------|------|-----------------------------------------|
| key    | TEXT | PK. Known keys: `hc_changes_token`      |
| value  | TEXT |                                         |

### Table: `worker_log` (diagnostics only, capped at 200 rows)

| column     | type    |
|------------|---------|
| id         | INTEGER PK |
| at         | INTEGER epoch millis |
| worker     | TEXT    |
| level      | TEXT (`info`/`error`) |
| message    | TEXT    |

## Canonical JSON payloads

All payloads are objects. Field names are camelCase. All timestamps inside
payloads are ISO-8601 UTC strings (e.g. `2026-08-20T07:15:00Z`). The
top-level Room columns hold epoch millis for querying; the payload holds the
full fidelity record.

### type = `LocationFix` (source = `location`)
    {
      "time": "2026-08-20T07:15:00Z",
      "latitude": 42.6977,
      "longitude": 23.3219,
      "accuracyMeters": 12.5,
      "altitudeMeters": 560.0,        // optional
      "speedMps": 0.0,                // optional
      "provider": "fused"
    }

### type = `HeartRate` (one row per HC HeartRateRecord)
    {
      "startTime": "...", "endTime": "...",
      "samples": [ { "time": "...", "beatsPerMinute": 62 }, ... ],
      "dataOrigin": "com.sec.android.app.shealth"
    }

### type = `Steps`
    { "startTime": "...", "endTime": "...", "count": 512,
      "dataOrigin": "..." }

### type = `SleepSession`
    {
      "startTime": "...", "endTime": "...",
      "title": null,
      "stages": [ { "startTime": "...", "endTime": "...",
                    "stage": "deep" | "light" | "rem" | "awake" | "sleeping"
                             | "out_of_bed" | "awake_in_bed" | "unknown" } ],
      "dataOrigin": "..."
    }

### Other Health Connect types
The mapper covers HeartRate, Steps, SleepSession explicitly. Any additional HC
record type the app is granted permission for is serialized with a generic
reflective mapper into a best-effort JSON object, with `type` set to the HC
record class simple name (e.g. `OxygenSaturation`). The PC side must tolerate
unknown types (store-and-ignore).

### Tombstone (any type)
    { "deleted": true }