# V2 — поисковая версия

Локальный презентационный вариант существующего OpenCart-приложения СМ ТЕХНО. V1 — текущий сайт https://test.cmteh.ru/, V2 — это оформление с поиском в центре. В этом прогоне V1 не обновляется.

**Админка и бизнес-система остаются одними.** V2 использует те же view models, каталог, складские предложения, корзину и обработку списка/файла. Здесь нет новых admin controllers, редакторов, схем или дублирования бизнес-логики. Сначала владелец смотрит V2 и выбирает оформление; подключение выбранного варианта к существующей админке/реальным данным — отдельный согласованный шаг. Native OpenCart в disposable local runtime является тестовой инфраструктурой существующего приложения, а не второй админкой, которую нужно вести.

## Запуск на этом компьютере

Общая база синхронизирована с принятым `origin/main` `8a5ada0` (PR26). V2 читает те же проверенные сервером поля analytics/verification, что default layout: два verification meta и условный локальный загрузчик `analytics.js`. Собственных настроек или валидации нет. В этом preview счётчики выключены; синтетическая HTTP-проверка временно задаёт тестовые значения через существующий CMS model и точно восстанавливает запись, не исполняя скрипты и не обращаясь к провайдерам. Подключения к реальной админке V1 нет.

Из `D:/codex/CMTEH.RU/V2-search`:

```text
python opencart/dev/v2-local.py --runtime-dir D:/codex/temp/cmteh-v2-search-20261112 --port 3162 --upstream D:/codex/temp/cmteh-opencart-cache/opencart-4.1.0.4.zip start
```

После успешного старта: http://127.0.0.1:3162/ . MySQL не публикуется на хост; runtime имеет собственные Compose project, volumes, private storage и одноразовые локальные credentials. Не копируйте сюда боевые секреты, клиентские заявки или медиа V1. Данные, цены и контакты в этом preview синтетические из `fixtures/demo.ndjson`; два предложения тестовых складов добавлены существующей loopback fixture. SMTP/cron и внешние уведомления не запускаются.

Повторный `start` обновляет установленный extension и presentation overlay, но не очищает и не переимпортирует уже созданный каталог. Изменённый demo fixture требует нового runtime. Путь и порт защищены ownership marker, launcher отвергает V1 checkout, собственный checkout, symlink/junction и чужой непустой каталог.

Если Docker сообщает об исчерпании стандартных address pools, не удаляйте чужие сети. После проверки существующих Docker-сетей и маршрутов задайте штатный `CMTEH_DOCKER_SUBNET` со свободной приватной /28 при первом запуске. Существующий runtime сохраняет её в `network.json`. Для данного экземпляра использована `10.231.93.0/28`; для другой чистой копии нужна другая свободная подсеть. Повторный запуск текущего экземпляра не требует переменной.

Для применения только новых Twig/CSS/изображений без миграций и импорта:

```text
python opencart/dev/v2-local.py --runtime-dir D:/codex/temp/cmteh-v2-search-20261112 --port 3162 refresh
```

Остановка только своего экземпляра без удаления данных:

```text
python opencart/dev/v2-local.py --runtime-dir D:/codex/temp/cmteh-v2-search-20261112 --port 3162 stop
```

## Проверки

```text
python opencart/dev/v2-check.py --runtime-dir D:/codex/temp/cmteh-v2-search-20261112 --port 3162
```

Это затронутый локальный checkset: ownership safety, настоящее HTTP-rendering/API, существующие catalog/warehouse/quote PHP/MySQL suites, неизменность исходников и точных файлов default V1 extension archive. Он не заменяет строгий production verifier, host acceptance или recovery. После CSS/Twig fix для узкой повторной проверки есть `--http-only`; это явно не повтор всего набора. Визуальную проверку 1440/1024/390px и пользовательские сценарии выполняет лид в браузере владельца, не в отдельном Playwright browser.

## Устройство

После синхронизации проверены 5 ownership safety tests, 24 HTTP/API/assets checks, существующие `admin-catalog-integration.php` и `catalog-integration.php`. Default archive совпадает с актуальными исходниками по всем 127 файлам; SHA-256 `bcc029517fa3419682f2ca9cd1d6b6a0692085a44d6ad64213edf8e407933ac4`. Прежний 126-file archive относится к старой базе. Новый head regression прошёл после воспроизведения отсутствующего verification meta до правки. Rendered body и все CSS сохранили прежние SHA-256; прежняя визуальная приёмка остаётся применима. Подробности: `D:/codex/temp/cmteh-v2-search-20261112/evidence/v2-head-sync.json`.

Исходный `opencart/extension/cmteh` не меняется. Launcher устанавливает стандартный архив в отдельный локальный OpenCart, затем копирует эти templates в его `storefront/v2`, assets — в `assets/v2-search`, а runtime `layout.twig` становится тонким include V2. Все рабочие страницы и бизнес-JS берутся из стандартного extension. Обычный production installer не включает эту папку и не переключает V1.

Изображения и их происхождение: `assets-sources.md`. Контакты, название компании, навигация и доступные бренды читаются из прежних моделей; CMS-логотип бренда имеет приоритет над локальным файлом. Незнакомый бренд выводится с текстовым fallback. Asset map описывает лишь представление логотипов, не ассортимент.
