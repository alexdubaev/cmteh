# 01 — Удобная мобильная витрина и короткий запрос

**Требования:** R01, R02, R03, R04, R05, R06, R07, R08, R09, R10, R11, R12i, R13i, R14i, G01, G02
**Зависит от:** —
**Зона:** `opencart/extension/cmteh/catalog/view/` · `opencart/extension/cmteh/system/StorefrontPages.php` · `opencart/extension/cmteh/admin/controller/module/cmteh_catalog.php` · `opencart/extension/cmteh/system/LeadInput.php` · `opencart/tests/lead-integration.php` · `opencart/tests/browser/storefront.mjs` · `opencart/README.md` · `opencart/admin-guide.md` · `DESIGN.md`
**Волна:** 1
**Ревью:** нет
**Модель:** обычная

## Что должно заработать

Посетитель телефона видит целое изображение на первом экране, быстро переходит от компактных категорий к товарам, не выбирает среди повторных CTA. Внизу две контактные кнопки; «Запрос» открывает короткую самостоятельную форму, «Позвонить» использует CMS номер. Корзина остаётся отдельной, работает «Наверх», desktop/tablet и существующие сценарии сохраняются.

## Из брифа, дословно

> «по загрузке странице картинка была полностью  экране телефона»
> «в хеадере убрать типа стрелочку вниз на кнопке каталог»
> «очень долго листать до товаров»
> «добавить кнопку наверх чтоб быстро к меню вернуться можно было»
> «как много у нас дублирующих моментов на одной странице»
> «Внизу можно сделать как на моем втором сайте, позвонить и запрос»
> «Категории компактно, затем товары — рекомендую»
> ««Запрос» должен открывать отдельную короткую форму»

## Разделы спецификации

spec.md целиком: «Решение», «Истории и приёмка», «Реализация», «Вне рамок»; interfaces.md — обязательные границы; notes.md — точный код; reference.md — пользовательские изображения. Craft floor навыка Impeccable перед первым UI edit прочитать по пути из задания. Сохранять текущую визуальную систему.

## Критерии приёмки

- [ ] На390×664/360×640 вся hero картинка видима над bottom bar, без обрезки и скрытого текста; первая карточка390×664 начинается не ниже930px. Компактные категории расположены перед товарами, brands ниже. Явные CMS block order/visibility продолжают работать.
- [ ] Header без стрелки Catalog, одна корзина со счётчиком; в первом мобильном экране убраны повторные крупные Catalog CTA/старые3нижних пункта. Уникальные CMS CTA, поиск, menu и desktop navigation сохранены.
- [ ] Нижняя панель2равных действия по reference: Call и Quick Request. Открытие/закрытие/Escape/backdrop/focus/reduced-motion/короткий viewport/keyboard не перекрывают важные элементы; native dialog и fallback /contacts.
- [ ] Три visible поля quick, validation/loading/failure/success/draft/retry работают. Quick с /request сохраняется в реальной тестовой БД с правдивым page_url, пустым items и intent=quick; корзина не очищается. Legacy empty RFQ/invalid intent/quick with items отклоняются. Existing payload fingerprints/keys/shape не меняются.
- [ ] Подписанная «Наверх» появляется после прокрутки, возвращает к шапке без открытия клавиатуры, не закрывает bottom bar/submit, accessible target≥44px.
- [ ] Обычные поиск/товар/cart±/remove/reload/manual/file imports/contacts/RFQ зелёные. Поля/ссылки переживают отказ module и старый asset cache: вся JS+CSS цепочка версионирована согласованно.
- [ ] 1440/1024/390/short batched visual pass, без overflow, обычные длинные CMS строки не обрезаны; цвета/шрифты/логотип сохранены. Не более одной общей visual closure после batch fixes.
- [ ] Meaningful backend integration + короткие E2E добавлены с RED proof; affected checkset PASS. Полный generic verifier/G4/review/host deployment выполняет lead после стабильного исходника; не подменять их syntax-only.

## Не делать

Не менять auth/ACL/session/DB schema/catalog fetch/dependencies/Directus/Next.js; не публиковать и не коммитить самостоятельно. Не менять пользовательский AGENTS.md, .autopilot-файлы или чужие worktree. Не записывать реальный телефон в fixtures — lead обновит CMS native формой. Не использовать английские технические сведения в UI. Не выводить токены/пароли/raw admin HTML.
