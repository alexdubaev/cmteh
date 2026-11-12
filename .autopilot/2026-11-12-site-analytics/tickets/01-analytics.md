# 01 — Аналитика и подтверждение прав
**Требования:** R01, R02, R03, R04, R05, R06
**Зависит от:** —
**Зона:** `opencart/extension/cmteh/` · `opencart/tests/` · `opencart/admin-guide.md`
**Волна:** 1
**Ревью:** нет
**Модель:** обычная
## Что должно заработать
Четыре общих необязательных поля в подписанной группе существующих Настроек сайта. Валидные ID включают async-счётчики на публичной витрине, коды формируют метатеги. Включение/очистка без изменения кода. Ни второй админки, ни второго backend, ни произвольных script/HTML.
## Из брифа, дословно
«Настройки сайта → Аналитика и подтверждение прав»; «Яндекс Метрика — номер счётчика»; «Google Analytics 4 — идентификатор измерения»; «Яндекс Вебмастер — код подтверждения»; «Google Search Console — код подтверждения».
## Разделы спецификации
Все (короткая спецификация).
## Критерии приёмки
- [ ] Все четыре поля в понятной отдельной группе, текущие ACL/сохранение без изменений.
- [ ] Сохранение/перечитывание/очистка и сохранность других полей; сервер отвергает неверные значения атомарно, legacy read fail-closed.
- [ ] Два meta в head, два официальных async bootstrap только при заполнении; no arbitrary JS/HTML/URL; no analytics admin/API.
- [ ] Локальные real MySQL integration + native form/public browser с провайдерами mock, никакого настоящего telemetry.
- [ ] Документировать единые ключи/common backend V1/V2, не менять visual V2 checkout/runtime.
## Затронутый checkset
PHP lint changed files; admin-catalog-integration real MySQL (extend valuable boundaries) + short analytics browser for native save/reload/clear/head/script queue using synthetic fixture; re-use existing ready images/tooling. Full generic verifier remains strict when used, no downgrade. Root Next is reference and out of scope. No warehouse/MySQL version matrix.
