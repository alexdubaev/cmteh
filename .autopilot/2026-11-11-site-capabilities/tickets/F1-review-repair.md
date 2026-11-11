# F1 — Исправления по итоговому ревью

**Требования:** G01, G04, G07, G08
**Зависит от:** —
**Зона:** `opencart/extension/cmteh/admin/controller/module/cmteh_catalog.php` · `opencart/tests/browser/inventory-import-happy.mjs` · `D:/codex/temp/cmteh-warehouse-ops-20261111/proof-ops.py` · `D:/codex/temp/cmteh-warehouse-ops-20261111/test-ops.py` · `D:/codex/temp/cmteh-warehouse-ops-20261111/ops-prepared-index.json`
**Волна:** 4
**Ревью:** да — ремонт ветки
**Модель:** сильная

## Критерии приёмки — дословно из единственного whole-change review

- Craft · `D:/codex/temp/cmteh-warehouse-ops-20261111/proof-ops.py:71,84` · План содержит 5 suites, setup пропускает recovery-файл, а run перебирает все пять. Подготовленный host-proof неизбежно остановится на отсутствующем файле; простое копирование также недостаточно: recovery требует mode/token, итоговый gate допускает ровно 4 suites. Условие: четыре обычных suite запускаются отдельно, recovery-файл устанавливается и используется только специальным сценарием seed/advance/verify/cleanup.
- Spec/Craft · `opencart/extension/cmteh/admin/controller/module/cmteh_catalog.php:649` · Ручные текстовые поля передаются после native Request::clean без обратного декодирования. Изолированный repro: введённое `A & B` сохраняется как `A &amp; B`; публичные Twig/DOM показывают лишнюю сущность, повторное редактирование усиливает искажение. Условие: исходный текст сохраняется без HTML-сущностей и экранируется при выводе, включая название/город/срок и остальные ручные текстовые поля.

## Границы и проверки

Только два FIX NOW; остальные три REPORT не расширяют этот ремонт. Не менять ACL, Request::clean, общий rich HTML sanitizer, доменные контракты и целевой хост. Никаких host writes/GO/SMTP. Все GO остаются закрыты. Причина, минимальный фикс, узкая значимая регрессия через actual native HTTP для ручных полей и локальная проверка согласованности Ops protocol. Не повторять широкие suites/две версии MySQL/strict. Внешние helper хеши переиндексировать без выдуманной приёмки. Сначала code commit с Autopilot trailers, затем контракт FILES/TESTS/REDPROOF/CONCERNS. Корневой агент сделает единственную scoped closure.
