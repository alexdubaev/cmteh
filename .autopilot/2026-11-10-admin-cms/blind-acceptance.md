# Blind acceptance G4

Дата: 10.11.2026. Исходный продукт: `77f604f36fb70f5ad1947a70ecdac58db94d951d`; первая test-only closure: `68898a1a8bc033cd0e2271876edb3137fc8e339e`; **итоговый проверенный кандидат: `cb93cc892c179f2fce674cf6ea84c5f8bd059085`**.

**Актуальный статус: G4 локально закрыта на cb93cc8 после F2 narrow closure.** Проверены фактические6товарных/2статейных карточки на1440/390, опубликованные цели всех ссылок, literal text/quoted-attribute escaping без DOM/handler execution и сохранение rich-section formatting. Прежнее закрытие на68898a1 было отозвано: оно пропустило пустые карточки home и небезопасный template boundary. Эта регрессия теперь закрыта целевой фактической browser-проверкой, описанной в конце. 44пункта:41implemented,3partial,0missing; три внешних/process partial сохранены. Неизменённые DB/ACL/recovery части опираются на прежнее исполнение. Итоговый кандидат здесь не объявлен published/merged/deployed. Общий strict verifier отдельно у lead; REG.RU/production не менялись.

## Независимость и фактическая среда

Прочитан целиком только исходный brief с дополнениями, затем обычные README/admin-guide/source/tests. Другие `.autopilot` файлы не читались. Skills/субагенты не запускались. Код не исправлялся.

Выполнены `git clone --no-hardlinks D:/codex/worktrees/cmteh-admin-cms-20261110 D:/codex/temp/cmteh-admin-blind-source-20261110` и `git checkout --detach 77f604f`. Клон чистый. Docker mount `/workspace` проверен: обе среды исполняют исходник этого клона.

Собственная среда обновления: `D:/codex/temp/cmteh-admin-mysql57-20261110`, loopback3171. Дополнительно создана новая пустая среда `D:/codex/temp/cmteh-admin-blind-install-20261110`, loopback3173, новые website/database volumes. На ней реально прошли штатная установка OpenCart и установка ZIP расширения через installer. Фактические версии обеих сред: PHP8.3.36/MySQL Community5.7.44. Это не приёмка конкретного REG.RU hosting/PHP/ACL.

Upstream взят из разрешённого официального cache, SHA сверяется runtime с lock. На77f604f/68898a1 SHA установленного `cmteh.ocmod.zip` обеих сред был `0116e2330247ae9968f5c385f96356a358e3703ecb1e0ed0959c1335697bc7ed`. После обновления собственного3171 доcb93cc8 SHA стал `b98db3f881f5641ef5c672d0020c466662d71930ec737b21fa86469d3f3cd109`: изменены7Twigpartials. Playwright cache: `D:/codex/cmteh/node_modules`; SHA package-lock совпадает с клоном: `9fe1dac598f92236f4566d31643f2e7c1d2f78acbd262cef057d7830dac77f08`. Отдельный npm ci Next.js не запускался; устанавливался фактический PHP/OpenCart пакет.

Только synthetic fixtures и новые роли. Пароли читались из private runtime в процессы, в отчёт не включены. Существующим группам разрешения не добавлялись. Среды3170/3172, stage, production, DNS и реальные данные не затрагивались.

## Реально исполненные команды и результаты

