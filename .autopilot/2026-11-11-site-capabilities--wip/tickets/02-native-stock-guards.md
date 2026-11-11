# 02 — Защита остатков при штатном редактировании

**Требования:** G01, G02
**Зависит от:** 01
**Зона:** `opencart/extension/cmteh/catalog/controller/event/cmteh_inventory.php` · `opencart/extension/cmteh/catalog/model/checkout/inventory_guard.php` · `opencart/extension/cmteh/system/InventoryNativeGuard.php` · `opencart/extension/cmteh/admin/view/javascript/cmteh_inventory_guard.js` · `opencart/extension/cmteh/admin/model/catalog/` · `opencart/extension/cmteh/catalog/model/catalog/` · `opencart/extension/cmteh/admin/controller/event/cmteh_catalog.php` · `opencart/extension/cmteh/admin/controller/module/cmteh.php` · `opencart/extension/cmteh/system/CatalogAdmin.php` · `opencart/extension/cmteh/system/CatalogWrites.php` · `opencart/extension/cmteh/system/NativeCatalog.php` · `opencart/tests/native-inventory-integration.php`
**Волна:** 2
**Ревью:** нет — одна независимая проверка всей реализации по политике владельца
**Модель:** сильная

## Что должно заработать

После managedcutover прежние nativeформы/quantitysetter/copy/delete/order-effects не могут обойти складскойучёт, но descriptions/legacyproducts продолжаютработать. Upgrade/migration подключаетinventory безавтовыдачиправ.

## Из брифа, дословно

> «Да нам нужны склады, остальное оставим на будущее»

## Разделы спецификации

Истории3,6,20–22; interfaces Nativeintegration. Read notes + warehouse-scout. Exactinventorycontract fromticket01. Не трогать cmteh_catalog controller (ticket03) и публичныйCatalog.php/Lead (ticket04); координаторрешает необходимыйsharedchange.

## Критерии приёмки

- [ ] SameactualnativeDB transaction mutex makes stale nativeform save descriptivefields whilecurrentaggregateavailable preserved; not separatePDO substitution. Loaderredirect afterroutes/projection sync validated actualinstallednative path.
- [ ] Scoped managedcopy haszeronoinventory; manageddelete batchpreflight/nohistoryloss, nativequantity/orderbeforemail bypass denied; unrelatednativeproduct operations unchanged.
- [ ] CMTEH metadataimport/delete can'tresetwarehouse source; effectiveprojection compatible. Additive install/schema/eventdefinitions preserveexistingkeys/routes/ACL; no usergroupgrant/globalpermissionbypass.
- [ ] Realinstallednativeintegration againstisolatedtaskdata tests stale/concurrentwrite, copy/delete and ACL negatives, notstandaloneservicemock. Record exactnew events/actions/codes forhostops.
