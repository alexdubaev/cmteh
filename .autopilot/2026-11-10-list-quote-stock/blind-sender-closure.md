# G4: узкое закрытие имени отправителя

**PASS LOCAL** на `d67100d2f60761e9ff862f60ffa1db6008f1fb21`: новая реальная текстовая расценка `93b61c51-e642-4c81-b3ff-fe1415727055`, созданная через браузерный UI на собственном 3178, сохранена с HTTP 200 / `replayed:false`. Документированный loopback worker создал новое `.eml`; независимый Python stdlib MIME parser декодировал **`From: СМ ТЕХНО <info@cmteh.ru>`**, `To: info@cmteh.ru`. Display name и адреса проверены assert, subject сопоставлен с новой расценкой.

Исторические `blind-acceptance.md` и captures коммита 3441135 не изменены: прежнее расхождение остаётся записанным как наблюдение того коммита. На новом коммите локальное расхождение имени закрыто. Условный итог исходной таблицы после этой единственной узкой поправки: 10 реализовано локально, 0 частично, 0 нет, 1 NOT RUN. Остальные сценарии повторно не прогонялись.

**HOST config/headers и фактическое Inbox — NOT RUN.** Внешней отправки, `--hosting`, конфигурирования хостинга или чужого runtime не было. Проверен именно default LOCAL capture после refresh расширения. Runtime/clone оставлены для родительского агента, clone чистый.

Доказательства: `blind-sender-closure.eml`, `blind-sender-closure-result.json`, независимый повторяемый `blind-check-sender-closure.py`, screenshot `blind-closure-sender-quote.png`.

Точные shell-команды (cwd `D:/codex/temp/cmteh-list-quote-blind-clone-20261110`):

```powershell
git status --short; git fetch origin; git checkout --detach d67100d2f60761e9ff862f60ffa1db6008f1fb21; git rev-parse HEAD
```
Вывод, exit 0; status был пуст:
```text
From D:/codex/cmteh
   3441135..d67100d  codex/list-quote-stock-20261110 -> origin/codex/list-quote-stock-20261110
Previous HEAD position was 3441135 F1: preserve quote retries and canonical offer boundaries
HEAD is now at d67100d F2: use approved sender display name in capture and config examples
d67100d2f60761e9ff862f60ffa1db6008f1fb21
```

```powershell
python opencart/dev/runtime.py --runtime-dir D:/codex/temp/cmteh-list-quote-blind-runtime-20261110 --port 3178 start --upstream D:/codex/temp/cmteh-admin-final-runtime-20261110-r4/opencart-4.1.0.4.zip --refresh
```
Exit 0; cached build, только собственный web container recreated; финальный вывод:
```text
OpenCart local runtime: http://127.0.0.1:3178 (cmteh-oc-8b47cb0eba6a)
```

Новая UI-расценка выполнена через Node REPL / cached Playwright:
```javascript
var {createRequire}=await import('node:module');
var pw=createRequire('D:/codex/cmteh/package.json')('playwright');
var closureBrowser=await pw.chromium.launch();
var closurePage=await closureBrowser.newPage({viewport:{width:1440,height:1000}});
await closurePage.goto('http://127.0.0.1:3178/parts-list',{waitUntil:'domcontentloaded'});
await closurePage.locator('#parts-list-text').fill('RE568158;1\nBLIND-SENDER-CLOSURE;2');
await closurePage.locator('#quote-name').fill('Blind Sender Closure');
await closurePage.locator('#quote-email').fill('blind-sender-closure@example.test');
var closureResponsePromise=closurePage.waitForResponse(r=>r.url().endsWith('/api/quote'));
await closurePage.getByRole('button',{name:'Отправить на расценку',exact:true}).click();
var closureResponse=await closureResponsePromise;
console.log('quote response',closureResponse.status(),await closureResponse.json());
```
Фактический вывод:
```text
quote response 200 { id: '93b61c51-e642-4c81-b3ff-fe1415727055', replayed: false }
```
Видимое подтверждение:
```text
Расценка принята. Номер: 93b61c51-e642-4c81-b3ff-fe1415727055. Менеджер свяжется с вами.
```

```powershell
python opencart/dev/runtime.py --runtime-dir D:/codex/temp/cmteh-list-quote-blind-runtime-20261110 --port 3178 compose exec -T --user www-data web php /workspace/bin/send-quotes.php --config=/var/www/html/config.php --capture=blind-sender-closure.eml --limit=1; python opencart/dev/runtime.py --runtime-dir D:/codex/temp/cmteh-list-quote-blind-runtime-20261110 --port 3178 compose cp web:/var/www/cmteh-private/quote-test-capture/blind-sender-closure.eml D:/codex/temp/cmteh-list-quote-stock-20261110/blind-sender-closure.eml
```
Exit 0:
```text
{"busy":false,"sent":1,"retry":0,"failed":0}
 cmteh-oc-8b47cb0eba6a-web-1 Copying cmteh-oc-8b47cb0eba6a-web-1:/var/www/cmteh-private/quote-test-capture/blind-sender-closure.eml to D:/codex/temp/cmteh-list-quote-stock-20261110/blind-sender-closure.eml
 cmteh-oc-8b47cb0eba6a-web-1 Copied cmteh-oc-8b47cb0eba6a-web-1:/var/www/cmteh-private/quote-test-capture/blind-sender-closure.eml to D:/codex/temp/cmteh-list-quote-stock-20261110/blind-sender-closure.eml
```

```powershell
$env:PYTHONIOENCODING='utf-8'; python D:/codex/temp/cmteh-list-quote-stock-20261110/blind-check-sender-closure.py
git status --short
```
Parser exit 0; final status пуст:
```text
{"commit": "d67100d2f60761e9ff862f60ffa1db6008f1fb21", "display_name": "СМ ТЕХНО", "from_address": "info@cmteh.ru", "to": "info@cmteh.ru", "reply_to": "blind-sender-closure@example.test", "subject": "Расценка списка 93b61c51-e642-4c81-b3ff-fe1415727055", "verdict": "PASS LOCAL sender-name closure", "external_inbox": "NOT RUN"}
PASS: independent MIME parser decoded From display_name = СМ ТЕХНО; from/to = info@cmteh.ru; new quote ID matched.
```

Техническая попытка до создания новой расценки: предыдущая Node REPL-сессия не сохранилась (`pw is not defined`), поэтому первый запуск той же capture-команды вернул `sent:0`, а копирование ожидаемо не нашло ещё не созданный `.eml`. Это не было засчитано PASS. Cached loader и browser были инициализированы заново; после единственной новой UI-расценки получен подтверждённый результат выше. Продукт и конфигурация для обхода ошибки не менялись.