- Из клона: `python opencart/dev/runtime.py --runtime-dir D:/codex/temp/cmteh-admin-mysql57-20261110 --port 3171 start --upstream D:/codex/temp/cmteh-opencart-cache/opencart-4.1.0.4.zip --refresh` — PASS. Аналогичный start без refresh для новой среды3173 — PASS на пустой БД.
- `LocalOpenCart.smoke()` обеих сред — PASS: extension/PDO/MySQL/private storage, anonymous admin/core/file guards, POST-only migration.
- `import_fixture` с `/workspace/fixtures/demo.ndjson`: preview/apply36added+13updated — PASS на обеих БД.
- `run_php_suite`: `catalog-integration.php`, `lead-integration.php`, `admin-catalog-integration.php` — PASS на3171: actual DB persistence/search/import resume/idempotency/concurrency, rollback/outbox, leading zeros, native fields/other language, dependencies, home/SEO/deletion.
- `admin_roles`, `editorial_content`, `native_catalog_editor`, `verify_media`, `check_recovery` — PASS на3171. Реальные native endpoints/POST, русский интерфейс, ACL, repeat upgrades, public render, protected media.
- Recovery действительно выполнен в отдельных earlier/latest databases и отдельном media scratch. Ранняя копия не содержит позднюю заявку; поздняя копия и current DB содержат обе. Старый dump не накатывался поверх current. Это DB/media rehearsal текущего кандидата; old-application rollback REG.RU не проверялся.
- `lint(runtime)` — PASS42PHPfiles. `python opencart/tests/http-storefront.py --base http://127.0.0.1:3173` на clean fixture baseline — PASS всех7групп:44canonical/indexable sitemap targets, SEO/robots/JSON-LD/search/guards.
- `node opencart/tests/browser/admin-cms.mjs`, full с новой synthetic owner role — **FAIL** на повторно используемом slug после reviewClosure; точный сценарий ниже. ReviewClosure и existing rename отдельно фактически прошли.
- Для незатронутых требований выполнено **отдельное диагностическое** browser-исполнение owner-body из того же source без destructive preceding reviewClosure setup, без записи source. Все owner-body сценарии PASS. Это **не заменяет committed gate**: brand/root/child/product, zero-prefixed SKU/MPN, photo/specs/multiple categories/native edit/search, hide/publish/301, private preview/new page/menu, home order/modes/limit0/explicit nested, shared contacts/social, explicit test deletion410. Существующие home/site значения восстановлены.
- `node opencart/tests/browser/storefront.mjs` на3171 — PASS: search→card→cart→persisted lead и lost response→same key→one lead.
- Отдельный independent browser article run — PASS rich text/date/author/SEO, **реальный штатный image chooser**, draft404+sitemap absence, publication200+sitemap presence, archive404/republish/reload. Тестовая статья удалена в cleanup. Отдельный assert410 этой cleanup-операции не исполнялся; article deletion410 подтверждён owner-body.
- Отдельный independent browser ACL run — PASS: operator/manager menus, forbidden routes401/403 без lead data, manager persisted detail, access-only mutation403, anonymous login без данных, private file404. Предыдущая попытка ожидала только403 при фактическом401; повторная проверка корректно принимает оба отказа.
- Отдельный independent browser home run — PASS hero title/text/image/alt/button/link save→reload→actual storefront. Значения восстановлены.
- `compose exec -T --user www-data web php /workspace/tests/http-fixture.php seed`, затем `python opencart/tests/http-storefront.py --base http://127.0.0.1:3173 --fixture`, затем аналогичный clean — **PASS всех9групп**, включая exact StoreLand301,410, chain/loop guard, confirmed zero price и public/private media. Ранний ошибочный setup объяснён ниже.

Фактическая clean inventory3173:35native products/16mapped;46native categories/8mapped;17native manufacturers/11mapped;8pages,2articles,15navigation,1home,1site. Cameras/Components не названы ассортиментом СМ ТЕХНО.

## Требования исходного brief

44 пункта: **41 implemented/matched,3partial,0missing**. Implemented означает локальную реализацию с указанным доказательством, не host deployment. Три partial — повторная сверка REG.RU, ещё не подтверждённая публикация Git и окончательный PR/deploy handoff lead.

