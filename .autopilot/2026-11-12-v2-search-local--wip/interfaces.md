# Границы, решённые в спецификации

## Правила проекта

- Обязательны корневые AGENTS.md и DESIGN-CONTRACT.md. V1: https://test.cmteh.ru/, source D:/codex/cmteh. V2: D:/codex/CMTEH.RU/V2-search, только локально. Не писать в V1 checkout/runtime. Не менять domain/data/routes/API/storage/idempotency/auth/ACL/migrations.
- Рабочая система OpenCart4.1.0.4, PHP8.3/MySQL8.4 Docker runtime. Корневой Next.js — reference.
- Исходники существующего production extension, packaging, business JS, схемы и fixtures не менять: новую presentation overlay и локальные инструменты держать отдельно.
- Из carried.md сохраняются прежние договорённости полностью. Текущий таск не переоткрывает inventory, mail, ACL, quote contracts или recovery. Никакого whole-upgrade на хосте, старого DBrestore или SMTP/cron.
- User-owned AGENTS.md не редактируется в этом прогоне. Дополнительную память предложить отдельным файлом.
- Только пользовательский браузер через CUA; исполнители не открывают браузер/Playwright MCP/CLI. Лид выполняет визуальную проверку и пользовательские сценарии. Не обращаться к Refero MCP.
- Один task branch от origin/main; named paths commits; PR/merge после проверок разрешены постоянной политикой владельца. Только source publication; хост/deploy исключены. Не синхронизировать V1 checkout в этом прогоне.

## Презентация V2

G14, уточнение владельца во время разработки: одна админка/бизнес-логика, два оформления. Не создавать вторую управляемую админку или схему контента. Сначала локальный визуал и выбор владельца, затем отдельный шаг подключения выбранного оформления к существующей системе. Не подключать real V1 DB/admin сейчас; disposable local native admin — только техническая test infrastructure.

Владеет `opencart/designs/v2-search/`: Twig templates, CSS, изображения, source attribution и инструкции. Вход — существующий view context `page`, `model`, `site`, `navigation`, `meta`, `jsonLd`, `params`. Не изменяет сами данные. Экспорт — runtime layout template, который использует существующие storefront working-page partials и JS.

Локальный launcher копирует overlay из checkout в собственный installed webroot, только после безопасного local runtime start. Default production extension остаётся byte-identical. Обновление/runtime refresh повторно применяет V2 overlay явно, не по глобальному состоянию и не от пользовательских HTTP parameters. Существующие `#header-article-search`, `#article-search`, `#search-options`, search-popover, data-cart-* и list-file/warehouse hooks сохраняются ровно один раз на странице.

## Локальное окружение

Владеет отдельным `opencart/dev/v2-*.py` и, при необходимости, маленькой локальной fixture для демонстрации складов. Вызывает существующий `LocalOpenCart`; не меняет его безопасность. Runtime path по умолчанию вне repo в D:/codex/temp/cmteh-v2-search-20261112, порт проверить свободным перед выбором. ZIP в D:/codex/temp/cmteh-opencart-cache/opencart-4.1.0.4.zip, pinned hash уже проверяет существующий build. Не печатать runtime secrets. Только own synthetic DB, only loopback, internal MySQL.

Экспорт: документированная команда `python opencart/dev/v2-local.py --runtime-dir PATH --upstream ZIP --port PORT start` (окончательный CLI executor может упростить, записав фактическую команду); повторный запуск сохраняет тестовые данные, не дублирует fixtures, не отправляет email. Отдельная проверка (check subcommand или короткий meaningful check script) подтверждает runtime, основные рабочие маршруты и unchanged package bytes. Нельзя называть эту проверку полным release verifier.

## Проверка

- Поведение — существующие PHP/MySQL integration/HTTP tests, узко по search/catalog/list/warehouse и native source. Browser interactions выполняет лид в CUA. Нет новых тестов ради markup/count; safety test local launcher полезен только для отказа выбирать V1/runtime path.
- Швы: реальный HTTP на отдельном loopback origin, установленная Twig rendering, существующие JSON APIs, existing storage/cart module. В acceptance report записать фактические команды и результаты.
- Сравнить checkout source/package default V1 с accepted origin/main; исключить новые `designs/`/dev docs из production installer. Никаких ослаблений strict verifier, никаких fabricated PASS.

