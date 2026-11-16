# V2: меню каталога с вкладками — 2026-11-16

Сценарий: «Каталог» → одна компактная панель → «Категории / Бренды» с плавным переключением. Сохранить весь текущий набор ссылок и V1. Реализация только V2 header/CSS/new module + явное копирование asset существующим local installer/checkset.

Высота/pill240ms, content180ms после25ms; естественная высота восстанавливается после finish/cancel. Native details/common storefront сохраняют Escape/outside-pointerdown/hamburger, URL и бизнес-логика не меняются. Горизонтальные ARIA tabs: Left/Right/Home/End, roving tabindex, Tab к ссылкам активной панели. Keyboard/reduced-motion без движения; no-JS fallback показывает обе группы, вкладки скрыты.

Фактические checks:

- PASS JS syntax/diff и affected `v2-check.py --http-only`:5 ownership safety,28 HTTP/API/assets (добавлен один module),127 exact default V1 package files unchanged. Полные backend integration/reference Next.js suites не перезапускались.
- Owner browser: 9 category links/12 brand links сохранены; Гидравлика→category и Caterpillar→brand открывают прежние URL. Новый module работает и на category page, где seamless navigation module не загружается.
- Mouse tab switch: selected/tabindex/hidden корректны; измерен активный height transition, после завершения is-switching=false. Быстрые переключения/resize не оставляют ограниченной высоты. Left/Home/End/Tab/Escape проверены; Escape возвращает focus SUMMARY. Обычный native CUA click вне панели её закрывает; console errors отсутствуют.
- 1440/1024/390/native888 проверены визуально, горизонтального overflow нет. На390 обнаружено унаследованное `position:fixed` из default globals: panel top852px. Исправлено явным V2 `position:absolute`, узкая closure дала x16..374/top65, обе группы полностью видны, touch areas44px. Существующее скрытие header-links на внутренних mobile-страницах сохранено, как и доступ через основную навигацию.
- Один свежий read-only review без замечаний; мобильная позиция исправлена по фактической browser acceptance и проверена узко, повторное формальное review не запускалось. Reduced-motion guard проверен статически, OS preference не менялась; no-JS fallback проверен структурно, browser JS не отключался.
- Viewport reset; native home с открытой вкладкой категорий оставлен владельцу. Данные/корзина/заявки не менялись.

Artifacts вне repo: `D:/codex/CMTEH.RU/.playwright-mcp/v2-catalog-tabs-{desktop,mobile,final}.jpg`, `v2-catalog-tabs-demo.gif` из реальных browser frames с паузами. Не performance benchmark.

Начало20:48:28 MSK, initial affected HTTP PASS до20:54, затем owner-browser/review и мобильный CSS fix/closure. Итоговое время с PR/merge/cleanup записано в versions.json; server-only refresh около1s, hosting deploy отсутствует.
