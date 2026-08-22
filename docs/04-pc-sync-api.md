# PC Sync Service — API Specification (v1)

Audience: the developer of the PC-side service. This document is the complete
contract; no knowledge of the Android codebase is required.

## Context
A personal Android app ("JoisEyes") accumulates telemetry records in a local
queue and periodically pushes them in batches to this service. Transport is
plain HTTP **inside a Tailscale tailnet** (WireGuard provides encryption and
peer identity). The service should listen only on the Tailscale interface.

The service must be:
- **Idempotent** — the phone may re-send records it already sent (e.g. lost
  ack). Upsert by `recordUid`.
- **Tolerant** — unknown `type` values must be accepted and stored, never
  rejected.
- **Available-optional** — the phone retries forever; the service being down
  loses nothing.

## Conventions
- All bodies are JSON, UTF-8, `Content-Type: application/json`.
- All timestamps are ISO-8601 UTC strings: `2026-08-20T07:15:00Z`.
- Authentication: static shared token in header `X-Auth-Token: <token>`.
  Reject with 401 if missing/wrong. (Defense-in-depth only; the tailnet is the
  real boundary.)

## Endpoints

### POST /v1/ingest
Submit a batch of records.

Request body:
{
"device": "s22",                    // fixed device id string
"sentAt": "2026-08-20T07:20:11Z",   // phone's send time
"records": [
{
"recordUid": "hc-uid-or-loc-uuid",   // globally unique, stable
"source": "health_connect",          // or "location"
"type": "HeartRate",                 // open set, see below
"startTime": "2026-08-20T06:00:00Z",
"endTime":   "2026-08-20T06:05:00Z", // null for instant records
"ingestedAt":"2026-08-20T06:16:02Z",
"payload": { ... }                   // type-specific JSON object
}
// 1..1000 records
]
}

Processing rules:
1. Validate auth header, JSON shape, non-empty `records`.
2. For each record, upsert by `recordUid`:
    - new UID → insert
    - existing UID → replace stored payload/metadata (record was updated on
      the phone, e.g. Health Connect edits)
    - `payload == {"deleted": true}` → this is a tombstone; mark the stored
      record as deleted (do not physically remove; keep audit trail).
3. A record failing per-record validation must NOT fail the batch; exclude it
   from acks and report it in `rejected`.

Response 200:
{
"ackedUids": ["uid1", "uid2", ...],   // successfully persisted
"rejected": [                          // optional, may be empty/absent
{ "recordUid": "uidX", "reason": "human-readable reason" }
]
}

The phone marks a record synced **only** if its UID appears in `ackedUids`.
Rejected records will be re-sent on every future sync until acked, so
persistent rejections should be logged loudly on the PC (they indicate a bug
on one side). If the service prefers, it may ack-and-quarantine bad records
instead of rejecting them, to stop the retry loop.

Error responses:
- 401 — bad/missing token. Body optional.
- 400 — unparseable JSON / missing required envelope fields. Phone will retry
  later (it cannot fix the batch, so log this loudly).
- 500/503 — transient server problem; phone retries with backoff.
  Any non-200 response means: nothing in the batch may be considered acked.

### GET /v1/health
Liveness probe used by the phone's status screen.

Response 200:
{ "status": "ok", "time": "2026-08-20T07:20:11Z", "recordsStored": 123456 }

Auth: same header required.

## Record types and payload schemas

The `type` field is an open set. The service MUST store any type. Known types
and their payload schemas (all fields optional unless marked required):

### LocationFix
| field          | type   | required |
|----------------|--------|----------|
| time           | ISO ts | yes      |
| latitude       | number | yes      |
| longitude      | number | yes      |
| accuracyMeters | number | no       |
| altitudeMeters | number | no       |
| speedMps       | number | no       |
| provider       | string | no       |

### HeartRate
| field      | type                                        | required |
|------------|---------------------------------------------|----------|
| startTime  | ISO ts                                      | yes      |
| endTime    | ISO ts                                      | yes      |
| samples    | array of {time: ISO ts, beatsPerMinute:int} | yes      |
| dataOrigin | string (source package name)                | no       |

### Steps
| field      | type   | required |
|------------|--------|----------|
| startTime  | ISO ts | yes      |
| endTime    | ISO ts | yes      |
| count      | int    | yes      |
| dataOrigin | string | no       |

### SleepSession
| field      | type   | required |
|------------|--------|----------|
| startTime  | ISO ts | yes      |
| endTime    | ISO ts | yes      |
| title      | string/null | no  |
| stages     | array of {startTime, endTime, stage} | no |
| dataOrigin | string | no       |

`stage` values: `deep`, `light`, `rem`, `awake`, `sleeping`, `out_of_bed`,
`awake_in_bed`, `unknown`.

### Tombstone (any type)
    { "deleted": true }

### Unknown types
Store envelope metadata + raw payload verbatim. Do not validate the payload.

## Suggested PC-side storage (non-normative)
SQLite table mirroring the envelope:
records(record_uid PK, device, source, type, start_time, end_time,
ingested_at, received_at, deleted, payload_json)
Consumers (the AI assistant pipeline) read from this table; that part is out
of scope for this spec.

## Sizing expectations
- Batch size: up to 1000 records, typically ≤ 500.
- Frequency: roughly every 15 minutes when phone+PC are both up; bursts of
  many batches after the PC was offline for days.
- Volume: order of a few thousand records/day.

## Versioning
Path-versioned (`/v1/...`). Breaking changes require `/v2` and both versions
served during transition (the phone updates rarely).