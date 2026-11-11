# F1 — Точность источников и надёжность журнала отката

**Требования:** R01, R02, R09, R10, R12
**Зависит от:** —
**Зона:** `opencart/extension/cmteh/system/AdminBusiness.php` · `opencart/tests/admin-business-integration.php` · `D:/codex/temp/cmteh-admin-unification-20261111/own-event-registration.php` · `D:/codex/temp/cmteh-admin-unification-20261111/ops-prepared-index.json` · `D:/codex/temp/cmteh-admin-unification-20261111/`
**Волна:** 3
**Ревью:** да — ремонт ветки
**Модель:** сильная

## Что должно заработать

Два подтверждённых FIX NOW единственного whole-review. Product и ops не изменяются за пределами точных условий; действия только в локальных изолированных данных, хостинг ещё не меняется.

## Из брифа, дословно

> «но должен быть заточен под наш сайт и наши данные»
> «Убери все что не относится к моему сайту»

## Разделы спецификации

§2, §3, §4; interfaces.md; frozen product37c5a44; ops index в указанном каталоге.

## Критерии приёмки — дословно из ревью

- [ ] FIX NOW: Spec · `opencart/extension/cmteh/system/AdminBusiness.php:124` · `GROUP BY source` использует `utf8mb4_unicode_ci`: разные URL `/A` и `/a` объединяются, показывается один адрес с суммой двух обращений. Подтверждено SELECT-only на текущем MySQL runtime. Условие: сохранять различие байтов сохранённых URL; интеграционный пример должен возвращать две группы по одному обращению.
- [ ] FIX NOW: Recovery · `D:/codex/temp/cmteh-admin-unification-20261111/own-event-registration.php:30` · COMMIT предшествует durable ownership ledger. Остаточный `own-events.json.new` или ошибка записи на строке32 оставляет три включённых события без доступного rollback ledger; `disable` отклоняется на строке25. Результат `rename()` также не проверяется. Условие: проверенный durable intent до COMMIT либо безопасное восстановление ownership из pending ledger; failure injection после COMMIT/перед rename должна позволять повторный запуск и отключение только своих трёх событий.

## Ограничения

Parser REPORT finding не входит в FIX NOW: не расширять ремонт на него без фактического отказа обязательного восстановления. Остальные продуктовые файлы, schema/auth/ACL/config/cron/mail не менять. Изменения ops лежат вне Git; вернуть точные SHA/проверки и краткую запись для коммита журнала. Собственные failure fixtures/базу очищать строго по nonce ownership. Никакой подмены тестов или ослабления ROOT-GO/artifact/source checks.
