# V2 — работающая локальная поисковая версия

Открыть: http://127.0.0.1:3162/ . Вкладка оставлена в пользовательском браузере. Главная — центральный поиск, светлый чертёж экскаватора,11реальных монохромных логотипов, компактная ссылка «Проценить список или файл». Страницы каталога и списка/файла, товары/склады, корзина и заявки работают на существующей OpenCart бизнес-системе.

## Проверено

- Выбранная главная: matched source/render1043×788 в одном paired image;1440×1080,1024×900,390×844; нет горизонтального overflow, контрольные изображения и контент загружены. Native browser viewport reset.
- Поиск с клавиатуры, пустой результат, бренды/доставка, mobile menu, quick dialog; склад/цена/срок/количество в корзине; принятая локальная заявка; ведущие нули/количество/unknownSKU в списке; CSV выбран штатным пользовательским диалогом и принят на локальную расценку; длинные title160/SKU52/no-photo и корзина. Тестовый stress product снят с публикации, данные не удалялись.
-5ownership tests,24HTTP/API/assets, real PHP/MySQL suites;127exactdefaultpackagefiles, SHA bcc029517fa3419682f2ca9cd1d6b6a0692085a44d6ad64213edf8e407933ac4. Три business suites в G4; отдельно новый shared head/admin-catalog/catalog regression155PASSlines. Synthetic settings восстановлены точно, счётчики выключены, provider requests0.
- Один независимый whole-branch review: нет замечаний. G4 clean clone/fresh DB и пользовательский Chrome: PASS, итоговый product ddb5f0d. SVG whitespace-only после первоначального bootstrap проверен отдельно поXMLcontent/HTTP hash; неизменённые suites формально не повторялись.

## Общая админка и границы

Одна действующая админка/бизнес-система, два оформления. V2 — отдельный локальный presentation overlay. Принятые параллельно общие analytics/verification настройки PR26 сохранены и в V2. Default extension/runtime/packaging относительно origin/main8a5ada0 не менялись нашим прогоном; V1 checkout/host/DB/DNS не изменялись нами. Другой прогон самостоятельно обновил V1 source до8a5ada0, мы только синхронизировали свою ветку.

Для локального просмотра ничего вводить не требуется. Данные/цены/контакты synthetic; настоящие клиентские данные/секреты не копировались. Публикация V2 в интернете, выбор окончательного дизайна и его подключение к рабочей админке/данным не входили в этот запуск; следующий шаг — выбор владельца.

## Повторный запуск и исходники

Из D:/codex/CMTEH.RU/V2-search:

```text
python opencart/dev/v2-local.py --runtime-dir D:/codex/temp/cmteh-v2-search-20261112 --port 3162 --upstream D:/codex/temp/cmteh-opencart-cache/opencart-4.1.0.4.zip start
```

Повторный start сохраняет тестовые данные. Для только визуальных изменений — та же команда с `refresh` без `--upstream`. Проверка — `python opencart/dev/v2-check.py --runtime-dir D:/codex/temp/cmteh-v2-search-20261112 --port 3162`; это локальный affected checkset, не production verifier. Подробности — opencart/designs/v2-search/README.md. Актуальные пути/адреса/команды и правило одной админки — D:/codex/CMTEH.RU/versions.json. Память для следующих агентов — существующий AGENTS.md плюс PROGRESS.md; добавления предложены в memory-proposal.md, пользовательский AGENTS не переписывался.

G4 temporary clone/volumes сохранены для доказательств, только его контейнеры остановлены. Основной V2 runtime3162 продолжает работать.
