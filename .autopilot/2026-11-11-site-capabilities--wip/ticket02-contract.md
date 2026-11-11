# Ticket 02 — native stock guards

Owned implementation complete; core Inventory files unchanged. Guard adds no DB columns/tables/permission keys/dependencies or automatic grants. Ticket02 explicit installer migration calls `InventorySchema::migrate($pdo, DB_PREFIX)` under the pre-existing install/upgrade permission gate; reads do not migrate or activate products. Ticket05 integration must explicitly require ticket03's `InventoryImport.php` and call `InventoryImport::migrate($pdo, DB_PREFIX)` on the same PDO after InventorySchema, before the full candidate. This mandatory wiring belongs to ticket05 (depends on02+03+04), keeping standalone ticket02 independent from the not-yet-committed ticket03 class. No conditional skip/no-op is permitted. Import-preview staging schema belongs to ticket03's contract, not a second guard schema.

## Actual native transaction boundary

`Cmteh\InventoryNativeGuard::__construct(object $db,string $prefix)` receives the **registry native DB object**, not PDO. `begin(string $purpose): void`, `finish(bool $success): bool`, `active(): bool`, `run(array $ids,callable $callback): mixed`, `lock(array $ids): array`, `available(int $id): int`, `projectionDeferred(): bool`; static `rejectManaged(array $states): void`; static `assertDeletable(PDO $pdo,string $prefix,int $id): void` for the already-transactional CMTEH deletion path.

Native wrappers lock native product rows numerically ascending, then inventory controls in the same order. The available aggregate is a **current locking read**, ordered by warehouse ID; product mutex prevents concurrent stock changes. No separate PDO is opened until the native commit. Loader route redirection changes the route delivered to the original after-event, so wrappers explicitly call `CmtehCatalog::synchronize` with the original add/edit/delete route **after** commit. Nested original-model proxy calls suppress projection until the outer wrapper completes. Descriptions/native fields survive stale quantity submissions. Managed copy is an unpublished ordinary product with quantity zero and no inherited inventory. Managed deletion, shared-stock variant creation/editing and subtracting-option introduction are rejected. Legacy operations remain native.

Managed operations inside a foreign, pre-existing native transaction fail before writes with `InventoryException(reason=unsupported_native_transaction,status=409)`; that caller retains its transaction. Guard-owned nesting (copy→add, full form→option value) remains supported. Legacy foreign transactions join a savepoint and defer separate-PDO projection while the caller transaction is still open; a native catalog sync warning asks for a postcommit save. A later explicit `synchronize` after that caller commits detects the closed transaction and can proceed. `native_busy` 409 rejects a second prepared scope rather than committing another operation. `managed_stock` 409 is the ordinary protected-path denial. No caller transaction is committed/rolled back wholesale by the guard.

Native order `addHistory` guard runs at priority **-10000**, before native mail/activity/statistics priority zero. It starts the actual native transaction, locks order-product rows plus sorted product/master identities, rejects any managed identity before downstream hooks, then redirects to a wrapper which calls the original native model and commits/rolls back. A later before-event exception is propagated; registered scope-owned shutdown fallback rolls back when the wrapper is never reached. No real mail event/transport is invoked by tests.

## Additive events (12)

All added only if their exact event code is absent; original events are not deleted/rewritten for these additions. Uninstall removes these owned codes. `A=extension/cmteh/event/cmteh_catalog`; `C=extension/cmteh/event/cmteh_inventory`.

| Code | Trigger | Action | Sort |
|---|---|---|---:|
| cmteh_inventory_delete_batch | admin/controller/catalog/product.delete/before | A.guardProductDelete | -10000 |
| cmteh_inventory_native_form | admin/view/catalog/product_form/before | A.inventoryForm | 1000 |
| cmteh_inventory_order_guard | catalog/model/checkout/order.addHistory/before | C.guardOrder | -10000 |
| cmteh_inventory_native_addProduct | admin/model/catalog/product.addProduct/before | A.guardProductModel | -10000 |
| cmteh_inventory_native_editProduct | admin/model/catalog/product.editProduct/before | A.guardProductModel | -10000 |
| cmteh_inventory_native_copyProduct | admin/model/catalog/product.copyProduct/before | A.guardProductModel | -10000 |
| cmteh_inventory_native_deleteProduct | admin/model/catalog/product.deleteProduct/before | A.guardProductModel | -10000 |
| cmteh_inventory_native_addVariant | admin/model/catalog/product.addVariant/before | A.guardProductModel | -10000 |
| cmteh_inventory_native_editVariant | admin/model/catalog/product.editVariant/before | A.guardProductModel | -10000 |
| cmteh_inventory_native_addOptionValue | admin/model/catalog/product.addOptionValue/before | A.guardProductModel | -10000 |
| cmteh_inventory_native_editQuantity | catalog/model/catalog/product.editQuantity/before | C.guardQuantity | -10000 |
| cmteh_inventory_native_editOptionQuantity | catalog/model/catalog/product.editOptionQuantity/before | C.guardQuantity | -10000 |

