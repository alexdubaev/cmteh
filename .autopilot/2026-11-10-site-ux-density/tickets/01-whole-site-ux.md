# 01 — Компактный сайт с видимыми результатами действий

**Требования:** R01, R02, R03, R04, R05, R06, R07, R08, R09, R10, R11, R12i, R13i
**Зависит от:** —
**Зона:** `opencart/extension/cmteh/catalog/view/` · `opencart/tests/browser/storefront.mjs` · `DESIGN.md`
**Волна:** 1
**Ревью:** нет
**Модель:** обычная

## Что должно заработать

На телефоне человек сразу видит основное действие страницы. После проверки списка — настоящие результаты перед глазами. Каталог/категория/бренд/товар/корзина не отодвигаются большими вступлениями или пустыми медиа; весь публичный сайт имеет единый удобный ритм с читаемыми группами. Сценарии поиска, списка, корзины и отдельной расценки сохраняются.

## Из брифа, дословно

> «куча пустого места, зато то что должен видеть где то внизу и половина видна»
> «вставил список, нажал проверить наличие, и я ничего не вижу»
> «Убрать все огромные пустоты, по всему сайту, в каталогах в категориях в карточках товаров»
> «это не значит налепить все друг на друга»
> «используй ... ui-ux-pro-max ... собери мне удобный и продуманный интерфейс всего сайта»

## Разделы спецификации

Все разделы spec.md, notes.md, interfaces.md. Подробныйlivebaseline D:/codex/temp/cmteh-site-ux-density-20261110/live-before-audit.md и screenshots before-*.png, не подменятьихкропомafterвидимости.

## Критерии приёмки

- [ ] При первом открытии /parts-list390×664 textarea и всяCheckbutton видны надbottompanel; нетномерныхэтапов/дублирующегокрупногозаголовка. Filechooser стольжедоступен. Baselinetextarea602/check939 — заэкраном.
- [ ] После настоящегокликаCheck безmanualscroll видныresultheading/первыйартикул/статус/целикомofferbutton; нольнайденных/partial/error осмысленны. Resultsсразу послеchecker, доcontactform. Focus/reducedmotion/pagination не теряются. Baselineheadingy1663afterclick.
- [ ] «На расценку» явнораскрываетexistingcontactform, initialclosed; quoteintentнеотправляетзаявку. Текст/файл сохраняются приswitch/check/edit; quote+cartsubmissionpayload/key/replay unchanged; noautocartadd.
- [ ] /catalog,/catalog?category=filters,/catalog?q=RE568158,/brand/john-deere на390 показываютпервыйтоваридействие, compactaccessiblefilterandsort; большиеintro/brandplaceholder/summary не стоятпередвыдачей. Baseline562/614/924firstcard, button699.
- [ ] /product/jd-re568158 title/SKU/status/priceifexists/add полностьюдоступнынаfirstscreen, отсутствующееphotoнет400pxпустоты; реальныемедиа/всехарактеристики/длинныйcontentсохранены. Baselineadd567..613obscured.
- [ ] /request сначалаcartstate/items, компактныйсписокlink вместоpromotionhero; contentpages/articles/brands/index/contact/404/home sharedrhythm безогромныхtopgaps. НикакогоhardcodeCMSdata/order.
- [ ] Внешне просмотрены390/1024/1440 иtargeted375/landscape/textgrowth/missingimage/longSKU; нетoverflow, invisiblefocus/fixedobscuring/контролейменьше44, unreadablefontshrink. Keepwholehomephoto.
- [ ] Добавлена толькоценнаякороткаяbrowserрегрессия initial/action→visibleactualresult, meaningfulREDbeforefix. Existingbrowser/quote/cartcheck проходят. No testofsource/markup/giantscreenshotmatrix. Report exactcommand+passcount.
- [ ] Coherentgraph20261110-7 (CSS/modules/imports/XLSX) иизменениятольковzone. No backend/DB/contracts/mail/privatefiles/ACL changes; finalrootstrict+review/hostacceptance отдельно, R13i ownerops pending доегоevidence.
