# Разведка текущего кода

Источник: read-only explorer explore_mobile_ux; baseline c673c12. Рабочая реализация находится в opencart/, Next.js — прежний визуальный эталон.

- home-hero.twig использует home.hero_title, hero_text, hero_image_url, hero_mobile_image_url, две CMS CTA и quicklinks. globals.css поздними mobile rules задаёт текст перед изображением и aspect-ratio4/3; крупная типографика+шапка растягивают hero.
- Layout CSS: theme→globals→home-ux→catalog-ux→request-ux→articles→responsive-ux→storefront. home-ux.css и responsive-ux.css следуют за большими повторными правилами globals. Проверять computed layout, не добавлять очередной независимый дизайн.
- header.twig: native details.catalog-menu, явный символ⌄, поиск #header-article-search, .request-link, .menu-toggle/#main-navigation. layout.twig: нижняя .mobile-actions c Search/Catalog/Cart — источник повторов.
- StorefrontPages::homeBlocks(settings) создаёт fallback hero/brands/categories/products/order/articles/sections, затем принимает CMS settings и сортирует. На живом test home_blocks НЕ записан — используется fallback. Можно поменять только fallback порядок; явные настройки CMS должны оставаться сильнее.
- cmteh_catalog.php строит те же7блоков для редактора; его fallback должен соответствовать новому порядку. CatalogAdmin::validateHome проверяет ключи/enabled/sort_order; не менять валидатор или схему.
- home-products.twig выводит6моделей, home-categories.twig использует model.home_categories. Фетч/состав товаров не меняется.
- layout создаёт tel из site.phone и валидированных digits. Новый реальный номер одобрен владельцем; записать его только в test CMS через native форму, не в код/fixtures.
- storefront.mjs: showCartCount/readCart/onCartChange, setupMenu, focusSearch, галерея/copy. Новый back-to-top/dialog — только presentation behaviors. JS release baseline20261110-3 во всей цепочке storefront/request/cart/request-import; XLSX reader наследует query.
- request.mjs::setupLead использует data-lead type. rfq=true только request; остальные формы посылают пустой список и не acknowledgeCart. Есть draft/pending sessionStorage, native validity, fingerprint+request_key, сохранение после потерянного ответа. Все5namedfields company/name/phone/email/message должны существовать, даже если в compact форме company/email hidden.
- LeadInput::parse отклоняет пустые items на page_url /request. Поэтому новая независимая короткая форма на странице корзины требует явного узкого intent; подделывать page_url=/contacts нельзя. Старый empty-RFQ guard должен остаться.
- Публичные формы на /contacts через lead-form.twig, type contact. Quick type обязан отличаться во избежание повторных DOM ids/draft keys.

До изменения на test390×664: header120px; hero top120,height674,bottom794; fixed bar top600,height64; первая карточка y1551.1. Deere Shop — только образец действий, его первая карточка y1640.3 сама по себе не решает длинный путь. Метрики/скриншоты D:/codex/temp/cmteh-mobile-ux-20261110/.

Runtime commands: opencart/dev/runtime.py; official ZIP уже есть D:/codex/temp/cmteh-admin-final-runtime-20261110-r4/opencart-4.1.0.4.zip. Node/Playwright D:/codex/cmteh/node_modules; новых зависимостей не требуется. Полный OpenCart verifier — opencart/bin/verify-local, не корневой Next.js verifier. Существующие locked Docker images использовать повторно, task DB отдельно.
