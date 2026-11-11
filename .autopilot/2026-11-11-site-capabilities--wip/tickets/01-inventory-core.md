# 01 — Складские остатки и ручные резервы

**Требования:** G01, G02, G06, G08, G09
**Зависит от:** —
**Зона:** `opencart/extension/cmteh/system/InventorySchema.php` · `opencart/extension/cmteh/system/Inventory.php` · `opencart/extension/cmteh/system/InventoryInput.php` · `opencart/tests/inventory-integration.php`
**Волна:** 1
**Ревью:** нет — одна независимая проверка всей реализации по политике владельца
**Модель:** сильная

## Что должно заработать

Независимый Inventorydomain с additive migration, warehouses/offers/balances/ledger/commands, явным cutover и ручными резервами. Готовая foundation для admin/import/storefront; без её nativeguards нельзя публиковать активацию на host.

## Из брифа, дословно

> «Да нам нужны склады, остальное оставим на будущее»
> «цена 1000 рублей ... цена 850»
> «Вручную для склада и при необходимости для товара (рекомендую)»
> «Да, ручные резервы (рекомендую)»

## Разделы спецификации

Истории1–4,8–9,18–19,22; interfaces Inventorydomain. Read notes.md + warehouse-scout.md. No newdependencies. ParentA operations must not delay core: simple completedtransfer and fullreserveship can be implemented as bounded command variants afterrequirements.

## Критерии приёмки

- [ ] Empty additive migration/readiness leaves all legacyqty/IDs/availability and ACL unchanged; explicitwarehouse and cutover/offerprice/default/override terms work with audited discrepancy, no guessedrealdata.
- [ ] SamePDO commands replay beforefreshness, locks stableorder, balances nonnegative/nooverspend, exact decimalstrings/null and checkedintegerbounds; command/ledger/balances commit/rollback together withinjectedfailure and concurrent realMySQL reserve race.
- [ ] Manualreserve create/release/fullship lifecycle, RFQ notcalled; ledger readonly/history retained, managedproduct/warehouseconstraints, publicallowlist/projection revision and resolveSelectedOffers available toothermodules.
- [ ] Core publicproduct sourceID mapping correct; batched publicOffers/consistentlockedresolve typedstaleerror support activeproduct/publicwarehouse; no fakeprices, no on_hand/reserved leak.
- [ ] Integration checks run on real taskDB with uniqueownedprefix; beforeproductcode demonstrate relevant initialfailure, notsource-string assertions. Record exactactualtablecolumns/methodkeys and commandpayloads in interfaces for laterworkers. Existingruntime dependencies remain unchanged.

## Заметки исполнения

Ownerpolicy allows one final independent review, no separatefoundationpointreview. Don'treadsecrets orhost. No coreglobalrefactors. Root handles runtime setup and Git orchestration; commit onlyownedticketfiles whenready and coordinatorauthorizes commit, not wholeworktree. If exactboundary can'twork safely, returnneedsrepair withspecific evidence ratherthaninventing contract.
