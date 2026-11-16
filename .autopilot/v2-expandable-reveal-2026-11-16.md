# V2: Expandable Card reveal — 2026-11-16

Сценарий владельца: плавное раскрытие товара и «Проценить список или файл» по принципу выбранного Expandable Card. V1 не менять. Изменения ограничены V2 navigation/CSS/layout, без новых зависимостей/схем/API и без копирования исходников 21st.

Вместо fade всего main — frame height/clip500ms, отдельный search FLIP500ms, content opacity/translate320ms после70ms; содержимое не масштабируется. С главной начало64px, между внутренними страницами — текущая высота. В конце/cancel — естественная высота и flex, без постоянной обрезки. Home search удерживает целевую геометрию при сокращении внешней области. История верхней позиции анимируется; глубокая прокрутка восстанавливается сразу. Клавиатура/reduced-motion без анимации; resize/wheel/touchstart/новый переход завершают движение.

Фактические checks:

- PASS JS syntax и diff; `v2-check.py --http-only`: 5 ownership safety, 27 реальных HTTP/API/assets, 127 exact default V1 package files неизменны. Полные backend integration и справочные Next.js suites не перезапускались для отдельного presentation overlay.
- Owner browser: измерены активные height/clip/opacity, а не только конечный screenshot. Список: старт64px, затем553px, в конце629px и clip:none/flex:1; desktop samples337→523→631→701→719→737→743→745→746px, active=false после522ms. Во время reveal transform содержимого — только translateY, без scale.
- Home→list, Find→card5, list→product→Back, возврат на home, быстрый переход во время reveal и прямой reload работают. Tab во время анимации немедленно снимает временную геометрию; Enter открывает product без анимации. Mobile wheel во время64px начала — active=false/clip:none.
- Список RE568158;2 и собственный CSV: проверка находит товар; product/Back сохраняет текст, FileList и результаты, ровно один root. Собственный текст очищен через UI; затем reload освободил выбранный тестовый FileList. Заявок и изменений корзины нет.
- Визуально проверены1440/1024/390px и native viewport. Горизонтального переполнения нет, после завершения clip:none и класс снят. Viewport сброшен, на native home active=false. OS reduced-motion preference не менялась; проверен guard в коде, не заявляется native эмуляция.
- Один свежий read-only review: замечаний нет. Повторное ревью не запускалось.

Артефакты вне repo: `D:/codex/CMTEH.RU/.playwright-mcp/v2-expandable-frame-measures.json`, desktop/mobile JPEG, `v2-expandable-reveal-demo.gif`. GIF собран из реальных кадров владельческого браузера с паузами между открытием/возвратом; это демонстрация движения, не performance benchmark.

Начало19:59:46 MSK. Реализация/refresh до20:03, affected HTTP PASS20:04:33; затем review и owner-browser acceptance/запись. Итоговое время с PR/merge/cleanup записано в versions.json. Server-only refresh около1s; hosting deploy отсутствует.
