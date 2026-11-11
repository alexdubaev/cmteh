# 03 — Складская админка и загрузка Excel/CSV

**Требования:** G01, G02, G06, G07, G08, G09, A01, A02
**Зависит от:** 01
**Зона:** `opencart/tests/browser/inventory-import-happy.mjs` · `opencart/tests/browser/inventory-import-fixture.php` · `opencart/extension/cmteh/catalog/view/assets/inventory-admin.mjs` · `opencart/extension/cmteh/admin/view/template/module/cmteh_catalog.twig` · `opencart/tests/browser/inventory-admin.mjs` · `opencart/extension/cmteh/admin/controller/module/cmteh_catalog.php` · `opencart/extension/cmteh/admin/view/template/inventory/` · `opencart/extension/cmteh/admin/view/assets/inventory/` · `opencart/extension/cmteh/admin/controller/event/cmteh_admin.php` · `opencart/extension/cmteh/system/InventoryImport.php` · `opencart/tests/inventory-import-integration.php`
**Волна:** 2
**Ревью:** нет — одна независимая проверка всей реализации по политике владельца
**Модель:** обычная

## Что должно заработать

Оператор управляет складами, остатками, отдельнымиценами/сроками и резервами; загружаетXLSX/CSV, видитserverpreview и явно применяет весьпроверенныйнабор. История и понятныеошибки, без созданияпродуктов/выдуманныхскладов.

## Из брифа, дословно

> «Нужна загрузка из Excel/CSV»
> «Вручную для склада и при необходимости для товара (рекомендую)»
> «Да, ручные резервы (рекомендую)»

## Разделы спецификации

Истории1–5,8–9,16–20,22; interfaces Import/Admin. Existingbase route extension/cmteh/module/cmteh_catalog withmethodsuffix; reuseaccess/modify EXACTkeys, no newstandaloneprotectedroute/autogrants. Serveractor nativegetId/getUserName. Read notes andwarehouse-scout; corecontract01.

## Критерии приёмки

- [ ] Usable warehouseCRUD/offerprice/default/override term, balances/reserves/history paging/filter, explicitcutoverdifference+reason; inputs survive409/networkfailure; no giantblankUI; nativecatalogrights permit UI, read-onlyrole cannotPOST.
- [ ] XLSX/CSVtemplate strictarticle+warehousecode+absolutequantity+price+optionalterm; textleadingzeros preserved, UTF8/CP1251, invalidquantity/price/formula/unknown/ambiguous SKU/duplicatepair errors shown; RFQ parserdefault/clamp notused.
- [ ] Preview normalizedrows+versions serverbound toactor withdigest/expiry, confirmschangedrows/cutover; applysamePDOallornothing+idempotency, missingrowsunchanged, countbelowreserve/staleversion rejectswhole; no auto product/warehousecreation. Actual1000rowimporttest and conflict/failure/race proveboundedwork.
- [ ] Manualreceipt/issue/count/reserve/release/fullship showimmutablehistory, completedtransfer confirmsactualarrivalandpairedentries; no transitdispatch or partialshipmentbusinessworkflow.
- [ ] New private views do notalterpublicstyles/auth/Quote/privatefiles/mail; meaningful realDB/import/nativeACL shortbrowserjourney and keyboard/mobile form review recorded. UseexistingvendorXLSXreader; newdependencies notauthorized.
