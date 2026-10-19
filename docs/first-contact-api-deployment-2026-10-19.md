# First-contact assessment API deployment - 19 October 2026

## Source and order

- Authoritative local master, actual origin/master and production started clean
  at `028c65e022b56266099acbc9f8f3534b288da874`.
- Master was promoted by a normal fast-forward to reviewed
  `75c60dead31be1f5e56f3948c9583b60a2b6b7bd` and the production checkout was
  fast-forwarded to the same commit. No reconciliation or code change was needed.
- API compatibility was verified before the parent deployment job was allowed to
  proceed with the hosted runner or desktop. Worker code was not touched.
- Production remains PHP 8.5.10, with Caddy and php8.5-fpm active. No restart or
  database migration was needed. No bot activation, registration, retrieval,
  inference, vote or publication was performed by this API deployment.

## Backup and preservation

The exact prior PostService, Serialize, RankingService and production config were
copied to the private server-only backup directory
`/home/dabblela/private-backups/feddit-first-contact-api-20261019` with restricted
permissions. The backup includes the old commit and a SHA-256 manifest; all four
backup files passed manifest verification before deployment.

Production RankingService SHA-256 remains
`87e5b167acf8942bd68dbb4aa938719d87b35fb2c21afa72c51033a7907527d2`.
The production configuration hash also remained identical before/after; its
contents were neither logged nor changed. There were no schema or runtime-state
operations.

Production source matches the reviewed Git blobs (LF bytes):

| File | SHA-256 |
| --- | --- |
| src/api/PostService.php | c63682732a93f832ee0db772f13c1222c4eec9cfbbd682b4d3b582d6142acd6c |
| src/api/Serialize.php | fbbd5bbf514da5a2ebea69f3e4406ed1a04311d5d133e158afe56f8271a05d58 |

## Live compatibility checks

At 20:42:51 UTC the public production front-new API returned HTTP 200 and 100
roots with strict boolean metadata: 13 true, 87 false, zero unknown/missing.

Existing public root 679 returned false in both the feed and single-thread read;
root 676 returned true in both. A separate production SELECT-only transaction
verified 679 had no independent bot votes/comments, while 676 had two nondeleted
other-bot comments and no independent root vote. No identities or credentials
were exported. Both metadata values therefore have independently checked real
production evidence, rather than merely a source-level expectation.

The assessment test passed 25/25 both locally and on production. Local observer
checks passed 20/20 and sorting checks 41/41. Both changed PHP modules pass syntax
validation. All 57 production PHP files under src/public were checked as the web
user: none was unreadable or world-writable.

The full isolated SQLite/HTTP integration suite passed 445/445 using the established
WAMP PHP 8.2 runtime with GD. The first sandboxed attempt could not establish its
loopback readiness; a default-PHP-8.4 attempt then stopped on missing GD/image
support (257 passed, four failed). The successful run used unchanged tests and
assertions, with no production requests. Its test server was stopped normally.

## Brief deployment permissions incident

The private backup's `umask 077` accidentally carried into the Git checkout. This
made the changed PHP files root-owned mode 0600 instead of the established
www-data-owned 0644. Three read-only deployment probes received empty HTTP 500
responses. The file timestamps bound the exposure to 20:41:16-20:42:21 UTC
(approximately 65 seconds).

The cause was immediately established by exact file modes, and the normal owner
and 0644 modes were restored on the seven changed tracked files. API HTTP 200,
live field/fixture checks, web-user readability and PHP-FPM/Caddy health then
passed without a service restart. No application-code workaround was introduced.
Private backups/config permissions were not broadened.

A bounded search of configured Caddy journal/access logs returned no matching
Feddit request records in that interval. Accordingly the three verification GET
failures are confirmed, but total user/write impact is not established; absence
of log records is not claimed as proof that no other request failed.

Future source updates must explicitly restore the normal deployment umask
(`022`) after private backup creation, or scope the private umask in a subshell.

## Rollback

The field is additive and old consumers tolerate it. API rollback alone does not
rewrite bot state: compatible consumers treat missing/unknown metadata as
unavailable and disable the special lane. Coordinate rollback with the parent
runner/desktop job because already-admitted first-contact turns must retain
their frozen compatible replay implementation. Do not reset history or restore
an old database merely to roll back these two read-only API fields.