Redirected model routes are `extension/cmteh/catalog/inventory_guard.{addProduct,editProduct,copyProduct,deleteProduct,addVariant,editVariant,addOptionValue}` in Admin and `extension/cmteh/catalog/inventory_guard.{editQuantity,editOptionQuantity}` plus `extension/cmteh/checkout/inventory_guard.addHistory` in Catalog. Controller batch redirect is `extension/cmteh/event/cmteh_catalog.inventoryDelete`, with explicit logged user + native `modify catalog/product` guard; it retains native deletion logic after whole-batch preflight. No global ACL bypass/new permission grants. Native stock readonly summary links to existing `extension/cmteh/module/cmteh_catalog.inventory` only for existing `access extension/cmteh/module/cmteh_catalog`; the form's descriptive fields remain editable. Native URL helper receives an argument array containing current session user_token and native product_id, with its raw-JavaScript URL flag enabled; JSON_HEX_AMP safely serializes raw ampersands for JSON-to-DOM href assignment without literal `&amp;` query keys.

CMTEH `CatalogAdmin::deleteRecord` checks managed history before routes/relations/deletion. Existing CMTEH controller deletes one record per confirmed request (no second unguarded CMTEH batch API). Existing `CatalogWrites` deliberately does not update quantity; actual `CatalogImport::preview/apply` regression confirms metadata imports cannot reset balances/reserves/versions/native mirror. NativeCatalog no longer derives stored legacy availability from the managed compatibility mirror; ticket04 owns effective public availability.

## Checks and evidence

`python opencart/dev/runtime.py --runtime-dir D:/codex/temp/cmteh-warehouse-dev-20261111 --port 3190 compose exec -T web php /workspace/tests/native-inventory-integration.php` — **24 PASS**, exit 0, actual installed OpenCart Loader/Factory/Event/models + native Cart User/isolated synthetic user groups + real MySQL8.4/PHP8.3.

Same command with `--runtime-dir D:/codex/temp/cmteh-warehouse-mysql57-20261111 --port 3191` — **24 PASS**, exit 0, MySQL5.7.44/PHP8.3.36. Not actual target PHP8.2 or final immutable candidate/host verification. Both sites received only explicitly coordinated owned-file copies plus four frozen Inventory core files/CatalogInput; no whole-package refresh or partial ticket03/04 copy. Tests require installed paths via Reflection and matching SHA256 for 17 owned/stable dependencies. Test runner is workspace-mounted; app classes are exclusively installed paths (no workspace fallback).

RED before guard code: actual installed native `editProduct` wrote posted 999 over explicitly managed 10; assertion `Stale native full form preserves warehouse available` failed. GREEN additionally covers native projection after redirected loader, copy zero/no inventory, direct/mixed-batch deletion, native ACL denial, unchanged legacy full form/direct setters, native two-process stale form blocked on real product mutex then preserved newly reserved available=6, metadata save/import, CMTEH deletion, foreign native transaction refusal, direct option setter, order before-hook spy never called for managed, legacy hook path preserved, later-before-event exception/shutdown rollback, user-group ACL bytes unchanged.

PHP syntax: all nine owned PHP product files + integration script PASS. `node --check` readonly-form script PASS; affected `git diff --check` PASS. No host/config/mail/live-user/real-stock changes. Local fixtures use fresh `ngt_<12hex>_` tables, synthetic users/groups, private temporary NDJSON and child processes; all are removed by suite cleanup.

## Coordinated installed file SHA256 (both runtimes)

| Relative to extension/cmteh | SHA256 |
|---|---|
| system/InventoryNativeGuard.php | 1ceffecc31dad729c54a87a41b78f90444e1381d9b7c7783c16b922a3c4dbf10 |
| admin/model/catalog/inventory_guard.php | 938ef514c7385ad82b0c1d022c2d467d326303feb142c26b62d07718494aa3fb |
| catalog/model/catalog/inventory_guard.php | 3a90cd071578b1ea44db1919de2fb681de8ca25b5c58bc1b8f0f49c75451e2d7 |
| catalog/model/checkout/inventory_guard.php | 73ed9fdd7562d520596e0d8094c894d171303ddf32e96522cc494854e4a91314 |
| catalog/controller/event/cmteh_inventory.php | 171a4d25822e085d746eb3e6e59b14fc03cb8083bbccc06314a07c3317756eb1 |
| admin/controller/event/cmteh_catalog.php | 332ff86bda99f052b79c2984be06dd883873c5a4b9ec157e2bb8fc03622f0987 |
| admin/controller/module/cmteh.php | 3a223cbabb3fdbc24104e36dce780c65a5e9bd13f88ab7e39d4c4382addcf260 |
| catalog/view/assets/inventory-native-admin.js | fae3db63e63aa7a589bd2a8a04b1d1ceb33d12a044093c26129e2be3e589532f |
| system/CatalogAdmin.php | 53bcc060886614a384ca13cb078bb660a09d8f143ac4282d8d2e5a9e3207457d |
| system/NativeCatalog.php | ea0850508b05371e4a25a03dcaac32943c1d7c9596d76cbcf2db13c7c2813204 |

