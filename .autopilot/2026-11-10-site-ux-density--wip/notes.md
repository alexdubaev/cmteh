# Обследование существующего сайта

Read-only scout и независимый live-browser audit, main257729f. Продукт после последней сдачи35f724a не менялся. RootNext — отдельный reference, не действующий хостинг. Работаем только opencart/.

## Имена и поверхности

Storefront::handle → StorefrontPages::view()/partsList() → storefront/layout.twig screenmap. Общие header/footer/mobile-actions/back-to-top. Тemplate inventory: home/catalog/brands/collection(brand+category)/product/articles/article/page/request/parts-list, notfound. Реальный поиск GET /catalog?q=, не /search. CMS home_blocks порядок сохраняется.

parts-list.twig: data-parts-list/.parts-list-layout содержит .list-source и .quote-form, data-list-results — после всего grid. parts-list.mjs setup()/render(): HTTPsuccess показывает результаты ниже, но оставляет наверху genericcompletion; scroll делает толькоpaginator. Функциональный тест это не выявлял.

globals.css широкие правила section78px/page-shelltop32/bottom90/breadcrumbbottom34/titlebottom38 и productmedi min-height400; множество последующих overrides. request-ux.css: gridgap28/padding28/results margin44. catalog-ux.css: cards/gallery/detail. responsive-ux.css: mobile. theme.css — канонические tokens. layout.twig и JSимпортgraph version20261110-6.

## Фактическое live390×664

Header120, fixedbottom68, usablebottom596. /parts-list textarea start602, Check939. Aftercheck scroll321/resultheading1663: quoteform высотой787 перед результатами. /catalog card562; filters/search card614 ниже usablebottom. /brand/john-deere firstproduct924 послеhero555 и3summarycards. /product/jd-re568158 title327, price525, add567..613 частично скрыт bottomactions. /request пустаякорзина title610 после ненужной listpromo. /about content415; /articles firstcard345/cardheight305; contactsform939. Horizontaloverflow не найден. Evidence D:/codex/temp/cmteh-site-ux-density-20261110/before-*.png,live-audit-data.json/live-audit-more.json.

## Проверенные guidance

ui-ux-pro-max Pythonstdlib available. Systemquery industrialparts ecommercecompact дал ecomtypography, но зелёно-оранжевую палитру иshowcasepattern — к текущему бренду не подходит. Единственный narrowerretry B2Bpartscatalog дал SaaS/navy/blue — тоже не принятая палитра/структура. Эти output не сохранены какdesignsystem. Применяем проверенные общие принципы density8(8–32spacing), Minimalismfunctional hierarchy, не меняя бело-жёлтый бренд/локальныеfonts и не добавляя GSAP/deps.

Focused UXquery focusnotobscured: WebAccessibility FocusNotObscuredMinimum советует scroll-padding противfixedbars; valid. Searchresultsquery дал толькоNoResults иgenericfocus, не достаточен для решения новойкомпоновки. W3C первоисточники https://www.w3.org/WAI/WCAG22/Understanding/focus-not-obscured-minimum.html и https://www.w3.org/WAI/WCAG22/Understanding/status-messages.html проверены. Требуется реальная видимость результата; statuslive отдельно, focus/контекст после инициированного действия.

## Запуск и проверки

Тесты opencart/tests/browser/storefront.mjs. OpenCartREADME explicitly requires opencart/bin/verify-local (root Nextverify неhostacceptance). Полный существующий strictcheck включаетpackaging/install,PHPlint/realPDO/catalog/lead/quote,HTTP/SEO,browser,nativeACL/media/recovery. Использовать готовые закреплённыеZIP D:/codex/temp/cmteh-admin-final-runtime-20261110-r4/opencart-4.1.0.4.zip иreferences D:/codex/temp/cmteh-admin-final-runtime-20261110-r4/evidence, cachedtooling D:/codex/cmteh. Новый собственныйruntime3179 dev и3180final, изолированные данные; не трогать foreignservices.

Protected: backendAPI/catalogmatch/domain/DB/privatefiles/nativeACL/mail/quotes/RFQ payload+fingerprints, storagekey smtechno-request,legacycart,URLs/query/CMSdata. Допустим parts-list presentation focus/disclosure/ordering без изменения fetching/submission/idempotency. Assets graph поднять согласованно. Scope публичныеviews, не nativeadmin.
