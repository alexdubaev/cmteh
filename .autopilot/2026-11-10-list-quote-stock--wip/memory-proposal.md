# Proposed additions to owner AGENTS.md

The owner's file is unchanged. These eight short pointers are optional additions:

1. Preserve `smtechno-request`, legacy item serialization/key order/fingerprints and pending-key replay. Canonical source IDs are strings up to160 characters; snapshots must not change accepted-request replay when catalog content changes.
2. The separate list check uses published/store-visible exact SKU matches. Preserve leading zeroes, row order and duplicates. Pasting/uploading/checking never adds rows to the cart; a selected catalog offer needs an explicit add.
3. Quotes have their own tables, private original BLOBs and worker. Never activate legacy Lead SMTP jobs when configuring the quote worker. See `opencart/admin-guide.md` and `opencart/README.md` for private `quote-mail.json`, hosting and loopback-only capture.
4. Lead schema3 accepts supported older schemas. The old schema1 migrator rejects a newer schema; do not weaken its guard or lower the marker. Old read/submit/worker paths do not migrate. Follow the documented code-only/cache-only rollback while preserving current DB and originals.
5. Use UTF-8 before starting Python wrappers, and redact native session tokens at ingest, before any log/stdout. MTA acceptance, local MIME capture and confirmed Inbox delivery are separate evidence.
6. Verified strict command: `python opencart/bin/verify-local --upstream D:/codex/temp/cmteh-admin-final-runtime-20261110-r4/opencart-4.1.0.4.zip --runtime-dir D:/codex/temp/cmteh-list-quote-final-runtime-20261110-r3 --reference-dir D:/codex/temp/cmteh-admin-final-runtime-20261110-r4/evidence --port 3177 --browser-tooling D:/codex/cmteh`. Result185PASS/0FAIL on 7769899. Use isolated task data and cached locked tooling.
7. Read `.autopilot/PROGRESS.md` for current scope, remaining work and the latest run. A run's blind report proves its local runtime; consult its separate host report for the deployed source/config/delivery evidence.
8. Trace changes with the `Autopilot-Run` and `Requirements` trailers; record tested source/PR/deploy SHA and actual checks in the handoff. Inspect history since the last `Autopilot: сборка сдана`, not the entire repository log.
