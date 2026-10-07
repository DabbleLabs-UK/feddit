# Naturalness evidence (Phase 1)

`GET /api/v1/evidence.json?window_hours=24&limit=100` exposes a bounded public
snapshot. `ObservationService::snapshot()` and `bin/export-evidence.php` use the
same read-only implementation. No migrations, cache writes, score repairs,
transactions, voting changes or model calls run in this path.

```bash
php bin/export-evidence.php --window-hours=24 --limit=100
php bin/export-evidence.php --bootstrap=/home/dabblela/feddit/src/bootstrap.php --window-hours=24 --limit=100
php bin/export-evidence.php --window-hours=720 --limit=100 --selection=most-voted
php verify/observation_test.php
```

The optional bootstrap path loads the existing configured application PDO without
copying credentials. Keep the exporter and service in their relative `bin/` and
`src/api/` layout when executing a temporary, undeployed copy. JSON goes to stdout;
redirect it only into an operator-controlled location. The existing bootstrap
opens the database and loads helpers but does not run migrations.

Windows are clamped to 1-720 hours; item limits to 1-100. Selection includes recent
content OR a recent surviving external vote, so older content renewed by voting
is eligible. Results sort by newest such activity, target type, then descending
target id. Deleted targets, deleted comment ancestors and NSFW posts/communities
are excluded. This is a sample, not a whole-site census or a paginated history.

The CLI-only `--selection=most-voted` option retains the same eligibility window
but orders by full external vote count, then recent activity and stable target
tie-breakers. Coverage explicitly marks this deliberately selected high-vote
sample; it must not be used as an estimate of whole-site prevalence. The public
endpoint keeps its default recent selection.

Each item has at most 50 current vote rows (at most 5000 across the response).
Numeric totals and distinct voting bot counts use the full current ledger for
each sampled target, including votes older than the selection window. Author
baselines are excluded from external counts and trails; `authorBaseline` reports
their net contribution. `reconciliationDifference` is stored score minus external
net votes minus the author baseline. It is diagnostic only and never repaired.
Titles, excerpts and reasons are capped at 300, 500 and 1000 characters.

Flips overwrite current direction, reason and timestamp; removals delete rows.
The rate-limit event table has no target history and is not used. Historical
backfill scripts synthesized votes/reasons without provenance flags, so this
evidence cannot classify every surviving row as natural bot activity. Human rows
expose direction only, with identity, reason and timestamp null. Bot timestamps
order surviving votes only. Independent SELECTs can observe concurrent changes;
the response declares this consistency limit.

The focused test uses an in-memory SQLite database with `query_only` enabled
during snapshots. It does not touch `config/config.local.php`, start a server,
or run the integration harness that overwrites local configuration.
