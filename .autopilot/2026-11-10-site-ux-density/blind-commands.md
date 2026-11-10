# Команды независимой приёмки

Все команды выполнены фактически, 2026-11-10. Рабочая директория установки/seed — `D:/codex/temp/cmteh-site-ux-blind-clone-20261110`. Скрипты наблюдения и артефакты находятся вне репозитория, рядом с этим файлом. Native session token URLs не выводились и не сохранялись; административных браузерных переходов не было.

## Clean clone

```powershell
git clone --no-hardlinks --branch codex/site-ux-density-20261110 D:/codex/cmteh D:/codex/temp/cmteh-site-ux-blind-clone-20261110
git checkout --detach 5c6728c5e7065cfc9c877ed1bfda38da497c2851
```

Оба exit 0; detached HEAD на указанном коммите.

```powershell
Get-Content -LiteralPath D:/codex/temp/cmteh-site-ux-density-20261110/blind-brief.md -Raw
Get-Content -LiteralPath opencart/README.md -Raw
Get-Content -LiteralPath opencart/dev/README.md -Raw
Get-Content -LiteralPath opencart/fixtures/README.md -Raw
python opencart/dev/runtime.py --help
python opencart/dev/runtime.py --runtime-dir D:/codex/temp/cmteh-site-ux-blind-runtime-20261110 --port 3181 start --help
```

Exit 0. Прочитаны инструкция и эксплуатационные команды; root Next.js не запускался. Для определения точной команды seed просмотрены первые 75 строк verify-local и определение import_fixture в release_checks.py; UI source/intent не читался.

## Изолированная установка

```powershell
docker network ls --format '{{.Name}}'
docker network inspect $(docker network ls -q) --format '{{.Name}} {{range .IPAM.Config}}{{.Subnet}} {{end}}'
```

Exit 0. Сети 10.221.0.0/28 и 10.221.0.16/28 существовали, выделенная 10.221.0.32/28 была свободна. Ничего не удалялось.

```powershell
$env:CMTEH_DOCKER_SUBNET='10.221.0.32/28'
python opencart/dev/runtime.py --runtime-dir D:/codex/temp/cmteh-site-ux-blind-runtime-20261110 --port 3181 start --upstream D:/codex/temp/cmteh-admin-final-runtime-20261110-r4/opencart-4.1.0.4.zip
```

Exit 0. Docker использовал закешированный build; созданы только собственные сеть/тома/контейнеры проекта `cmteh-oc-b71b878b3c7a`. Итог: `OpenCart local runtime: http://127.0.0.1:3181 (cmteh-oc-b71b878b3c7a)`.

```powershell
python -c "import sys; sys.path.insert(0, 'opencart/dev'); from runtime import LocalOpenCart; from release_checks import import_fixture; r=LocalOpenCart(__import__('pathlib').Path('D:/codex/temp/cmteh-site-ux-blind-runtime-20261110'),3181); import_fixture(r,'/workspace/fixtures/demo.ndjson',36,'blind-demo',expected_updated=13)"
```

Exit 0: `PASS: catalog preview and confirmed import (36 added, 13 updated)`.

## Браузерное исследование

Locked Playwright взят из checkout `D:/codex/cmteh`, чей package-lock содержит @playwright/test 1.63.0. Зависимости в clean clone не устанавливались. Собственные поздние скрипты получают расположение установленного инструментария явно через `--browser-tooling`, аналогично документированному интерфейсу verify-local.

```powershell
node D:/codex/temp/cmteh-site-ux-density-20261110/blind-trial.cjs
node D:/codex/temp/cmteh-site-ux-density-20261110/blind-parts-inspect.cjs
node D:/codex/temp/cmteh-site-ux-density-20261110/blind-parts-trial.cjs
```

Все exit 0. Начальное исследование home/parts-list в Chromium 390×844 и основной paste→check. Эти первые три скрипта явно подключают тот же установленный `D:/codex/cmteh/node_modules/playwright`; их содержимое сохранено рядом с отчётом. Основной trial: scrollWidth 390, pageerror [], фокус list-results-title; два найденных предложения и неизвестная строка видны в результате.

```powershell
node D:/codex/temp/cmteh-site-ux-density-20261110/blind-public-pages.cjs --browser-tooling D:/codex/cmteh
```

Exit 0. Шестнадцать маршрутов, 15 HTTP 200 и ожидаемый HTTP 404 для /not-a-real-page. Для всех scrollWidth=390, pageerror []. Фактические тексты, ссылки, размеры и ошибки в `blind-page-observations.json`, viewport PNG по каждому маршруту.

```powershell
node D:/codex/temp/cmteh-site-ux-density-20261110/blind-interactions.cjs --browser-tooling D:/codex/cmteh
```

Первый запуск exit 1: неверный селектор getByRole('button', name='Изменить список') ждал 30 с. Проверка DOM показала, что это ссылка. До ошибки открылись фильтры и товар из результата попал в корзину (2 шт.).

```powershell
node D:/codex/temp/cmteh-site-ux-density-20261110/blind-selector-inspect.cjs --browser-tooling D:/codex/cmteh
```

Exit 0. «Изменить список» — `a[href="#parts-list-text"]`; «На расценку» — ссылки на #quote-details. В собственном скрипте селекторы заменены на getByText для этих ссылок.

```powershell
node D:/codex/temp/cmteh-site-ux-density-20261110/blind-interactions.cjs --browser-tooling D:/codex/cmteh
```

Повторный запуск exit 0. Фильтры, добавление→корзина, edit→повторный unmatched, quote-open и menu-open фактически прошли. Данные в `blind-interaction-observations.json`; viewport PNG. Формы не отправлялись.

```powershell
node D:/codex/temp/cmteh-site-ux-density-20261110/blind-targeted-browsers.cjs --browser-tooling D:/codex/cmteh
```

Exit 0. Chromium375×667, WebKit375×667, Chromium768×1024, Chromium1440×900: initial→paste→check, сохранение фактического viewport. На всех активен list-results-title, нет pageerror/горизонтального переполнения; конкретное предложение и действие видны после check. На 768/1440 дополнительно catalog/category/product/contacts, без overflow. Логи `blind-targeted-browser-observations.json`.

```powershell
node D:/codex/temp/cmteh-site-ux-density-20261110/blind-file-and-catalog.cjs --browser-tooling D:/codex/cmteh
```

Exit 0. TXT через штатный file input→check показывает 2 предложения. Единственный POST в этом изолированном сценарии: `/api/list-check`. Просмотрены товар с «Цена по запросу» и catalog?page=2; страницы работоспособны. Снимки сохранены.

Снимки вручную просмотрены инструментом view_image для всех основных типов страниц и целевых состояний, включая фактический результат короткого телефона в Chromium и WebKit; оценка не ограничивалась DOM-координатами.

## Конечная чистота

```powershell
git status --porcelain=v1
git rev-parse HEAD
Get-Date -Format o
```

Exit 0. Статус — пустой вывод. HEAD `5c6728c5e7065cfc9c877ed1bfda38da497c2851`. Время фиксации чистого статуса `2026-11-10T23:39:58.5499404+03:00`. Код не менялся. Стенд оставлен работающим для проверки root; чужие ресурсы не затрагивались.