## Построено T01

- `python opencart/dev/v2-local.py --runtime-dir PATH --port PORT [--upstream ZIP] start|refresh|stop|check`; runtime basename обязан начинаться с `cmteh-v2-search-`, директория вне checkout, ownership marker связывает checkout/порт. Current runtime D:/codex/temp/cmteh-v2-search-20261112, loopback3162.
- `python opencart/dev/v2-check.py --runtime-dir PATH --port PORT [--http-only]`:5safetytests+24HTTP/API/assets+126exactdefaultpackagefiles; полный affected mode дополнительно3existingPHP/MySQL suites. Это не strict production verifier.
- Repeatstart сохраняет fixtureidentities; `refresh` только presentation overlay. Production extension/package unchanged SHA b78dbe14f9132c7a6a4164a1eb79d665fcd9f3488064b09b3f470e59c5dae442.
- Browser/design acceptance выполнена в пользовательском Yandex/Chrome через CUA, выбранная главная сравнена в одном pairedimage1043x788, итогpassed; временные viewport reset. Иконки — Feather, рисунок image_gen, бренды реальные assets. CodeSHA41773a3.
- One fresh whole-branch review: FIX NOW none, REPORT none;5ownershiptests rerun by reviewer PASS. Глубокие unrelated checks не повторялись.

## Договорились ранее — исходный текст

Полный текст «Договорились» из carried.md включён ниже автоматически, без изменения. Он относится к прежней принятой бизнес-системе, которую V2 переиспользует.


- Только склады в текущем выпуске: «Да нам нужны склады, остальное оставим на будущее». R01–R42 прежнего отчёта остаются будущими работами.
- Покупатель видит предложения одного товара по складам, свои цену/наличие/срок; ID товара стабилен, выбор склада и снимок условий сохраняются в корзине/заявке. Заявка не резервирует автоматически.
- Excel/CSV с предпросмотром, явным подтверждением и атомарным применением; пропущенные строки не обнуляются. Ручные резервы, снятие и полная отгрузка; подтверждённое парное перемещение без логистического транзита.
- Срок задаётся для склада с уточнением для предложения. Примерные города/цены не являются реальными данными: владелец создаёт склады и загружает фактические остатки.
- Actualapp opencart/OpenCart4.1.0.4; rootNext reference. Толькоtest.cmteh.ru, production/StoreLand/DNS/engine исключены. Старые IDs/source_id/slug/mappings и приватные оригиналы сохраняются.
- Прежние RFQ/quote, native ACL и UX сохранены. Сырой список не добавляется в корзину; проверка exactSKU/ведущихнулей/порядка/дублей. info@cmteh.ru, владелец подтвердил Inbox и исходный CSV; почтовую приёмку не переоткрывать.
- Только quotequeuecron; старыйLeadSMTP отключён. Leadmarker3, inventorymarker1, отдельная importpreviewсхема. Constructors/read не мигрируют. Новые12hooksдоinventoryreadiness; нацелевомхосте безwholeupgrade/перезаписи67старыхhooks/автогрантовACL.
- Старый бинарный rollback допускается Opsguard только при полном отсутствии inventorytables. После DDL только совместимый code-only recovery с текущей БД; oldDBrestore/loweringmarkers запрещены. Новый incident требует нового GO.
- Seed-cleanup.json сохраняет точные подписанные rawbytes cb85…; unchangedTEXTacceptedoverlays неприменимы к изменённым warehouseфайлам. Последний архив126files/outera8520763/nestedb78dbe14 получен из cleancloneLF без overlays.
- Quote/campaign/readmodels не равны коммерческим заказам/покупателям/конверсиям; сохранённый page_url декларируемый. Публичных кабинетов/оплаты нет. Карта офиса — только trustedYandexiframeURL; фактический адрес/код владелец пока не предоставил.