Readonly summary and actual protected navigation now have the narrow browser evidence below. Final full candidate, actual target PHP8.2, existing release/host/recovery safeguards and the one whole-change independent review remain ticket05/root gates. No self-review loop, commits or deployment performed by ticket02.

Integration ownership boundary: ticket03 tests its static staging-DDL migration explicitly, without constructor DDL. Ticket05 owns mandatory Import migrator wiring and the integrated authorized-migrator check before the full candidate. Ticket02 installer is independent from InventoryImport; current installer hash matches the prior 24-PASS receipt. Installed-module PHP syntax PASS after this boundary adjustment; only the module was recopied to3190/3191, with no repeated unchanged domain suite.

Native summary link correction: the initial code incorrectly named a nonexistent standalone inventory route. Confirmed ticket03 entry method is `cmteh_catalog.inventory`. Narrow installed Event fixture `php /workspace/tests/native-inventory-integration.php --form-only` failed before the fix on the expected route assertion, then **3 PASS** on3190 after the initial link correction: actual emitted form JSON uses the existing method/native product ID, URL-helper token encoding and JSON ampersands decode correctly, and missing catalog access produces no link. Installed event PHP syntax, updated fixture PHP syntax, and unchanged JS syntax PASS. Domain files unchanged. The final immutable-candidate strict gate checks the integrated package; the unchanged domain24×2 suite is not repeated for these UI repairs.

Prior 24-PASS ×2 receipt: runner SHA256 `b106d0b4bab00147eda07b1d807e252523781440f602848a941736238d514aa1`; event file SHA256 `cef7fb85d6065e0eb37f66a4cd236e7fe54fd7443bb080b41e3767138a6d1efc`; installer SHA256 `3a223cbabb3fdbc24104e36dce780c65a5e9bd13f88ab7e39d4c4382addcf260`. All domain/wrapper hashes and the installer remain exactly those in the table above. The later form-link change has separate narrow evidence above, so the earlier full native runs are not misrepresented as having executed that later line.

## Allowed static delivery and actual native form closure

Confirmed HTTP RED: `/extension/cmteh/admin/view/javascript/cmteh_inventory_guard.js` returned404, so the readonly script was never executed. The unchanged generic JS moved to `extension/cmteh/catalog/view/assets/inventory-native-admin.js`; emitted URL is `/extension/cmteh/catalog/view/assets/inventory-native-admin.js?v=20261111-1` under the existing allowed static prefix. No .htaccess expansion, authenticated script route, new controller, public config/token/warehouse data, or domain change. Protected JSON configuration remains in native form HTML. The former admin/view JS file is deleted; coordinated copies on3190/3191 contain only the updated event and this moved asset, with the obsolete owned staged JS removed.

`php /workspace/tests/native-inventory-integration.php --form-only` → **4 PASS** on3190 after delivery correction, adding an actual emitted versioned asset URL assertion to the previous three data/ACL checks. `node opencart/tests/browser/native-inventory-form.mjs` → **3 PASS**, exit0: actual HTTP native product form requests the script with200 and `text/javascript`, version20261111-1, quantity input is readonly and equals available10, descriptive field remains editable, and clicking the visible stock link opens ticket03's actual existing protected inventory method with the native product filter retained. Browser uses a disposable native-auth user/group with only access `catalog/product`, `extension/cmteh/module/cmteh_catalog` and modify `catalog/product`; default admin/owner grants remain untouched. The default bootstrap admin has no custom catalog access, and correctly receives no stock link.

Browser fixture reuses ticket04's `--browser-setup/--browser-cleanup` with a fresh owned nonce. Owned integration modes `--form-browser-event-setup/--form-browser-event-cleanup <12hex>` temporarily add only a unique native-form event plus disposable user/group; the event action/trigger/priority come from the actual module declaration. Recovery IDs and before hashes live in a0600 private temporary capture; ephemeral password passes only through process memory/stdin and native login, never arguments/logs. Cleanup uses exact own nonce/IDs/username/group/event definitions, removes only that user's login/session records, and verifies all other native user/group/event rows byte-for-byte unchanged. No persistent inventory hooks are registered. Nested finally cleanup removes both auth/event fixtures and owned synthetic product/warehouse/ledger data on browser failures.

Narrow syntax: updated installed PHP event + fixture PASS; moved JS + browser runner `node --check` PASS. No domain24×2 rerun. Current integration runner SHA256 `a910e811c633e5764d682c49399da2bfd86e1cf3f9af0e25c9ec5161d3074425`; browser runner SHA256 `8aaf9869d63eb6bcd815d0b259bd497f566bc4839a2b2f348e226b3e958bea57`. Product event/asset hashes are in the coordinated table above.
