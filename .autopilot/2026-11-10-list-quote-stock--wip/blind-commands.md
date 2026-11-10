# Выполненные команды и результаты

Рабочий каталог после clone: `D:/codex/temp/cmteh-list-quote-blind-clone-20261110`.

```powershell
git clone --no-hardlinks D:/codex/cmteh D:/codex/temp/cmteh-list-quote-blind-clone-20261110
```
```text
Cloning into 'D:/codex/temp/cmteh-list-quote-blind-clone-20261110'...
done.
```
```powershell
git checkout --detach 3441135dc2ae217bdc024f43cb247e467d2190a6
```
```text
HEAD is now at 3441135 F1: preserve quote retries and canonical offer boundaries
```

Перед запуском выполнены `docker network ls --format '{{.ID}} {{.Name}}'`, `docker network inspect $(docker network ls -q) --format '{{.Name}} {{range .IPAM.Config}}{{.Subnet}} {{end}}'` и `Get-NetTCPConnection -LocalPort 3178 -ErrorAction SilentlyContinue | Select-Object LocalAddress,LocalPort,State`. Подсеть 10.218.0.0/28 среди существующих не присутствовала; порт 3178 не слушался. Дочерние/родительские стенды не останавливались.

Прочитаны исходный brief целиком; `opencart/README.md`, `opencart/dev/README.md`, `opencart/fixtures/README.md`, packaging README и относящиеся к правам/native history строки admin-guide. Использованы `runtime.py --help` и `runtime.py start --help`. Для документированного браузерного загрузчика прочитан только loader `tests/browser/capture.mjs`; сценарии продукта исполнялись вручную через Playwright.

```powershell
$env:CMTEH_DOCKER_SUBNET='10.218.0.0/28'; $env:CMTEH_BROWSER_TOOLING='D:/codex/cmteh'; python opencart/dev/runtime.py --runtime-dir D:/codex/temp/cmteh-list-quote-blind-runtime-20261110 --port 3178 start --upstream D:/codex/temp/cmteh-admin-final-runtime-20261110-r4/opencart-4.1.0.4.zip
```
Финальный вывод, exit 0 (build использовал кеш):
```text
OpenCart local runtime: http://127.0.0.1:3178 (cmteh-oc-8b47cb0eba6a)
```

```powershell
python opencart/dev/runtime.py --runtime-dir D:/codex/temp/cmteh-list-quote-blind-runtime-20261110 --port 3178 compose exec -T web php /workspace/bin/import-catalog.php --config=/var/www/html/config.php --input=/workspace/fixtures/demo.ndjson --preview
```
```json
{
    "added": 36,
    "updated": 13,
    "skipped": 0,
    "errors": 0,
    "sha256": "28c6a5e8793ae7200404beca0176d37b9b966b3f78fa53bb90bd0edc5eb11a08",
    "diagnostics": [],
    "publication_changes": []
}
```
```powershell
python opencart/dev/runtime.py --runtime-dir D:/codex/temp/cmteh-list-quote-blind-runtime-20261110 --port 3178 compose exec -T web php /workspace/bin/import-catalog.php --config=/var/www/html/config.php --input=/workspace/fixtures/demo.ndjson --apply --confirm=28c6a5e8793ae7200404beca0176d37b9b966b3f78fa53bb90bd0edc5eb11a08 --run-key=blind-20261110
```
```json
{
    "added": 36,
    "updated": 13,
    "skipped": 0,
    "errors": 0,
    "run_key": "blind-20261110",
    "sha256": "28c6a5e8793ae7200404beca0176d37b9b966b3f78fa53bb90bd0edc5eb11a08",
    "next_line": 50,
    "finished": true
}
```

