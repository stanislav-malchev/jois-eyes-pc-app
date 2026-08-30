# Joi's Eyes PC Sync Service

PC-side companion for the Joi's Eyes Android app. Receives location + Health
Connect telemetry batches over Tailscale, stores them in SQLite, and exposes
an admin panel to browse the data. See `CLAUDE.md` and `docs/` for the full
design/contract if you need it — this file is just "how do I run/operate
this thing."

**Before anything else:** code comments in this repo frequently say "see the
LLM wiki's concepts/X.md" or "features/X.md" without saying where that is —
it's `/root/.llmwiki` (also reachable from Windows at
`\\wsl.localhost\Joi\root\.llmwiki`), a separate interlinked-markdown wiki
covering this whole box, not just this repo. Start at its `index.md`. See
`AGENTS.md` for how this repo and the wiki relate.

## Is it running right now?

```
systemctl status joiseyes
```

`Active: active (running)` means it's up. Or just hit it directly:

```
curl http://127.0.0.1:9091/v1/health
```

## Where is it, and why does it start on its own?

It runs as a systemd service, unit file at:

```
/etc/systemd/system/joiseyes.service
```

(This file lives only on this machine — it's host config, not part of this
git repo.) It runs:

```
php -S 0.0.0.0:9091 -t public public/router.php
```

as the `www-data` user, with `WorkingDirectory` set to this repo. It's
`router.php`, not `index.php` directly — the router script lets the
built-in server serve real static files itself and only hands off to
`index.php` for actual app requests. Pointing `ExecStart` at `index.php`
breaks static assets (EasyAdmin's CSS/JS/fonts silently fail to load) —
see "Stack decisions" in `CLAUDE.md` for why.

**Why it survives a WSL restart:** `/etc/wsl.conf` has `[boot] systemd=true`,
which makes WSL boot a real init system (systemd) inside the Linux
distribution instead of just dropping you into a shell. When systemd starts,
it brings up every service that's *enabled* — and this one is, via:

```
systemctl enable joiseyes
```

That command's only effect is creating a symlink:

```
/etc/systemd/system/multi-user.target.wants/joiseyes.service -> /etc/systemd/system/joiseyes.service
```

`multi-user.target` is the "normal running system" milestone systemd reaches
on every boot, and it starts everything symlinked into its `.wants/`
directory. That symlink is the entire autostart mechanism — nothing else
runs it, there's no cron job or Windows Task Scheduler entry involved.

The unit also has an `ExecStartPre` line that re-applies `chown -R
www-data:www-data var/` on every start. That's a self-healing guard: if
`var/` ever ends up owned by `root` again (e.g. someone ran `composer` or
`bin/console` as root), the SQLite file becomes unwritable by the service
user and every sync fails with "attempt to write a readonly database" — this
line fixes that automatically on the next start/restart instead of leaving
you to debug it again.

## Common operations

```
systemctl status joiseyes        # is it up, since when, last log lines
systemctl restart joiseyes       # bounce it (e.g. after `git pull` / code changes)
systemctl stop joiseyes          # stop it now, but it WILL still start on next boot
sudo journalctl -u joiseyes -f   # live log tail
sudo journalctl -u joiseyes -n 100   # last 100 lines
```

There is no separate database service to start — SQLite is just a file
(`var/data_dev.db`), read/written directly by the PHP process.

## Disabling autostart

Systemd doesn't use comments/config toggles for this — "enabled" literally
*is* that one symlink mentioned above, so disabling just removes it:

```
systemctl disable joiseyes
```

That stops it from starting on the next boot, but does **not** stop it if
it's currently running. To also stop it right now:

```
systemctl disable --now joiseyes
```

You can still start it manually any time after that (`systemctl start
joiseyes`) — `disable` only opts it out of the automatic boot sequence, it
doesn't remove or break anything.

### To remove it entirely

```
systemctl disable --now joiseyes
sudo rm /etc/systemd/system/joiseyes.service
systemctl daemon-reload
```

## Changing the port or bind address

Edit the `ExecStart=` line in `/etc/systemd/system/joiseyes.service`, then:

```
systemctl daemon-reload
systemctl restart joiseyes
```

It currently binds `0.0.0.0:9091` (all interfaces, not just Tailscale) —
fine for a single-user tailnet setup, but worth tightening later if this box
is ever reachable from outside it (see "Stack decisions" in `CLAUDE.md`).

## Re-importing Xiaomi fitness data (e.g. after swapping wearable/apps)

Historical Mi Fitness/Xiaomi data (steps, heart rate, sleep, spo2, etc.) gets
loaded through a three-step pipeline, all via `php bin/console`:

```
app:import:xiaomi-csv <path-to-csv>   # CSV -> records_import (staging)
app:merge:records-import              # records_import -> records
```

`app:import:xiaomi-csv` reads the Mi Fitness "fitness_data" CSV export
(`Uid,Sid,Key,Time,Value,UpdateTime` columns), converts the types that
overlap with Health Connect (`steps`/`heart_rate`/`sleep`/`spo2`) into the
same shape `records` already uses, buckets steps/heart-rate into 30-minute
windows to match Health Connect's granularity, and — because the CSV export
is a full cumulative history and Xiaomi's own sync pipelines have changed
over the years — dedupes *within* the import itself wherever more than one
source covers the same `(type, start_time)`. `app:merge:records-import`
then copies `records_import` into `records`, deduping *again* against
whatever's already live there (real phone syncs, or rows from an earlier
merge), so nothing gets double-counted. Both commands are safe to re-run —
they upsert by `record_uid`, never duplicate.

