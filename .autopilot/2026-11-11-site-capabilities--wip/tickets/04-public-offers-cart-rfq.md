# 04 — Предложения складов на сайте и в заявке

**Требования:** G01, G03, G04, G05, G06, G08, G09
**Зависит от:** 01
**Зона:** `opencart/extension/cmteh/system/Catalog.php` · `opencart/extension/cmteh/system/Storefront.php` · `opencart/extension/cmteh/system/StorefrontPages.php` · `opencart/extension/cmteh/system/Lead.php` · `opencart/extension/cmteh/system/LeadInput.php` · `opencart/extension/cmteh/catalog/view/` · `opencart/extension/cmteh/admin/view/template/module/cmteh_leads.twig` · `opencart/tests/warehouse-offers-integration.php` · `opencart/tests/browser/warehouse-offers.mjs`
**Волна:** 2
**Ревью:** нет — одна независимая проверка всей реализации по политике владельца
**Модель:** сильная

## Что должно заработать

Одинтовар публично имеет несколько предложенийскладов с отдельнымиценой/наличием/сроком. Явновыбранныепредложения сохраняются отдельнымистроками корзины и serverimmutableRFQsnapshot, безрезерва/онлайнпокупки.

## Из брифа, дословно

> «клиентам тоже показывать склады»
> «от этого будет зависеть срок поставки»
> «одна и та же запчасть ... в наличии в Питере, цена 1000 рублей, наличие в Благовещенске цена 850»

## Разделы спецификации

Истории7–15,19,21; interfaces Storefront/cart/Lead. Read notes + warehouse-offers-scout. Inventorymethodcontractfrom01. Не редактировать nativeguards/CatalogWrites/CatalogAdmin или admincontroller03; свяжисьеслиsharedchange нужен.

## Критерии приёмки

- [ ] ProductID/source/URLs/exactSKU/canonicals intact; offersbatchlookup allowlistedwarehouse/price/term/status; effectiveavailability/filter/price-sort/count agree, no productcountinflation or fakeglobalprice; publiclabelsescaped, hidden/internaldata notleaked.
- [ ] Explicitwarehousechoice onproductpage andlist-check; productcardsees'Выбратьсклад'; sameproduct A+B twoindependentlines, oldunassigneddraftsnotdropped; noautomaticcheapestselection/noautolistadd.
- [ ] Auxversioned selectedstore isolates old smtechno-request normalizer; new POST /api/lead/warehouse oldserver404, oldpendinglegacy payloadhash/order unchanged; storagefailures preserveselectedrows/quantity andlostack retryacksubtract retainsnewadditions.
- [ ] Firstacceptance resolvesactual product↔offer revision, serverdecimal/warehouse/term snapshots atomicallywithLead/items/outbox; stale409 noorphanlead/job, committedreplay returnssameoldresult afterimport/hide; noautoreserve. Genericnewmanagedsubmissionwithoutselection cannot silentlydiscardwarehouse, acceptedlegacyreplay stillworks. RawQuote remainsidentical.
- [ ] Adminleadshow immutablewarehouse/price/terms afterrename/archive; realDBpriceinjection/stale/race/replay/noreserve andone realbrowserA+B→cart→lostacksubmit criticaljourney; existinglegacyLead/Quote compatibility checks. SharedJSgraphcacheversion bumped consistently; don'tleaveunversionedimports.
