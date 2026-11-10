# Фактические проверки

## Данные каталога
- 2026-11-10, source b33e049: новый admin-catalog-integration.php —20PASS; прежний catalog-integration.php —13PASS на изолированных таблицах MySQL8.4.13.
- Те же20+13PASS отдельно на настоящем MySQL5.7.44 в dedicated runtime3171 (image digest зафиксирован в mysql57-backend-evidence.json). Не является приёмкой хостинга.
- Никаких существующих серверных записей для этих проверок не менялось.

## REG.RU до обновления
- Read-only SSH и браузер/HTTP Basic Auth успешны. Состояние БД и браузера в двух staging baseline JSON.
- Совпадение двух ключевых исходников с origin/main5acbdd9 подтверждено SHA256.
- Все45 mapped публичных URL HTTP200:16products/8categories/11brands/8pages/2articles.
- Штатные списки Products/Categories/Manufacturers доступны, язык английский, собственного меню нет. Прямой редактор возвращает Permission Denied. Причина —отсутствуют cmteh_catalog/leads в access/modify существующей административной группы; module/cmteh присутствует.
- Права/контент/код/конфиги/DNS на хостинге не менялись.

## Ожидают готового исходника
Полный строгий release gate, браузер форм, независимый обзор ветки, слепая приёмка из чистого клона, новая версия на REG.RU и корректная ACL существующего администратора пока НЕ подтверждены.

## Завершённый полный gate до F2
68898a1: строгий verify-local на fresh3174/MySQL8.4.13 exit0; packageb103ecbd...; всепроверкивключаяполныйbrowserпринаследованномreview-closure прошли. Но ручной просмотр18capture выявил пустые product/article cards; F2 также восстанавливает собственный escaping/includes. Поэтому этотPASS НЕ выдаётся за выпуск окончательного F2кандидата: после изменения appTwig требуется свежий полный gate.