Реальные browser API действия выполнялись через persistent Node REPL, с кешированным `createRequire('D:/codex/cmteh/package.json')('playwright')`, `chromium.launch()`, viewport 1440×1000. Ключевые исполненные действия (page — реальная storefront tab):
```javascript
await page.goto('http://127.0.0.1:3178/request');
await page.getByText('Проверить список или отправить на расценку',{exact:true}).click();
await page.locator('#parts-list-text').fill('RE568158;3\n0000550380;2\nBLIND-NO-SUCH-20261110;4');
await page.getByRole('button',{name:'Проверить наличие',exact:true}).click();
await page.getByRole('button',{name:'В корзину',exact:true}).first().click();
await page.getByRole('link',{name:'Открыть корзину',exact:true}).click();
await page.getByLabel('Выбрать файл',{exact:true}).check();
await page.locator('#parts-list-file').setInputFiles(evidence+'/blind-list.csv');
await page.getByRole('button',{name:'Отправить на расценку',exact:true}).click();
await page.locator('#parts-list-file').setInputFiles(evidence+'/blind-list.txt');
await page.getByRole('button',{name:'Проверить наличие',exact:true}).click();
await page.locator('#parts-list-file').setInputFiles(evidence+'/blind-list.xlsx');
await page.getByRole('button',{name:'Проверить наличие',exact:true}).click();
await page.locator('#parts-list-text').fill(Array.from({length:1000},(_,i)=>i===0?'RE568158;1':`BLIND-LIMIT-${String(i+1).padStart(4,'0')};1`).join('\n'));
await page.getByRole('button',{name:'Проверить наличие',exact:true}).click();
await page.getByText('Проверено строк: 1000',{exact:true}).waitFor();
await page.getByRole('button',{name:'Далее',exact:true}).click();
```
Синтетические контакты вводились перед submit; PDF получен `page.pdf()`, PNG — `page.screenshot()`, XLSX — Python stdlib `zipfile`, без дополнительных пакетов. Результаты каждой группы находятся в screenshots и native detail TXT. Первый browser timeout был ошибочным поиском navigation link как button; последующий корректный click link прошёл. Native `/admin/` load timeout из-за полного load был продолжен по фактически видимой форме. Первое меню перекрывал штатный security modal; он закрыт кнопкой `.btn-close`. Эти действия не заменяли проверки и не обходили права.

```powershell
python opencart/dev/runtime.py --runtime-dir D:/codex/temp/cmteh-list-quote-blind-runtime-20261110 --port 3178 compose exec -T --user www-data web php /workspace/bin/send-quotes.php --config=/var/www/html/config.php --capture=blind-capture.eml --limit=5
```
```text
{"busy":false,"sent":5,"retry":0,"failed":0}
```
```powershell
python opencart/dev/runtime.py --runtime-dir D:/codex/temp/cmteh-list-quote-blind-runtime-20261110 --port 3178 compose exec -T --user www-data web php /workspace/bin/send-quotes.php --config=/var/www/html/config.php --capture=blind-text.eml --limit=1
python opencart/dev/runtime.py --runtime-dir D:/codex/temp/cmteh-list-quote-blind-runtime-20261110 --port 3178 compose exec -T --user www-data web php /workspace/bin/send-quotes.php --config=/var/www/html/config.php --capture=blind-csv.eml --limit=1
```
Каждая команда exit 0:
```text
{"busy":false,"sent":1,"retry":0,"failed":0}
```
Captures скопированы отдельными выполненными командами:
```powershell
python opencart/dev/runtime.py --runtime-dir D:/codex/temp/cmteh-list-quote-blind-runtime-20261110 --port 3178 compose cp web:/var/www/cmteh-private/quote-test-capture/blind-capture.eml D:/codex/temp/cmteh-list-quote-stock-20261110/blind-capture.eml
python opencart/dev/runtime.py --runtime-dir D:/codex/temp/cmteh-list-quote-blind-runtime-20261110 --port 3178 compose cp web:/var/www/cmteh-private/quote-test-capture/blind-text.eml D:/codex/temp/cmteh-list-quote-stock-20261110/blind-text.eml
python opencart/dev/runtime.py --runtime-dir D:/codex/temp/cmteh-list-quote-blind-runtime-20261110 --port 3178 compose cp web:/var/www/cmteh-private/quote-test-capture/blind-csv.eml D:/codex/temp/cmteh-list-quote-stock-20261110/blind-csv.eml
$env:PYTHONIOENCODING='utf-8'; python D:/codex/temp/cmteh-list-quote-stock-20261110/blind-check-mime.py
```
Копирование всех трёх завершилось exit 0. Последние строки независимого parser, verbatim:
```text
PASS: independent MIME parser; text original present; CSV/PNG attachments exact original bytes; static info@cmteh.ru addresses
OBSERVED: capture display name is СМТЕХНО; brief requests СМ ТЕХНО. External Inbox delivery NOT RUN.
```
Полный структурированный результат — `blind-mime-results.json`.

```powershell
git status --short
git rev-parse HEAD
docker ps --filter name=cmteh-oc-8b47cb0eba6a --format '{{.Names}} {{.Status}} {{.Ports}}'
```
```text
3441135dc2ae217bdc024f43cb247e467d2190a6
cmteh-oc-8b47cb0eba6a-web-1 Up 6 minutes 127.0.0.1:3178->80/tcp
cmteh-oc-8b47cb0eba6a-db-1 Up 6 minutes (healthy) 3306/tcp, 33060/tcp
```
