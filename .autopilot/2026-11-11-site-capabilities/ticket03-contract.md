# Ticket 03 — admin warehouses and spreadsheet import

## Public interfaces

- Admin GET/POST route: `extension/cmteh/module/cmteh_catalog.inventory`; ACL is the existing exact key `extension/cmteh/module/cmteh_catalog` (`access` for reads, `modify` for POST). GET filters: `page`, `search`, `warehouse_id`, `product_id` (native integer, admin-only).
- Generic UI behavior module: `/extension/cmteh/catalog/view/assets/inventory-admin.mjs?v=20261111-2`; it contains no user data, config, prices, or credentials and uses the existing bounded vendor XLSX reader. Script requests do not carry the admin token.
- `InventoryImport::migrate(PDO $pdo,string $prefix): void` is explicit, additive, idempotent migration; it is not called by a constructor, read, or preview.
- `InventoryImport::parseCsv(string $bytes): array` accepts UTF-8/BOM or Windows-1251; canonical header is `article;warehouse_code;quantity;price;delivery_term`, with optional final `source_id` text column for exact SKU disambiguation.
- `InventoryImport::validateXlsxFile(string $path,bool $hasSourceId=false): void` checks bounded ZIP/XML, formula absence and text-typed article/warehouse cells; it does not calculate formulas.
- `InventoryImport::preview(array $rows,array $actor): array` returns `{preview_id,digest,expires_at,summary,errors,rows,page,pages,shown_rows,can_apply}`. Only 100 rows/errors are rendered per page; all rows are validated and stored. Rows carry expected product/legacy/balance/warehouse versions and exact canonical source mapping. `source_id` must identify the same exact SKU; no native integer is accepted as the disambiguator.
- `InventoryImport::apply(string $previewId,string $digest,array $actor,string $requestKey): array` is actor-bound, expires after 30 minutes, applies the complete preview inside one PDO transaction/savepoint, and returns the stored receipt for exact-key replay.
- `InventoryImport::storedPreview(string $previewId,string $digest,array $actor,int $page=1,bool $retryAllowed=true): ?array` reloads a bounded page of the same actor’s preview after an apply conflict or page navigation. Failed apply displays the saved rows and disables a second attempt with the stale digest.
- Import header fields: `article`, `warehouse_code`, absolute integer `quantity`, positive decimal RUB `price` or blank for on-request, optional override `delivery_term` (blank inherits warehouse default), optional final text `source_id`. No missing-row zeroing, fuzzy SKU, or entity creation. Explicit `publish_offers` is included in preview and apply.
- First unmanaged-product import always requires explicit cutover confirmation; a quantity difference also requires an audit reason. Preview shows the previous shared quantity and the per-row warehouse allocation/difference.

## Added staging schema

`cmteh_inventory_import_preview`: `preview_id CHAR(32)` PK; `actor_id INT`; `digest CHAR(64)`; `payload LONGTEXT`; `expires_at DATETIME`; unique nullable `apply_key VARCHAR(128)`; `result JSON`; `applied_at DATETIME`; `created_at DATETIME`; `(actor_id,expires_at)` index. Applied receipts are retained; each preview call removes up to 200 expired unapplied rows. No schema-version marker is introduced.

The existing module migration wiring is intentionally left to ticket 05 per orchestration; local test fixtures call `InventoryImport::migrate` explicitly. Before deployment, ticket 05 must invoke it from the existing authorized module migrator after InventorySchema, then verify a fresh installed candidate.

## Checks and RED proof

- Before implementation, `inventory-import-integration.php` failed as expected with missing `InventoryImport.php`.
- `python opencart/dev/runtime.py --runtime-dir D:/codex/temp/cmteh-warehouse-dev-20261111 --port 3190 compose exec -T web php /workspace/tests/inventory-import-integration.php` → 30 PASS on isolated MySQL 8.4/PHP 8.3, including UTF-8/BOM, CP1251, bounded XLSX formula/leading-zero checks, actor/digest, publish choice, explicit cutover, stale conflict rollback, replay/savepoint and actual 1,000-row preview/apply with 100-row UI pages.
- Same integration command with runtime `D:/codex/temp/cmteh-warehouse-mysql57-20261111`, port `3191` → 30 PASS on isolated MySQL 5.7.44/PHP 8.3.
- PHP syntax: runtime `php -l` for `InventoryImport.php`, `cmteh_catalog.php`, `inventory-import-integration.php` → no syntax errors.
- `node --check opencart/extension/cmteh/catalog/view/assets/inventory-admin.mjs` and `node --check opencart/tests/browser/inventory-admin.mjs` → pass.
- `node opencart/tests/browser/inventory-admin.mjs` on targeted local runtime 3190 → PASS catalog navigation/import form, uploaded invalid CSV row error/focus, desktop+390px layout; PASS direct POST denied for a temporary read-only role.
- `node opencart/tests/browser/inventory-import-happy.mjs` on targeted local runtime 3190 → PASS a real native-admin warehouse-create POST, valid XLSX through the static browser reader and multipart preview POST (HTTP 200), explicit publication selection and whole-preview apply POST; DB and reloaded admin UI confirm destination quantity 9, price `1777.25`, published=true, omitted original warehouse unchanged at quantity 6 / `1000.10` / unpublished, and no automatic product or warehouse creation. The same UI flow reserves 3 (available 9→6) and releases all 3 (available 6→9); exact nonce product, warehouses, ledger, preview, temporary user/group and password file are removed.
- One implementation failure found during the final DB run: a managed row’s native quantity mirror no longer matches its original legacy revision after stock changes. Fixed by checking managed product version and checking legacy hash only before cutover; both DB versions then passed.
- Positive browser XLSX preview initially returned HTTP 422 because native `Request::clean` HTML-escapes POST strings before the JSON row bridge is decoded. The existing controller now applies one matching `htmlspecialchars_decode(..., ENT_COMPAT)` before `json_decode`; the real XLSX preview/apply and stock-operation browser flow then passed. Inventory service and frozen core contracts were unchanged.

## Scope note

No public storefront files, request-import normalizer, mail flow, new dependencies, remote host, or production data were changed. The XLSX reader uses the existing vendored library after bounded archive inspection; server preview validates recognized rows but does not claim a cryptographic raw-file-to-row attestation.
