# 05 — Проверка установленной сборки и руководство

**Требования:** G01, G02, G03, G04, G05, G06, G07, G08, G09
**Зависит от:** 02, 03, 04
**Зона:** `opencart/extension/cmteh/admin/controller/module/cmteh.php` · `opencart/dev/release_checks.py` · `opencart/bin/verify-local` · `opencart/tests/inventory-recovery.php` · `opencart/admin-guide.md` · `docs/warehouses.md` · `docs/admin-capabilities-2026-11-11.md`
**Волна:** 3
**Ревью:** нет — одна независимая проверка всей реализации по политике владельца
**Модель:** обычная

## Что должно заработать

Готовыйimmutablepackage проверяется какinstalledcandidate, а владелец получает инструкцию созданияскладов/импорта/резервов и ограниченийвосстановления. Никакого PASS длянепроверенногохостинга/неотправленныхписем.

## Из брифа, дословно

> «Да нам нужны склады, остальное оставим на будущее»
> «Нужна загрузка из Excel/CSV»

## Разделы спецификации

Истории16–22 и интеграция всехактивныхG. Interfaces Проверкиивосстановление. Не реализовыватьбудущиеR01–R42. Не добавлятьwrapperritual/testsинспектирующиеsource/UImarkup.

## Критерии приёмки

- [ ] Новые meaningfulPHPintegration suites wired intoexisting installedcandidate strictgate withactualdependencyhashes/Reflection/zip receipts, no workspacefallback/skip/CLIdowngrade. Existingseedcleanup rawSHA pin unchanged.
- [ ] Fullfinalaffectedchecks andstrictverifier existingprocedure actualartifact correspondsource; meaningful1000import/nativeguard/cartRFQ proof is retained. TargetPHP8.2/MySQL5.7 compatibilitycheck notclaimedfrom8.4alone; root coordinates environment/host proof.
- [ ] Recovery onisolatedcopy preservesafterbackupmovements+reservations+acceptedRFQ and source snapshots; oldbinaryincompatibility detected/failclosed aftermanageduse; nooldDBoverwrite/lowerSchema/globalACL/cron changes. Exactcompatibleartifact reinstallproven; rootowns actualdeployguard/backupoperations.
- [ ] Guide documents code/warehouseSKUtemplate/UTF8CP1251/preview/conflicts/cutoverdiff/priceonrequest/manualterm/manualreserves/noRFQreserve/confirmedtransfer; arbitraryexamplecities/pricesnotpublished. Capabilityreport updates onlycurrentwarehousefact withremainingdirectionsfuture. No extra feature/version/providerpromises.

## Исполнение

Read finalfiles fromprior tickets; don'trerunbroadchecks alreadygreenunless changed/failure/unresolvedrisk. Roothandles packagecandidate rawbytealignment and later GitHub/actualhostdeployment; coordinate precisely. Productcodefixfromtestfailure belongsownerworker via root, no unrelatedrefactor.

## D01 — обязательный integrationwire

Послеготового03 подключить require/static InventoryImport::migrate($pdo,DB_PREFIX) сразу после InventorySchema::migrate в существующем authorizedmodulemigrator. Только здесь peerdependencies всеcommitted; никакого class_exists/skip/noop и constructorDDL. Проверитьactual fresh moduleinstall/upgrade создал previewtable без измененияACL/старыхданных. Ticket02domain/guardcode не переписывать.

## Final execution coordination

Не запускатьсамостоятельноwhole strict whilewriting andthen rootrepeat. Сначалаownaffectedregistration/migrator/recovery checks+report, rootcommit05/reviewfixes, thenrootcleanclone exactimmutablecandidate one strictfullrun withinstalledbyteguards. Existingdoc-onlychangesaftergreen не rerunproduct. Cachedruntime/reference/tooling reuseroot; no rawnewlineswhitelist, no patchedtestexpectedhashes.
