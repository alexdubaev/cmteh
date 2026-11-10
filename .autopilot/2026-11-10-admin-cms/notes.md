# Исследование существующего кода

Независимый scout остался pending_init и не выполнил исследование; ниже факты, прочитанные оркестратором до разработки, без заявления о независимом discovery.

## Модель
- Catalog.php читает product из native product/product_description/product_to_store + cmteh_product. Конструктор(PDO,prefix,languageId,storeId,privateDir).
- CatalogWrites.php trait: save/validate/writeProduct/writeCategory/writeBrand/writeContent, editRows/editRecord. Публикация фильтруется по status и published_at. Metadata в JSON БД — внутренний формат, не пользовательский UI.
- CatalogSchema.php мигрирует cmteh_schema/product/category/brand/attribute/content/route/media/media_link/import; MySQL JSON совместим с 5.7, никаких оконных запросов добавлять не требуется.
- NativeCatalog::synchronize(type,nativeId,deleted) подключён к штатным add/edit/delete событиям через admin/controller/event/cmteh_catalog.php. Существующий native ID остаётся тем же; initialSlug сохраняет адрес после первого сопоставления.
- brands() и categories() читают штатные names/images/store assignment. categories() возвращает parent_slug и отсекает скрытых предков. Product имеет основную category_id плюс native product_to_category, синхронизацию спецификаций и approved private media.
- StorefrontPages::view формирует home/site/navigation и model brands/categories/products/entries; StorefrontSeo и Storefront маршрутизируют SEO/legacy 301/410, sitemap/robots. Не менять search/query/lead/идемпотентность.

## Интерфейс до изменений
- cmteh_catalog.php TYPES product/category/brand/article/page/home/navigation/site; FIELDS, TYPE_FIELDS, readForm/readSections/readRows.
- Необслуживаемые поля выдавались через textarea details JSON. Списки 25/страница без поиска; taxonomy список только id/slug. CKEditor уже подключается, image helper обращается в штатный filemanager с отдельным access.
- POST проверяет login/access/modify/user_token; значения обрабатываются только при нужных правах. sections имеют server revision hash. Не терять эти проверки.
- home.twig показывает все публичные brands, первые8 rootCategories, products6/articles3. Основная композиция остаётся, но порядок и видимость должны стать формой.
- module/cmteh.php регистрирует storefront/native events и применяет migrateEditorialContent, не выдаёт права на editor/leads. Штатная установка/upgrade не добавляет эти права; локальные проверки создают отдельные синтетические роли с нужными маршрутами.
- OpenCart upstream loader language after signature: route,prefix,code,output; view before: route,data,code,output. Русский интерфейс можно наложить через events, config_language_id не менять.

## Среда
Worktree D:/codex/worktrees/cmteh-admin-cms-20261110, branch codex/admin-cms-20261110, origin/main5acbdd9. Upstream/cache D:/codex/temp/cmteh-opencart-cache/opencart-4.1.0.4.zip. node/playwright D:/codex/cmteh/node_modules.
Dev runtime D:/codex/temp/cmteh-admin-dev-20261110 port3170, project cmteh-oc-cf006b2db87c; source /workspace readonly bind. Test files run directly from live source; installed web extension must refresh through installer for HTTP testing.
MySQL5.7 runtime D:/codex/temp/cmteh-admin-mysql57-20261110 port3171, project cmteh-oc-2a58edb869e9, actual image5.7.44 hash in network.json outside repo.
Full strict release gate: opencart/bin/verify-local; PHP suites tests/catalog-integration.php, lead-integration.php and new admin-catalog-integration.php; HTTP tests/http-storefront.php; existing release_checks.py tests roles/native/editorial/recovery. Reference18PNG: D:/codex/temp/cmteh-opencart-evidence.

## Прочитанный стенд REG.RU
Детали в staging-db-baseline.json и staging-browser-baseline.json. Read-only strict SSH работает через существующую конфигурацию; значения секретов не получены в контекст. Mapped16products/8cats/11brands/8pages/2articles; native totals35/46/17, orphan mappings0. Все45 mappedURLs HTTP200 с Basic Auth. Admin en-gb, native catalog доступен, cmteh_catalog — Permission Denied. У cmteh-test из extension/cmteh разрешён только module/cmteh (access+modify); usergroup maintenance и native catalog разрешены. Права на стенде не менялись. PHP CLI8.2.35, MySQL5.7.44-48; webPHP отдельно не сверено. Production и engine сайт не затрагивались.
