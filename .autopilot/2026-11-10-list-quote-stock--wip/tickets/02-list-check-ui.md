# 02 — Список проверяется и отправляется отдельно от корзины

**Требования:** R01, R02, R03, R04, R05, R06, R07, R08, R09, R12i, G02, G03, G05, D01
**Зависит от:** 01
**Зона:** `opencart/extension/cmteh/system/Catalog.php` · `opencart/extension/cmteh/system/StorefrontPages.php` · `opencart/extension/cmteh/system/LeadInput.php` · `opencart/extension/cmteh/system/Lead.php` · `opencart/extension/cmteh/admin/controller/module/cmteh_leads.php` · `opencart/extension/cmteh/admin/view/template/module/cmteh_leads.twig` · `opencart/extension/cmteh/catalog/view/` · `opencart/tests/catalog-integration.php` · `opencart/tests/lead-integration.php` · `opencart/tests/browser/storefront.mjs` · `DESIGN.md`
**Волна:** 2
**Ревью:** нет
**Модель:** сильная

## Что должно заработать
Публичнаястраницасписка `/parts-list`: paste/file→check/quote; parserбольшенедобавляетвкорзину. Exactbatchпоказываетпо1000строкстатусы/предложения, явныйaddтольковыбранноготовара. РасценкаиспользуетготовыйquoteAPI/локальнуюcaptureT01. Полеquoteконтактовотдельноотcart/quick.

## Из брифа, дословно
> «в корзину списком он добавлять ничего не должен. В корзину он добавляет только из предложенных товаров»
> «он может просто проверить их по наличию нашему... Оттуда он уже может поочередно добавить в корзину»

## Разделы спецификации
Истории1–9,12,14–15,17–18; interfaces5–7. BackendконтрактT01прочитатьвinterfacesпослетогокакleadегообновит, неугадывать.

## Критерии приёмки
- MeaningfulRED oldpaste/fileaddизменяетcart, newcheckroute/1kresultотсутствует; затемреальныйbrowser paste/file/check/quoteоставляютcartнеизменным. Толькоexplicitproductbutton меняетего.
- BatchrealPDO1000 позиций, published/store/exactsku_normfilters, неprefix/MPN. Missing/unknown/out_of_stock/ambiguous честны; partialникогданеfake notfound. NoN+1APIloop иboundedSQL/response.
- Ведущиенули/дубликаты/количество/ошибкистрок; PDF/фотоподходятдляquoteсохранённыморигиналом, непроверяютсянасклад/неOCR. Вводпослеошибкисохраняется; no100silentslice, >1000checkявныйотказ.
- QuoteAPItext/fileaccept черезнастоящуюформу, replayпослепотериответа, имя+phoneORemail, originalfilebytes/контактыизолированыотcart. Draft/pendingотдельно; base64непишетсявbrowserstorage.
- Смtechno-request/currentRFQpayload/fingerprint/старыеrowsсохраняются; optionalcanonicalproductIdstringдляновыхcatalogadds. Quickdialog/backtotopF1/F2неповреждены.
- D01: новый optionalproduct_id путь сохраняетпроверенныйsnapshot id/SKU/brand/title вRFQистории; legacyparse/keyorder/fingerprintстрогонеизменны. Повторsamekeyпослепереименования/скрытиятоваравозвращаеттотжеID/прежнийsnapshot. Unknown/hidden/newwrongSKU rejectsбезorphanparent/job; nativeadminпоказываетвыбранныйбренд/товар. ОтдельнаяQuoteочередьнеизменяется.
- /request—корзина; /request#importработаетчерезясныйlegacyпереходкparts-list. CTA/home/навигация ведуткправильномупроцессу, уникальныеCMSURLнепереписываются. NewUIwhite/yellow/nativebrand, mobile390short+desktop1024/1440targetedacceptanceбезtheme-matrix.
- ОдинcoherentgraphCSS/JS/XLSXv20261110-6, загрузка/ошибкиготовностивидимы. Врезультатахпорции50, 1000DOMcardsсразуневставлять. Целоефотопервогоэкрана остаётся.
- Весьзатронутыйbrowserфайл/catalogintegrationзелёный, obsolete list-to-carttestsудалить/заменить, неskip. Толькосвоязона, безcommit/deploy/.autopilot.
