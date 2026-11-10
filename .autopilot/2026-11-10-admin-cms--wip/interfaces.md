# Границы, решённые в спецификации

## Правила проекта
Действующие AGENTS.md и прямые Git-правила владельца обязательны. Ветка codex/admin-cms-20261110 от origin/main 5acbdd9; корень D:/codex/worktrees/cmteh-admin-cms-20261110. Никаких изменений других веток/worktree, main напрямую, CI, production/DNS/реальных данных или секретов. Оркестратор пишет только .autopilot и Git, продуктовый код — исполнители.

Договорённости прежней сборки, дословно: «еще нет, пусть будет локально» — текущая приёмка локальная; не отправлять данные, почту или пакет на внешний хост. Эталон 61a10e2 остаётся неизменённым. Старый CMS только справочник для чтения. Сохранять публичные пути и корзину, не подменять цену фактом из демо. Более свежий handoff сообщает о закрытом test.cmteh.ru; текущее разрешение — реализация админки, а не production-переход.

## Контракты таска 01
Catalog::save / validate остаются совместимыми для импорта.
Новые публичные методы Catalog:
- editRows(string $type, int $page=1, string $search='', string $status=''): array — максимум 25 строк, id/slug/title/status/native_id, поиск и статус; существующие вызовы совместимы.
- editorChoices(string $type): array — списки brand/category/product/page для именованных select; id/slug/title/status/native_id, category parent_slug, публичный path; без private содержимого. Возможна ограниченная выдача/поиск для больших каталогов, без скрытого усечения выбора существующей связи.
- saveFromAdmin(string $type,array $input,bool $confirmSlugChange=false): string — save атомарно с проверкой смены URL и прямыми 301; неизменное имя не меняет slug. Сохраняет серверные поля, ACL проверяется контроллером.
- deleteRecord(string $type,string $id): void — только явное действие, запрет зависимостей/системных записей, корректные старые URL, сохранность прочих данных.
- editRecord возвращает актуальные native_id, parent_slug, category_slugs, main_image_path, images/specification_items и прочие обслуживаемые формы без потери native данных.

Поля product: brand_slug выбирается из списка, category_slug основная, category_slugs дополнительные; main_image_path публичный catalog/...; specification_items массив name/value/group; images сохраняют существующую модель. category: parent_slug, image_path. brand: image_path и SEO metadata не должны теряться.
Поля home: home_blocks=[{key,enabled,sort_order}], разрешённые ключи hero,brands,categories,products,order,articles,sections; brands_mode=auto|selected; brand_slugs=[]; categories_mode=auto_roots|auto_all|selected; category_slugs=[]; categories_limit=0..300 (0 — все, существующий вид по умолчанию 8). Фильтрация только опубликованными; выбранный порядок сохраняется. Эти поля валидируются сервером, Twig не содержит постоянный slice(0,8).
site.social_links=[{label,url}] через обычные поля, безопасные URL.

## Владение файлами
01 — system/Catalog*.php, system/NativeCatalog.php, system/StorefrontPages.php и нужные storefront Twig (главная/home fragments и соцссылки); tests/admin-catalog-integration.php. Не admin/controller или admin/view.
02 — admin/controller/module/cmteh_catalog.php, admin/view/template/module/cmteh_catalog.twig и собственные admin assets; tests/browser/admin-cms.mjs. Использует API 01. Никаких записей в system или регистрацию events.
03 — admin/controller/event/cmteh_admin.php (новый), module/cmteh.php, собственные admin словари, module/cmteh.twig и leads при необходимости; dev/release_checks.py только дополнения навигации/языка; admin-guide.md и README. Не редактирует cmteh_catalog.php/Twig.
Изменение API согласовать с оркестратором до реализации. Не отключать старые проверки; один независимый обзор всей ветки, без повторного формального ревью.

## Среда и проверки
PHP 8.3, OpenCart 4.1.0.4 из D:/codex/temp/cmteh-opencart-cache/opencart-4.1.0.4.zip (SHA проверяется сборщиком); Docker cached cmteh-opencart-web:local; Node/Playwright из D:/codex/cmteh/node_modules (не npm ci заново).
Полный выпуск: python opencart/bin/verify-local --upstream UPSTREAM --runtime-dir NEW_RUNTIME --reference-dir REFERENCE --browser-tooling D:/codex/cmteh --port PORT. Для одного PHP-теста: python opencart/dev/runtime.py --runtime-dir RUNTIME --port PORT compose exec -T web php /workspace/tests/NAME.php. Runtime вне checkout; локальные письма перехватываются.
Секретные файлы runtime читать только в процессы, не выводить и не коммитить. Не останавливать/удалять чужие контейнеры, БД и сети.

## Фактически построено — 01
CatalogAdmin trait реализует editRows(string,int=1,string="",string=""):array; editorChoices(string):array; saveFromAdmin(string,array,bool=false):string; deleteRecord(string,string):void. editRecord возвращает native_id/parent_slug/category_slugs/main_image_path/specification_items. Choices включают id/slug/title/status/native_id/path, status=published|draft|archived. Публичные home selection arrays сохраняют порядок и исключают неопубликованное; действующие save/validate импорта совместимы.

## Фактически построено — 03
Events: admin/view/common/column_left/before→cmteh_admin.menu; admin/language/*/after→cmteh_admin.language. Русские оригинальные словари внутри расширения не меняют язык данных и ACL; общие ключи накладываются после регистрации событий, так как initial default language загружен раньше. collect_browser создаёт собственную синтетическую группу/пользователя и передаёт CMTEH_ADMIN_USERNAME/CMTEH_ADMIN_PASSWORD_FILE в admin-cms.mjs, без изменения первоначальной группы. admin_navigation подтверждён независимым запуском оркестратора.

## Фактически построено — 02
Named scalar/collection POST; internal_target/external_url; confirm_slug_change; action=delete+confirm_delete. sections_revision сохранён. Произвольный details отклоняется, metadata и ID приватных вложений берутся с сервера; обычные подписи/удаление доступны, новое private attachment — только прежний одобренный import. Новое public фото — main filemanager/native gallery. Черновик предпросматривается только в авторизованном sandbox. Ошибки422 сохраняют введённые title/image path. Browser admin-cms —6групп с reload/реальной витриной и очисткой только собственных тестовых записей.
