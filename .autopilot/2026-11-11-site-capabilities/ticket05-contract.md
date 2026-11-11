# Ticket 05 — integration and release evidence

## Module startup order

`Cmteh::applyChanges()` installs the exact 12 additive inventory hooks before running migrations. Hook registration is safe before readiness: native guard readers detect the absence of `cmteh_inventory_product` and preserve legacy behavior until there are managed rows. A failed/incomplete migration therefore cannot leave managed inventory without installed native guards. The authorized existing migrator then runs, in order:

1. `InventorySchema::migrate($pdo, DB_PREFIX)`
2. `InventoryImport::migrate($pdo, DB_PREFIX)`

Both migrations use the same OpenCart PDO connection. Import staging does not run from a constructor/read. Existing 12 hooks are inserted only when absent. The module's existing five `adminEvents()` hooks are still delete/re-added during `applyChanges()` (including the language hook); they are separate from the additive 12 inventory events. No claim is made that the full module upgrade preserves those five event row IDs/bytes.

The supported host operation is staged by the release owner: install candidate code, add the exact 12 inventory hooks while preserving existing event rows, then run schema migration against empty inventory. The target host has no warehouse inventory schema/data yet. Do not use the unvalidated whole-module upgrade there merely to register the new hooks.

## Installed-candidate suite registry

`dev/release_checks.py::INSTALLED_SUITES` registers the inventory core, native guard, import, legacy lead, warehouse RFQ, and recovery suites. Each suite stores installed extension members from the candidate ZIP, verifies installed realpaths and SHA-256, copies the exact workspace test bytes to `/var/www/cmteh-verification/tests`, verifies the private copy, and checks reflected `Cmteh` system classes against the candidate receipt. Warehouse/lead fixtures also receive exact SHA-256 receipts in a private non-symlink directory; no test falls back to executing itself from `/workspace`.

Before the core suite, negative cases require the installed verifier to reject a wrong dependency hash and a test path redirected into `/workspace`. The original seed cleanup and admin business installed-suite guards remain registered with their exact source/dependency checks. No CLI downgrade or expected-hash rewrite was added.

## Recovery fixture

`tests/inventory-recovery-integration.php` accepts only `seed`, `advance`, `verify`, and `cleanup` plus a 12-hex task token. It creates a synthetic native item, warehouses, and opening allocation in the isolated release database; the release harness dumps the database; then it records a completed transfer, published prices/terms, a manual reservation, and an accepted non-reserving warehouse RFQ. After the compatible code upgrade, it compares canonical inventory/history/reservation/lead snapshot hashes and checks exact snapshot values. The database dump is restored only into two uniquely named disposable databases for comparison; the old dump is never restored over the working database. The fixture cleanup removes its unique synthetic lead, inventory records, warehouses, source mapping, and native product.

The expected snapshot is: pre-backup dump has no task-token transfer/reserve commands and no task RFQ; latest dump and working database contain both commands and the warehouse/price snapshot; compatible migration preserves active reserve and immutable history. The approved release/host operations deny baseline-binary rollback once any inventory table exists, including an empty table. Code recovery uses the exact compatible candidate with the current database preserved. The application has no version floor; manual copying outside the approved workflow is unsupported.

## Browser journeys in the release gate

The strict verifier runs two sequential browser journeys after the seeded legacy browser acceptance: `inventory-import-happy.mjs` exercises a real XLSX preview/apply and reserve/release; `warehouse-offers.mjs` exercises storefront A+B selection, cart separation, stale offer rejection, lost-ack replay and preservation of a fresh cart addition. There is no viewport/theme matrix for these warehouse journeys. Both scripts receive fixture paths in `/var/www/cmteh-verification`; the warehouse integration fixture is staged by the installed-suite guard with candidate SHA/reflection receipts, while `inventory-import-fixture.php` is copied and SHA-verified from the exact workspace source to a private test path. Browser source hashes are checked before/after each run, and both scripts perform ownership-scoped fixture cleanup.

## Owner evidence / checks

- `python opencart/dev/runtime.py --runtime-dir D:/codex/temp/cmteh-warehouse-dev-20261111 --port 3190 start --upstream D:/codex/temp/cmteh-admin-final-runtime-20261110-r4/opencart-4.1.0.4.zip --refresh` → installed authorized module upgrade on isolated MySQL 8.4/PHP 8.3.
- `verify-local` will exercise a fresh runtime install plus `inventory_migration(runtime)` before its final strict gate. On existing isolated runtime 3190, `inventory_migration(runtime)` confirmed both schema tables, all 12 hooks, and that a repeat authorized module upgrade leaves group permission rows, all 12 hook rows, and all non-owned event rows unchanged.
- Installed candidate suites on the same isolated runtime: `inventory-integration.php` 45 assertions; `native-inventory-integration.php` 24; `inventory-import-integration.php` 30; `lead-integration.php` 35; `warehouse-offers-integration.php` 18. Each installed receipt reflected only the candidate's classes and hashes. Core guard negative checks rejected wrong hash and workspace path.
- `inventory_browser_acceptance(runtime, tooling)` on local runtime 3190 ran `inventory-import-happy.mjs` then `warehouse-offers.mjs` sequentially → PASS XLSX preview/apply/reserve/release and PASS A+B cart/stale/lost-ack journeys. Both PHP fixture inputs came from the private installed candidate test path or an exact-hash private fixture copy; installed receipts and browser source hashes were rechecked after each run. Both scripts completed their scoped fixture cleanup.
- Manual recovery fixture exercise on runtime 3190: synthetic setup → separate DB dump → transfer/reserve/warehouse RFQ → compatible module upgrade → preservation hash verification → owned fixture cleanup. The final immutable candidate strict verifier and full recovery shell comparison run in the root's clean-clone gate.
- `php -l` for `admin/controller/module/cmteh.php` and `tests/inventory-recovery-integration.php`; Python AST parse for `dev/release_checks.py` and `bin/verify-local`.
- MySQL 5.7 targeted inventory/import/browser evidence from ticket01–04 is retained in `interfaces.md`; it was not rerun because ticket05 changes do not alter the 5.7 domain services. Actual host PHP 8.2 behavior, host deployment, real mail and real stock data remain unverified.
