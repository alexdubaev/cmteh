# Границы
site JSON keys: yandex_metrika_id, google_analytics_id, yandex_verification, google_site_verification. String, empty default; old content без ключей совместим. Server write+read validation; no executable HTML values. Existing site admin ACL/CSRF unchanged. Public shared StorefrontPages+layout only. One CMS; no V2 source/runtime changes.
Tests use synthetic isolated test data only. Mock/abort providers in browser. No real analytics account traffic.
User-owned AGENTS unchanged. Safe Git branch/PR/merge and release safeguards retained. Test site only, no production cmteh.ru, DNS, native upgrade, DB rollback or ACL changes.

## Выполнено T01
Четыре необязательных site strings yandex_metrika_id, google_analytics_id, yandex_verification, google_site_verification. YM positive decimal up to15digits; GA4 G-…; verification safe characters. Write rejects atomically, public view fail-closed. analytics.js static shared bootstrap; no custom events, remote GA enhanced measurement controlled in account. Actual isolated MySQL135 and native browser green; oldsite record preserved.
