# V2: ненавязчивое проявление деталей — 2026-11-16

Сценарий: заменить параллакс чертежа экскаватора мягким локальным проявлением по принципу Torch Reveal, только V2. V1 не менять. Текущий WebP и базовая прозрачность сохранены; изображение больше не перемещается.

V2-only CSS/JS: второй декоративный экземпляр того же изображения точно совмещён с первым. Радиальная mask220px с мягким краем локально добавляет opacity0.065 к базовым0.095; появление/затухание300ms, позиция догоняет курсор с ease100ms. Нет idle patrol, flicker, perpetual rAF, новых зависимостей, ассетов, UI-настроек и данных. Legacy filename `parallax.mjs` оставлен для существующего installer/checkset; внутри теперь только reveal. Без поддержки CSS mask остаётся исходный фон.

Эффект включён только для mouse hover/fine pointer, ширины>640px и no-preference reduced motion. Coarse/touch/mobile/reduced-motion сохраняют статичный фон. Уход курсора, фокус на интерактивном элементе, клавиатура, navigation/scroll/resize/blur/visibility/pagehide сбрасывают reveal; rAF работает лишь до схождения координат. Декоративный слой pointer-events:none, скрыт от accessibility родителем aria-hidden.

Фактические checks:

- PASS `node --check .../parallax.mjs`, `git diff --check`.
- PASS V2 affected `v2-check.py --http-only`:5 ownership safety,27 HTTP/API/assets,127 exact default V1 package files unchanged. После уточнения min-width guard — JS syntax и browser closure; финальный HTTP/package check также выполнен перед публикацией. Backend integration/reference Next.js suites не перезапускались.
- Owner browser1440/1024/native888: hover active=true, mask follows actual pointer coordinates; background transform:none; base/clone dimensions совпадают. Native пример mask center160/172 relative to layer; opacity wrapper1, child0.065. Pointer leave→active=false/opacity0; search focus disables reveal.
- 390px: display:none, active=false, transform:none; без горизонтального overflow. Coarse/reduced-motion CSS+JS guards проверены статически, OS preference не менялась. Viewport сброшен.
- Find RE568158→существующая карточка5, ссылка списка→существующая форма, возврат на главную работают. На product active=false и ровно один detail layer. Заявки не отправлялись, корзина не менялась; собственный поисковый запрос очищен. Главная V2 оставлена открытой в новом persistent owner-browser tab11.
- Низкорисковая декоративная правка реализована одним исполнителем; отдельное формальное review не запускалось.

Screenshots вне repo: `D:/codex/CMTEH.RU/.playwright-mcp/v2-excavator-detail-{desktop,baseline,mobile,final}.jpg`. Ref: https://21st.dev/@rmahammad/components/torch-reveal — адаптирован принцип, код demo не копировался.

Начало20:18:44 MSK; первый live refresh до20:22, browser acceptance завершена до20:28. Итоговый request→merge/cleanup и server-only refresh (~1s) записаны в versions.json. Hosting deploy отсутствует.