| № | Требование | Статус | Доказательство/граница |
|---|---|---|---|
|01|Обычные поля без JSON/ручной БД|implemented|Owner/article/home browser; visible JSON editor отсутствует|
|02|OpenCart/PHP/MySQL,5.7|implemented|Реальные clean install и execution5.7.44|
|03|Сохранить витрину/каталог/редактор/заявки|implemented|PHP+HTTP+storefront browser; actual6product/2article home cards закрыты отдельно наcb93|
|04|Карта visible elements/source/edit path|implemented|admin-guide table и связанные формы/source|
|05|Локальная сверка native/mapped/catalog/pages|implemented|DB inventory выше + public/browser|
|06|Отдельная сверка закрытого REG.RU|partial|Docs содержат historical read-only baseline; мной заново не подтверждён|
|07|Сохранить ID/связи/права/данные/язык|implemented|Native/other-language tests, upgrade snapshots, recovery|
|08|Русское основное меню по ТЗ|implemented|admin_navigation actual screens и browser|
|09|Прямые разделы без Extensions|implemented|Owner browser routes/menu, admin-guide|
|10|Меню и server ACL ограниченных ролей|implemented|Independent browser + real POST403|
|11|Русские native forms/editor/image manager|implemented|Native/browser save и actual chooser|
|12|Перевод не меняет data language/catalog|implemented|Config/permission snapshots, repeat upgrade/public catalog|
|13|Translation provenance/license/update compatibility|implemented|Original dictionaries GPL3+, pinOC4.1.0.4, events/no core patch|
|14|Штатный CRUD product/category/manufacturer|implemented|Real native create/edit/delete integration и browser|
|15|SKU/MPN zeros,photo/specs,request-price|implemented|Browser reload/public card и DB assertions|
|16|Списки/search/filter/status/edit|implemented|Browser SKU search + native/mapped list tests|
|17|Hierarchy/multiple categories/order/visibility|implemented|Root/child/multiselect browser/native paths/home|
|18|CRUD/hide синхронизируют storefront/index|implemented|Public200/404/410, real search/index|
|19|Явное безопасное deletion/dependency/old URL|implemented|Confirmation, linked refusal, atomic batch refusal410|
|20|Native/imported согласованы без дублей|implemented|Imported fixture + same-record native editing/spec tests|
|21|Home order/visibility/content/images/links|implemented|Owner-body blocks/hero + cb93actual cards1440/390,escaping,formatted sections|
|22|Home auto/selected brands/categories,limit|implemented|Selected order,auto_roots,limit0/browser+DB|
|23|New published records; nested explicit|implemented|Browser new records/home links/root-vs-selected nested|
|24|Existing/new pages,visual text/sections/status/SEO|implemented|Delivery/contacts actual render, rich new page/browser|
|25|Private authenticated draft preview|implemented|Sandboxed iframe; public404/no content|
|26|Header/footer/legal targets/text/order/external URL|implemented|Page selector/public menu, named UI/source, archive|
|27|Shared site contacts/logo/legal/social|implemented|Browser header/footer/social; editorial logo/email/render|
|28|Article CRUD,image,date,status,SEO|implemented|Independent actual browser/image-manager/reload/public|
|29|Draft/private not public/sitemap|implemented|Article draft/archive404+sitemap exclusion; product suite|
|30|Entity URL/title/description/index/canonical|implemented|Named forms,brand persistence,HTTP/meta/robots/article|
|31|Rename не меняет URL молча|implemented|Existing brand/category/browser and native/product tests|
|32|URL confirmation/collision/safe301|implemented|Browser explicit confirmation301; collision/flattening tests|
|33|StoreLand exact301,404/410,canonical/sitemap|implemented|All9HTTP groups; no blanket-home fallback|
|34|Roles/sessions/private files/lead history|implemented|ACL/anonymous/file guards + persisted/recovered leads|
|35|Only approved real data; no invented commercial data|implemented|Synthetic fixtures only, provenance documented|
|36|Backup/restore separate copy/no fresh overwrite|implemented|Real earlier/latest DB copies+media/current late lead|
|37|Actual browser/reload/storefront/storage|implemented|Owner/article/home/ACL/storefront runs, not source-only|
|38|Integration tests against real DB|implemented|3PHP suites+native/roles/recovery5.7|
|39|Actual separate MySQL5.7 acceptance|implemented|Two isolated5.7.44 environments/install/browser/suites|
|40|Load/SMTP/real catalog marked unverified|implemented|External load/SMTP/approved real catalog NOT RUN|
|41|Affected candidate checks must actually pass|implemented|688exact fullbrowser/HTTP; cb93affected actual cards/escaping/rich sections exit0; unchanged DB/ACL/recovery5.7PASS; общий release gate отдельно|
|42|Commit/push/PR/merge/current main workflow|partial|Lead/executor obligation after gate; blind agent did not publish|
|43|Deploy/DNS/live changes only within permission|implemented|No stage/prod/rights/DNS/real-data actions|
|44|Owner route/checks/limits/PR/SHA/stage handoff|partial|Guide+report provide route/checks/SHA/limits; PR/merge/deploy pending lead|