Both support `--dry-run` to preview counts without writing anything, and
`app:import:xiaomi-csv` also takes `--limit=N` for a quick trial run on the
first N CSV rows. Full run time on a ~2M-row export is well under a minute
for either command.

### If you've swapped the wearable and/or the Mi Fitness/Xiaomi apps

A new device or app shows up in the CSV as a new `Sid` value (the `source`
column) — possibly with new `Key` values (the `type` column) too if it
tracks something the current setup doesn't. Before re-importing:

1. **Truncate the staging table** so it reflects only the new export, not a
   mix of old and new (the CSV is a full history dump each time, not an
   incremental diff). There's no `sqlite3` CLI on this box — use Doctrine's
   own `dbal:run-sql` instead (add `--env=prod` for the prod db, see
   `CLAUDE.md`):
   ```
   php bin/console dbal:run-sql "DELETE FROM records_import"
   php bin/console dbal:run-sql "DELETE FROM sqlite_sequence WHERE name='records_import'"
   ```

2. **Check for a new `Sid`** you haven't seen before — `--dry-run` only
   reports row counts, not which Sids are in the file, so inspect the CSV
   directly instead:
   ```
   awk -F',' 'NR>1{print $2}' <path> | sort -u
   ```
   then compare against `App\Enum\RecordSource`'s Xiaomi cases
   (`MI_FITNESS_APP`, `XIAOMI_SPORTS_APP`, `XIAOMI_HLTH_GEN`,
   `XIAOMI_WEAR_APP_MANUALLY`). A genuinely new `Sid` needs:
   - a new case added to `RecordSource` (value = the raw `Sid` string;
     label = whatever you actually called that device/app — the label is
     just for the admin UI, safe to guess and fix later),
   - a decision in `ImportXiaomiCsvCommand::SOURCE_PRIORITY` about where it
     ranks — **this is a judgment call, not something derivable from the
     data**: when two sources disagree on the same window, the higher-
     priority one's numbers are the ones that stay live. The new
     device/app is probably what should rank highest going forward (it's
     now "the current source of truth"), but confirm rather than assume —
     the existing three sources' data genuinely differs from each other
     window to window, it's not just repeated noise.

3. **Check for a new `Key`** (type) the same way:
   ```
   awk -F',' 'NR>1{print $3}' <path> | sort -u
   ```
   and compare against `App\Enum\RecordType`. Unknown types are still
   imported fine (stored verbatim, never
   rejected), but won't have a friendly label or show up in the admin
   filter dropdown until added. If it's conceptually the same thing
   `records` already has a Health Connect type for (like `steps`/
   `heart_rate`/`sleep`/`spo2` were), it's worth normalizing the same way:
   add it to `RecordType`, `ImportXiaomiCsvCommand::TYPE_MAP`, and write a
   payload transform (see `transformSleepPayload()`/`transformSpo2Payload()`
   for examples) so it merges cleanly instead of sitting side-by-side with
   its Health Connect equivalent forever.

4. **Run the import for real**, then **run the merge**:
   ```
   php bin/console app:import:xiaomi-csv <path>
   php bin/console app:merge:records-import
   ```

5. `var/data_dev.db` (or the prod equivalent) won't shrink on its own after
   a truncate — SQLite doesn't reclaim space from `DELETE`. Run `VACUUM` if
   you want the file size back:
   ```
   php bin/console dbal:run-sql "VACUUM"
   ```
