# Admin unification discovery — 2026-11-11

Read-only discovery of OpenCart implementation; no product edits or app checks in this exploration. Target is `opencart/`, not the root Next.js reference. Root AGENTS.md preserves public commerce/data/URL contracts. Repository owner policy permits this task's temporary branch/PR workflow and requires actual affected checks before publication; existing native authentication/ACL and release/host safeguards remain applicable.

## Evidence provenance

- Pinned upstream: OpenCart 4.1.0.4, `opencart/packaging/open-cart.lock.json`.
- Read `D:/codex/temp/cmteh-opencart-cache/opencart-4.1.0.4.zip`; SHA-256 measured as `cdef537f5a0a7e1b4ddbe16f589dd2e80b10150a09bfbe93ce76ef2d5c3218c0`, identical to lock. No archive download/extraction or runtime changes performed.
- Compared extracted cache bytes against this ZIP for `upload/system/engine/loader.php`, `upload/admin/controller/startup/{event,login,permission}.php`, `upload/admin/controller/common/{header,dashboard,column_left,security}.php`, `upload/admin/view/template/common/{header,dashboard}.twig`: every file exactly equal. Therefore shell findings below are authoritative for pinned distribution, not inferred from another OpenCart version.
- Parent also identified same pinned ZIP in `D:/codex/temp/cmteh-admin-final-runtime-20261110-r4/` and already extracted business-audit source at `D:/codex/temp/cmteh-admin-unification-20261111/native-audit/upload/`. Reuse these for implementation; do not repeat extraction.

## Current catalog identity and scope

- `opencart/extension/cmteh/system/CatalogSchema.php`: projections `cmteh_product`, `cmteh_category`, `cmteh_brand` have native numeric IDs as primary keys (`product_id`, `category_id`, `manufacturer_id`); each stores unique `source_id` and unique `slug`. Mapped native and CMTEH entries represent the same entity.
- `system/CatalogAdmin.php::choiceQuery`, `editRows`, `editorChoices` start from projection JOIN native. CMTEH lists contain mapped records; native lists include mapped and unmapped. No second independent native catalog should be created.
- `admin-guide.md` staging baseline is 35 native/16 mapped products, 46/8 categories, 17/11 manufacturers. This is historical read-only evidence, not current counts or evidence that all unmapped records are disposable.
- `system/NativeCatalog.php::synchronize` maps native adds/edits with `native-{type}-{id}` source IDs when unmapped; existing source IDs and slugs survive. `initialSlug` uses eligible native SEO keyword first, then name/name-ID/fallback. Bulk synchronize is a data/publication change, not a navigation cleanup.
- Existing per-record bridge is `admin/controller/module/cmteh_catalog.php::nativeLink`: maps type to native `.form`, checks native route access, passes real numeric ID and current user_token. Twig shows this link in form and separate list column.
- Stock demo cleanup requires positive match against pinned upstream seed content/identity and dependencies. Never treat unmapped status, name resembling electronics or a numeric-ID range alone as authorization to delete. Parent maintains separate native-business/deletion audit.

## Why navigation currently duplicates

- `admin/controller/event/cmteh_admin.php::menu` prepends a `menu-cmteh` group without changing native groups.
- `::links` includes CMTEH products/categories/brands and explicit `Товары OpenCart`, `Категории OpenCart`, `Бренды OpenCart` native list shortcuts. `module/cmteh.php::index` uses this same list for home cards, so duplication occurs in both sidebar and module home.
- `module/cmteh_catalog.twig` also shows all content tabs and native advanced-form links; retain a contextual advanced-form path while removing redundant list entry points.
- `module/cmteh.php::adminEvents` currently registers only `admin/view/common/column_left/before` and `admin/language/*/after`; header and dashboard are not adapted, only partly translated.
- Every extension page loads native `common/header`, `common/column_left`, `common/footer` unchanged. Dictionary translation does not replace the OpenCart logo, profile external help, dashboard widgets or dashboard destinations.

## Exact extension seams in OpenCart 4.1.0.4

`upload/admin/controller/startup/event.php::index` selects DB triggers beginning `admin/`, removes that prefix, then registers engine trigger with saved sort_order. Store **admin-prefixed** names in setting/event; engine loader emits the corresponding unprefixed names.

| DB trigger | Handler reference arguments | Purpose and boundary |
| --- | --- | --- |
| `admin/view/common/column_left/before` | `string &$route, array &$data, string &$code, string &$output` | Reshape `$data['menus']` before native sidebar rendering; current handler declares first three arguments and works. Stable group IDs include `menu-dashboard`, `menu-catalog`; extension adds `menu-cmteh`. |
| `admin/view/common/header/before` | same four arguments | Add header data/styles, adapt brand/home links, or replace `$route` with an extension-owned Twig. Preserve native script/asset data needed by all native forms. |
| `admin/view/common/dashboard/before` | same four arguments | Add permission-filtered CMTEH operational links/cards or replace template route while retaining native generated data/security block. Works on login landing without granting module access. |
| `admin/view/common/footer/before` | same four arguments | Optional extension-owned footer adaptation; no current need established. |
| `admin/controller/common/dashboard/before` | `string &$route, array &$args` | Controller redirection exists but is unnecessary for presentation; avoid using it to bypass the CMTEH module's route ACL or native dashboard security. |
| `admin/controller/common/dashboard/after` | `string &$route, array &$args, mixed &$output` | Native dashboard index returns void and writes Response; `$output` is not rendered HTML here. View-before is the useful seam. |
| `admin/language/*/after` | `string &$route, string &$prefix, string &$code, array &$output` | Existing original Russian overlay, no language ID/config change. Extend allowed route dictionary list if adapting dashboard labels. |