## Обнаруженный и закрытый blocker исходного кандидата77f604f

`opencart/tests/browser/admin-cms.mjs`: `reviewClosure()` создавал и удалял `browser-{type}-{suffix}` для brand/category/product. Следующий full branch создавал те же type/ID/slug с общим suffix. Продукт корректно сохранял410 tombstone и отклонял возврат адреса: **«Адрес уже используется перенаправлением»**. На новой synthetic owner role воспроизведён exit1 после reviewClosure PASS и existing rename PASS. Это был дефект изоляции тестовых данных. Он закрыт test-only commit68898a1; URL-retirement продукта не менялся. Диагностическое исполнение owner-body не использовано как замена committed gate.

## Narrow closure итогового committed кандидата

В том же собственном clone выполнены `git fetch origin`, `git checkout --detach 68898a1a8bc033cd0e2271876edb3137fc8e339e`. Клон чистый. `git diff --name-only 77f604f 68898a1 -- opencart` показывает только `dev/README.md`, `tests/browser/admin-cms.mjs`, `tests/http-fixture.php`. `git diff --exit-code 77f604f 68898a1 -- opencart/extension opencart/packaging` exit0: ранее исполненные product/DB/recovery/browser проверки относятся к неизменившемуся продукту. ZIP SHA после refresh не изменился.

1. Refresh3171 из updated clone по документированному runtime.py — PASS.
2. Новая synthetic owner role; env `CMTEH_ADMIN_CMS_SCENARIO=full`, loopback3171, password file private runtime; **точная команда `node opencart/tests/browser/admin-cms.mjs` без substitutions/omissions — exit0**. PASS review H1/excerpt/native model/social; existing rename; brand/root/child/product/native/photo/specs/search; hide/republish/explicit301; authenticated private preview/new page/menu; home order/auto-selected/root-nested/limit0/shared contacts; explicit deletion/410/cleanup. Отдельный диагностический script больше не нужен для утверждения этих результатов.
3. На3173 committed fixture seed/clean как `--user www-data`, между ними немодифицированный `python opencart/tests/http-storefront.py --base http://127.0.0.1:3173 --fixture` — **exit0, все9групп PASS**, включая private media и legacy guards. Новые сети/runtime при closure не создавались.
4. Broad product suites не повторялись без изменения продукта; narrow affected checks соответствуют фактическому68898a1. Полный release verifier не подменён этим отчётом.

## Исправленные выводы и внешние границы

