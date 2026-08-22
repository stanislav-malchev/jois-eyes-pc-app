# JoisEyes — Overview

## Purpose
JoisEyes is a single-user, sideloaded Android app for one specific device
(Samsung Galaxy S22, One UI, Android 14/15). It has one job: collect personal
telemetry and deliver it to a service on the owner's PC over Tailscale.

Data collected:
- Location (fused: GPS when available, network-assisted otherwise)
- Health Connect data: heart rate, steps, sleep (and any other readable types;
  the pipeline is type-agnostic)

Explicit non-goals:
- No multi-user support, no Play Store compliance, no server discovery,
  no account system, no cloud. The PC endpoint address is configured manually.
- No data visualization beyond a status/diagnostics screen.

## Key decisions (already made — do not revisit)
1. Local buffer is SQLite via Room, not CSV.
2. Records are stored generically: typed metadata columns + full JSON payload.
   Adding a new health metric must require no phone-side schema change.
3. Sync is HTTP POST of JSON batches over the tailnet. Plain HTTP is acceptable
   because Tailscale provides encryption and identity.
4. Synced records are kept and marked with `synced_at`, never deleted on send.
   Optional retention cleanup may delete old synced rows.
5. Ingest and sync are decoupled: the phone must operate indefinitely with the
   PC offline, accumulating data locally.
6. Health Connect ingestion uses the Changes API (token-based), not time-range
   re-querying.
7. All periodic work uses WorkManager. No foreground service in v1.

## Documents
- 01-architecture.md — components and data flow
- 02-data-model.md — Room schema and payload JSON formats
- 03-android-plan.md — milestone-by-milestone implementation plan
- 04-pc-sync-api.md — **contract for the PC service; self-contained spec**
- 05-device-setup.md — S22-specific setup, permissions, battery hardening