Source: `upload/system/engine/loader.php::view/controller/language`. View-before saves original trigger before callbacks; may change template `$route` or `$code`. Nonempty `$output` skips template rendering. View-after receives `(route,data,output)` (three arguments, no code). Controller-before return values do not short-circuit execution: handler must change route if redirecting; existing native category guard already accounts for this. Do not add a recursive wildcard view callback that loads its own target repeatedly.

Register new own hooks through existing `cmteh.php::adminEvents/applyChanges/uninstall`; use idempotent delete+add for own event codes as existing admin hooks do, and remove them on uninstall. Do not touch other extensions' events or role permissions. Existing `clearCompiledTemplates` removes only compiled extension Twig cache; test installed upgrade with new view routes and preserved user data.

## Native shell data that must survive adaptation

- `common/header.php::index` supplies `lang`, `direction`, document title/meta/base, native bootstrap/icons/stylesheet/jquery plus document links/styles/scripts. Native pages use jQuery/common.js/bootstrap and filemanager/editor callbacks. Replacing shell must not strand them.
- Header sets logged=false/home=common/login on token mismatch; logged=true only with matching request/session token. Authenticated home is `common/dashboard`; profile, logout and storefront links are generated natively. Branding can change without altering these authentication decisions.
- `common/header.twig` hardcodes OpenCart logo `view/image/logo.png` and three external help URLs; an extension-owned header Twig avoids modifying core and can adapt these while retaining mobile `button-menu`, `#container`, `#alert`, navbar and forms' required assets.
- `common/column_left.php::index` only builds menu when request/session user_token match. `menus` is a 3-level tree. Native catalog children are individually access-filtered; useful tools include filter/filter_group, attribute/attribute_group, option, identifier, download plus native product/category/manufacturer forms. Preserve allowed tools under a coherent advanced-tools group; do not discard the entire native catalog group blindly.
- Sidebar other group IDs include `menu-cms`, `menu-extension`, `menu-design`, `menu-sale`, `menu-customer`, `menu-marketing`, `menu-system`, `menu-report` (use actual generated menu arrays for conditional visibility; all subordinate links already follow native ACL).
- `common/dashboard.php::index` loads enabled dashboard modules only if user has access to each `extension/{extension}/dashboard/{code}`; assembles `$data['rows']`. It also sets developer_status from `common/developer` access and loads `$data['security'] = common/security`.
- `common/dashboard.twig` renders header/sidebar/footer, rows, developer control and `{{ security }}`. If using an extension dashboard template, preserve the security block and native security route/action protections. Hiding generic sales widgets in an adapted operational dashboard is presentation; suppressing security notices or changing privilege requirements is not.

## ACL-preserving one-office navigation

- Keep authenticated landing route `common/dashboard`. `common/login` redirects successful login to it. `startup/permission.php` deliberately exempts `common/dashboard` from route access checks; `startup/login.php` still requires authenticated session and correct user_token there. Therefore adapting its view supports native-only roles without new grants.
- Do not globally redirect dashboard/header home to `extension/cmteh/module/cmteh`, and do not invoke the module index from an ignored dashboard route to bypass its ACL. A native-only user lacks that module/catalog route in staging baseline. Build safe permission-filtered links as view data instead.
- For each products/categories/brands menu slot: select CMTEH destination only if user has extension catalog access; otherwise retain permitted native list destination. Do not show a dead extension link or remove the user's only existing editor. Extension-only catalog roles keep CMTEH editing; advanced native links remain absent unless native access exists.
- The extension catalog controller performs its own session/token/access/modify checks. Do not relax those to make navigation work. Native advanced form URLs retain their own access/modify checks. Menu unification changes discoverability, never grants authority.
- A unified mapped CMTEH list alone cannot represent all native records. To include unknown unmapped records safely, show real native identity and authorized native edit links in the same catalog surface without automatically projecting/publishing them. No decision to bulk map unknown records is established.
- Retain direct native routes for existing integrations and advanced tools even if their duplicate sidebar entries disappear. Preserve CMTEH source IDs, existing slugs and native IDs.

## Affected verification seams

- Existing `opencart/dev/release_checks.py::admin_navigation`: installed hook count/idempotent upgrades; native Russian screens; permission-filtered menu for extension-only catalog and leads roles; snapshots of existing language configuration and group permissions.
- Add targeted shell acceptance for owner/full rights, native-only catalog, CMTEH-only catalog, leads-only and anonymous/invalid token. Verify one ordinary catalog entry for each entity, no denied landing after login, contextual native advanced tools only with native access, retained security block and working mobile sidebar.
- Existing `::native_catalog_editor` covers native CRUD synchronization and whole-batch dependent category delete denial without mutations; keep it affected when changing native list/form shell events.
- Existing `tests/admin-catalog-integration.php` and short `tests/browser/admin-cms.mjs` protect identity/URLs/native-model independence, unmanaged native fields/languages, category assignments, specifications and CMTEH/native edit round trip.
- Do not represent this read-only source discovery as product checks PASS. No runtime, app test, publication, deployment or group permission mutation performed by this scout.
