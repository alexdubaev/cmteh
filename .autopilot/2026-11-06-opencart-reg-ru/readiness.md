# Подготовка: источники и совместимость

| Область | Результат |
|---|---|
| Git | origin/main и эталон: 61a10e2d6a747478fc8017475d5a06ceafbc5538. Ветка autopilot/opencart-reg-ru, отдельный worktree. |
| Эталон | Next.js mock на loopback:3148; 19 ответов (включая 404), XML и 18 кадров на 1440/1024/390. Артефакты: D:/codex/temp/cmteh-opencart-evidence. |
| Синтетика | Точные DTO из эталона: 16 товаров, 8 категорий, 11 брендов, 12 навигационных ссылок, 1 статья. Фикстуры не подтверждают фактические контакты, условия, цены или наличие. |
| Опубликованный источник | По одиночным read-only HTTP запросам cmteh.ru/ и /catalog вернули 200 со старыми title; sitemap.xml вернул 404. Домен для этой миграции не подтверждён, полный crawl не проводился. Сравнение только с эталоном. |
| OpenCart | GitHub latest: 4.1.0.4. Получен asset 32816480 байт; SHA-256 cdef537f5a0a7e1b4ddbe16f589dd2e80b10150a09bfbe93ce76ef2d5c3218c0 совпал. Минимум PHP 8.1 по релизу; локальная цель 8.3. |
| PHP проверки установщика | GD, cURL, openssl_encrypt, zlib, zip, DB driver, iconv или mbstring; проверка writable config на этапе установки. Приложению PDO MySQL и DOM/XML. |
| Риски официального ядра | У 4.1.0.4 есть отчёты о SVG в нативном блоге, XSS в пользовательских данных и обходе оплаты через rewards. Нативные публичные blog/account/checkout/API запрещены границей маршрутизации, SVG не выдаётся как исполняемое активное медиа. Это локальный кандидат; отдельная проверка применимости актуальных advisories перед размещением обязательна. |
| REG.RU | Документация провайдера подтверждает PHP на Hosting Linux кроме Host-Lite, cron, отдельные лимиты CPU, бэкапы без cron/панели. Действующие web/CLI/PHP/MySQL/SMTP, rewrite/HTTPS/open_basedir и фактические лимиты аккаунта: NOT RUN. |
| Данные и размещение | Владелец: «еще нет, пусть будет локально». Реальный экспорт, реальный SMTP, нагрузка и cutover не выполняются. Старый CMS читается только как справочник. |

Официальные источники: [OpenCart release](https://github.com/opencart/opencart/releases/tag/4.1.0.4), [установщик](https://github.com/opencart/opencart/blob/4.1.0.4/upload/install/controller/install/step_2.php), [PHP REG.RU](https://help.reg.ru/support/hosting/php-asp-net-i-skripty/kak-izmenit-parametry-php), [cron](https://help.reg.ru/support/hosting/php-asp-net-i-skripty/planirovshchik-cron), [CPU](https://help.reg.ru/support/hosting/sayt-ne-rabotayet/prevyshen-limit-na-ispolzovaniye-cpu), [бэкапы](https://help.reg.ru/support/hosting/rezervnoye-kopirovaniye-hostinga/bekapy).

Подтверждённые локальные проверки:

- 44 разных URL эталонных XML отвечают 200. Снимок остальных HTTP-контрактов — reference-http.json, в том числе short search, filtered robots, page canonical, два ожидаемых 404.
- Установка T01 на чистом OpenCart/MySQL: SHA-256 пакета ядра, нативный Installer, модуль, PDO/private storage, анонимная админка, 10 закрытых путей, сессия и POST для миграций. PHP 8.3.36, MySQL 8.4.13, 5 PHP lint. Проверенный исходный код платформы 4d26bb1, 19 файлов идентичны снимку проверки.
- Отдельный обязательный gate неизменённого Next.js на 61a10e2 и совместимой чистой копии CMS a54d54aade9a23dee6e5eb0b930dd45ff3344e1d: 383 backend, 65 storefront, 2 Chromium, build, TypeScript, audit 0, canary не попал в клиентский bundle. Этот результат не является доказательством PHP-продукта.

Приёмка: чистый clone 343e9cc прошёл документированный PHP gate; 24 PHP lint, 13 каталог, 18 заявки, 5 smoke, 7 HTTP-групп, 44 canonical 200, 2 Chromium с реальной MySQL, нативные ACL/публикация и отдельное восстановление БД/медиа. Отдельная opt-in HTTP проверка — 9 групп, в том числе 301/410, Unicode, публикация нулевой цены. Ревью без блокирующих замечаний. Независимая приёмка нашла пустую дату статьи; исправлена в 61e48c1, 9 реальных Chromium проверок по затронутым страницам и ширинам. Итоговый ZIP из чистого checkout 61e48c1, SHA-256 0ed17dd316cb79c3a47cb46b5f8b03b6578a67418ac9f932d1f7cf560b3f3039.
