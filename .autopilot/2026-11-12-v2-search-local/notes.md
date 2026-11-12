# V2 local design discovery (2026-11-12)

## Scope and repository state
- Checkout: `D:/codex/CMTEH.RU/V2-search`, branch `codex/v2-search-local-20261112`, HEAD `9edb5fac137abd95c792a41af2ae051f978eed89`.
- Existing unrelated/owner worktree state is dirty: `.autopilot` state/readme/dashboard staged/modified/untracked changes plus this WIP folder. Preserve it.
- V2 must remain isolated from V1 (`D:/codex/cmteh`); local runtime only, synthetic DB/storage, no production secrets/data. Contract allows presentation-only changes.

## Relevant render path and dynamic fields
- `extension/cmteh/system/StorefrontPages.php::build` enriches `site.logo` to `site.logo_url` and `home.hero_image_path` to image URLs; home brands are `model.home_brands` filtered from dynamic `model.brands` using home mode/slugs.
- `catalog/view/template/storefront/layout.twig` is the shared document: links theme/globals/home/catalog/request/article/responsive/storefront CSS and modules, includes shared header, selected page (`home` => `home.twig`) and footer.
- `home.twig` loops CMS/configured home blocks (`home-<block.key>.twig`). Hero currently has no search form; shared `header.twig` has search `#header-article-search` inside `#article-search`, form `.search-box.compact` action `/catalog`, GET `q`, role=combobox; `storefront.mjs` binds this selector and `[data-focus-search]`.
- Header and footer logo read dynamic `site.logo_url` (asset fallback is company name). Home brand strip links each dynamic brand name/slug, but does not render brand logos. `/brands` and brand collection render dynamic `brand.image_url` where present; CMS/import field is brand image.
- Safe surface likely new isolated `opencart/designs/v2-search` overlay of Twig/CSS/static assets, activated only by a fresh V2 runtime launcher; do not change deployed extension PHP/business logic or default design. Existing `runtime.py` packages and installs extension, bind-mounts checkout read-only at `/workspace`; it currently has no overlay parameter/copy step.

## Fixtures and affected checks
- Synthetic storefront fixture: `opencart/fixtures/demo.ndjson` (49 rows; 16 products, 11 brands, 8 categories). Apply with installed CLI: `php cli/import-catalog.php --config=/var/www/html/config.php --input=/workspace/fixtures/demo.ndjson --preview`, then `--apply --confirm=<preview SHA> --run-key=<unique>` (docs: `opencart/fixtures/README.md`). Full gate imports fixture through `release_checks.import_fixture` with 36 add/13 update expectation. The fixture includes dynamic site logo and brands; no explicit `home` record was found in the current demo file.
- Browser critical flow: `opencart/tests/browser/storefront.mjs`, scenario “Поиск → карточка → корзина → заявка”; selectors rely on accessible combobox name “Поиск запчастей”, returned option and product `/product/jd-re568158`. Additional script asserts static assets' `?v=20261111-1`, so overlay asset references should not disrupt baseline tests or cache checks.
- Useful targeted local checkset: start isolated runtime, import demo, run `opencart/tests/browser/storefront.mjs` with existing Playwright tooling/base (search → product → request; optionally inspect home responsive visually). Broader documented gate is `python opencart/bin/verify-local ...`; design acceptance also calls for 1440/1024/390 and no horizontal overflow, but its visual diff targets locked Next reference and is much broader than local design smoke.
- Warehouse functionality has existing integration tests: `warehouse-offers-integration.php`, `inventory-integration.php`, `native-inventory-integration.php`; importer behavior in `inventory-import-integration.php`. The full verify gate runs these after fixture import. Warehouse test setup/seed is in those PHP integration tests; latest accepted warehouse work should remain untouched.

## Local runtime availability / limits
- Docker CLI 29.6.2 and Compose 5.3.1 available. Cached images include `cmteh-opencart-web:local`, `php:8.3-apache`, `mysql:8.4`; no runtime was started or altered.
- `opencart/dev/compose.yml` binds web to loopback and keeps DB private; per runtime path Compose project and named volumes isolate DB. `runtime.py start` builds extension ZIP and provisions isolated one-time credentials. Runtime directory must be outside checkout; V1 untouched.
- Official OpenCart ZIP cache was not found under `D:/codex/CMTEH.RU` (recursive bounded search). Lock requires official 4.1.0.4 ZIP SHA-256 `cdef537f5a0a7e1b4ddbe16f589dd2e80b10150a09bfbe93ce76ef2d5c3218c0`; potential archive elsewhere was not searched.
