# Границы
site JSON keys: yandex_metrika_id, google_analytics_id, yandex_verification, google_site_verification. String, empty default; old content без ключей совместим. Server write+read validation; no executable HTML values. Existing site admin ACL/CSRF unchanged. Public shared StorefrontPages+layout only. One CMS; no V2 source/runtime changes.
Tests use synthetic isolated test data only. Mock/abort providers in browser. No real analytics account traffic.
User-owned AGENTS unchanged. Safe Git branch/PR/merge and release safeguards retained. Test site only, no production cmteh.ru, DNS, native upgrade, DB rollback or ACL changes.
