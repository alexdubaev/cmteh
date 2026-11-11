# Project memory proposal — warehouse capability

## Pointers
- `docs/architecture.md`: warehouse source-of-truth, legacy marker boundary, RFQ snapshot boundary, and recovery constraint.
- `docs/warehouses.md`: operator workflow, file format, and customer-visible limits.
- `.autopilot/2026-11-11-site-capabilities/interfaces.md` (or the --wip path if rename was deferred): accepted contracts and detailed implementation/release evidence; consult relevant sections before touching those boundaries.

## Durable traps
- `cmteh_inventory_product` is the managed/legacy boundary. Before its migration exists, readers retain legacy behavior; do not infer managed state from native quantity or a partially completed migration. For managed items, native stock/availability is a compatibility projection, not authority.
- The first legacy-to-managed cutover requires explicit expected legacy quantity, allocations, and confirmation of any discrepancy. Do not auto-activate from a read, import, or mirror.
- An RFQ stores the selected offer snapshot atomically with the accepted lead. Replays return that snapshot; stale first submissions are rejected. Cart/offer selection, email, and accepted RFQ do not reserve stock. No checkout/order/payment flow is implied.
- Keep public offer DTOs free of physical/reserved quantities and internal warehouse identifiers, address, and notes. Preserve native product identity vs public `source_id` mapping.
- Import is preview then whole-file apply, version checked and idempotent. It updates only listed product×warehouse pairs; it does not create entities, zero omitted pairs, or auto-publish. XLSX article/code cells must remain text to preserve leading zeros.
- Hooks are additive and must preserve unrelated event rows. Upgrade does not grant ACL. Once any inventory table exists—even empty—baseline-binary rollback is disallowed; compatible code recovery keeps the current database. Do not use global DB restore or lower existing markers.
- Host execution/deployment is separate from local evidence. This warehouse release's actual-host functional checks are pending source acceptance; actual execution is recorded in D:/codex/_handoffs/cmteh-warehouses-result-2026-11-11.md. Prior confirmed Inbox/attachment acceptance is retained, not reopened. Treat operational GO and host changes as root-owned release gates.

## Checks with recorded evidence (do not rerun for prose edits)
- Clean clone `62eac91`: `python opencart/bin/verify-local --upstream D:/codex/temp/cmteh-admin-final-runtime-20261110-r4/opencart-4.1.0.4.zip --runtime-dir D:/codex/temp/cmteh-warehouse-final-20261111 --reference-dir D:/codex/temp/cmteh-admin-final-runtime-20261110-r4/evidence --port 3192 --browser-tooling D:/codex/cmteh` (PowerShell passes `--port 3192` as one option/value pair). Exit 0; strict immutable outer `a8520763` / nested `b78dbe14` PASS; fresh subnet `10.231.91.0/28`.
- Clean clone G4 runtime: `python opencart/dev/runtime.py --runtime-dir D:/codex/temp/cmteh-warehouse-dev-20261111 --port 3190 start --upstream D:/codex/temp/cmteh-admin-final-runtime-20261110-r4/opencart-4.1.0.4.zip --refresh` (PowerShell passes `--port 3190`). Installed browser acceptance: 4 import/admin checks + 7 warehouse/cart checks + 2 CSV override cases PASS; G4 active requirements 9/9.
- Targeted domain check: `python opencart/dev/runtime.py --runtime-dir D:/codex/temp/cmteh-warehouse-dev-20261111 --port 3190 compose exec -T web php /workspace/tests/inventory-integration.php` — 45 PASS on MySQL 8.4; recorded check also passed on MySQL 5.7 at port 3191.
- These are recorded local checks, not host verification or deployment. No test or deployment was run for this documentation-only update.

## Правка без автопилота
- Before ordinary edits read `.autopilot/PROGRESS.md`; add the factual change/reason there, preserving deferred scope and known limitations.

## История git
- Source acceptance is traceable through Autopilot-Run/Autopilot-Ticket trailers; one temporary task branch and verified merged PR per task, preserving unrelated work. Real deploy receipts live in the external handoff, not inferred from a source acceptance commit.

