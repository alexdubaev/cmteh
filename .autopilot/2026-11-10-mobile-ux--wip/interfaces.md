# Границы и правила

## Не пересматривать

Белая индустриальная тема, жёлтый акцент, оригинальный логотип, нейтральная мультибрендовая техника. Данные каталога, цены, контакты и тексты принадлежат CMS. OpenCart4.1.0.4, текущая заявка /request, поиск, маршруты, localStorage smtechno-request, ACL и идемпотентность сохраняются. Целевой стенд test.cmteh.ru; production cmteh.ru/StoreLand/DNS/engine.cmteh.ru исключены.

## Границы, решённые в спецификации

- Shared templates/CSS отвечают за header, hero, compact categories, bottom actions, back-to-top и dialog. Уникальные CMS факты не хардкодить. Header basket остаётся единственным мобильным basket entry; bottom Request — самостоятельный quick.
- StorefrontPages::homeBlocks меняет только fallback order на hero/categories/products/brands/order/articles/sections. Те же fallback order в cmteh_catalog.php. Явные CMS значения остаются старшим источником; CatalogAdmin validation/schema не менять.
- Общая форма lead-form.twig может принимать compact=true; data-lead=quick, name/phone/message видимы, company/email hidden. Уникальные ids quick-*. Все обычные RFQ/contact markup и поведение сохраняются.
- request.mjs::setupLead: rfq=request; quick — новый non-RFQ тип, request_items=[], intent='quick', реальный location.href. В fingerprint quick входит intent; legacy payload/order/keys не меняются. При quick success acknowledgeCart НЕ вызывается. Retry/draft использует тот же существующий механизм с изолированным type quick.
- LeadInput::parse: intent отсутствует→всё как прежде. Единственное новое значение quick; quick требует пустые items и разрешается при реальном /request page_url. Unknown intent/quick+items reject. Legacy пустой /request reject. Lead::save/Outbox/rate/схема/ACL не меняются.
- Dialog UI — storefront JS: showModal()/close() и native focus behavior; Escape/close/backdrop, без route change; fallback anchor /contacts. Состояние sending остаётся у формы. Back-to-top — чистое scroll/focus поведение.
- Новый asset version применить ко всем четырём JS URL, imports и динамическому XLSX reader, а также CSS, чтобы физический Safari не использовал старый layout. Не путать import.meta.url (строку) с URL объектом.
- Телефон устанавливает lead в test CMS native формой, не исполнитель в исходниках/fixtures. Бизнесномер получен от владельца, secrets в промпты/логи не попадали.

## Зона исполнителя

opencart/extension/cmteh/catalog/view/**; StorefrontPages.php (только fallback order); admin/controller/module/cmteh_catalog.php (только fallback order); system/LeadInput.php (узкий intent); opencart/tests/lead-integration.php и tests/browser/storefront.mjs (короткие критические сценарии); при необходимости opencart/README.md/admin-guide.md и DESIGN.md — только достоверная документация текущего UI. Никаких иных backend/DB/schema/auth/dependency/DNS/deploy правок. Не редактировать .autopilot/ и не коммитить файлы lead.

## Работа и проверки

Worktree D:/codex/worktrees/cmteh-mobile-ux-20261110, branch codex/mobile-ux-20261110, base c673c12. Источник Playwright tooling D:/codex/cmteh/node_modules; менять package/lockfile не нужно.

Dev runtime D:/codex/temp/cmteh-mobile-ux-dev-20261110, port3176. Lead подготавливает и сообщает готовность; не создавать ещё один стенд без причины. CLI `python opencart/dev/runtime.py --runtime-dir D:/codex/temp/cmteh-mobile-ux-dev-20261110 --port 3176 compose ...`. Исходник смонтирован read-only как /workspace, установленное расширение — /var/www/html/extension/cmteh. `start --upstream D:/codex/temp/cmteh-admin-final-runtime-20261110-r4/opencart-4.1.0.4.zip --refresh` обновляет extension и безопасный template cache через native upgrade. Локальные значения credential files не выводить.

Затронутый checkset исполнителя: PHP backend integration `/workspace/tests/lead-integration.php` в этом собственном web контейнере и `CMTEH_BROWSER_BASE=http://127.0.0.1:3176 node opencart/tests/browser/storefront.mjs` с CMTEH_BROWSER_TOOLING=D:/codex/cmteh. UI measurements/screenshots в D:/codex/temp/cmteh-mobile-ux-20261110/, не Git. Финальный FULL strict `python opencart/bin/verify-local --upstream <тотжеofficialZIP> --runtime-dir <freshprivateDIR> --reference-dir D:/codex/temp/cmteh-admin-final-runtime-20261110-r4/evidence --port3177 --tooling D:/codex/cmteh` запускает lead по фактическим CLI args; не обходить guard/неподдерживаемые сценарии. Старые reference PNG — baseline сравнения, не требование pixel identity нового дизайна. Ровно один full после стабилизации кода, узкий closure после конкретных фиксов.

До принятия кода нужны честные RED evidence для новых сценариев, real runtime не syntax-only. Не строить большую unit/screenshot matrix; короткие meaningful business/UI E2E и одна batched visual inspection. Не выводить raw admin pages, cookies, session URLs, exception traces с токенами. DOM UI screenshots без адресной строки допустимы.