- Первый HTTP run после mutating native/admin checks упал на exact sitemap set из-за оставшихся собственных test-brand/test-parent. Немодифицированный suite полностью прошёл на новом clean3173. Это порядок моей диагностики, не product sitemap failure.
- Первый browser запуск исходным admin не имел access к cmteh_catalog: install/upgrade намеренно не расширяет существующие группы. Затем созданы новые synthetic роли по guide. Host permissions не менялись.
- Первый opt-in fixture seed от root создал media directory `root:root0750`. Проверено отдельно: www-data не мог читать каталог; это причина HTTP404. Исправлен owner **только собственной3173media directory**, seed/clean повторены как www-data, весь немодифицированный opt-in HTTP suite PASS. Отдельно PNG bytes старого fixture не декодируются GD (false); это качество fixture, **не причина404**: stream endpoint не требует GD. Ранний вывод о причинности PNG отозван.
- Общий strict verify-local с18reference PNG в blind run не запускался; его PASS не заявляется. Реальная внешняя SMTP, нагрузка, owner-approved карточки/images/prices/stocks и hosting web PHP/ACL NOT RUN.
- Новая версия на REG.RU не установлена; browser acceptance нового редактора там NOT RUN. Historical docs baseline не выдан за fresh acceptance. Production/DNS/реальные заявки не менялись.
- Начало clone13:57:24UTC; основные локальные checks получены примерно к14:07UTC (~10мин). Report/closure wait — отдельный хвост, не server-only time. Среды оставлены только со synthetic data для воспроизведения; чужие volumes/branches/worktrees не удалялись.
- Итоговая narrow closure и отчёт закончены14:13:08UTC: полный blind request→verified-result15мин44сек, включая clone/setup/checks/report/ожидание и повтор затронутых checks; около6мин после основных product checks ушло на отчёт и test-only closure. Это elapsed acceptance time, не время сервера и не длительность всей исходной задачи реализации.

## F2 — повторное открытие и окончательная homepage closure cb93cc8

На финальных скриншотах lead выявил реальную регрессию68898a1: grids товаров/статей были пустыми, поскольку included partials вызывали ui macros без собственного import. Семь новых partials также не наследовали autoescape родителя при отключённом global autoescape в закреплённом OpenCart adapter. Прежние проверки были недостаточны: они проверяли наличие home blocks, но не фактических card nodes. Прежний вывод «локальных несовпадений нет» на68898a1 отозван; это не объясняется test-data/setup ошибкой.

Выполнены `git fetch origin`, clean `git checkout --detach cb93cc892c179f2fce674cf6ea84c5f8bd059085`, refresh существующего собственного3171 из clone тем же runtime.py. Новые runtime/network не создавались. Diff относительно68898a1 ровно8файлов:7`home-*.twig` и `tests/browser/admin-cms.mjs`; две macro-consuming partials импортируют ui локально, все7оборачивают содержимое explicit html autoescape. Domain/DB/ACL/recovery/import/packaging source не менялся; broad checks не повторялись для формальности.

**Реальный независимый Chromium narrow run — exit0:**

1. При1440x1000 и390x844 непосредственно на `/` найдено ровно6видимых `.home-product-grid .product-card` и2видимых `.articles-home-section .article-card`. Все ссылки ведут на соответствующие `/product/` и `/articles/`, каждая фактически отвечает200 опубликованной страницы.
2. Через обычную home form сохранены harmless stored text probes в hero и заголовках брендов/категорий/товаров/статей; quoted alt probe содержит потенциальные attribute delimiters и marker assignment. После save/reload и actual public navigation текст/alt точно равны сохранённым строкам; не возникло probe elements, новых attributes/onload или execution. Проверено фактическое состояние DOM и marker=false, не только escaped source strings.
3. Через визуальный редактор добавлены две собственные sections: `custom` и `home_order`. Rich text набран в iframe и выделен кнопкой Bold. После save/reload обе sections на реальной витрине содержат **настоящий `<strong>` DOM element** с ожидаемым текстом; теги не показаны посетителю строкой. Это проверяет, что explicit escaping не разрушил уже безопасный rich HTML rendering.
4. Cleanup восстановил исходные6home fields и удалил только собственные две sections; успешный save подтверждён. Не затронуты существующие sections/каталог/права/заявки. Clone status чистый, HEADcb93cc8.

Итоговая F2 closure завершена14:26:01UTC. Blind elapsed от clone до финального verified result **28мин37сек**: первые15мин44сек до первой closure/report, затем12мин53сек ожидания F2 и узкой перепроверки/обновления отчёта. Это включает setup/checks/ожидание/report, не server-only time. Полный исходный request-to-release time должен отдельно сообщить lead. Full generic gate наcb93 здесь NOT RUN и не подменён целевыми проверками; fresh gate lead отдельно pending.
