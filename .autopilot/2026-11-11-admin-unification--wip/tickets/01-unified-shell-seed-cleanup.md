# 01 — Единая админка и безопасная очистка установочного каталога

**Требования:** R01, R02, R03, R04, R05, R06, R07, R08, R15
**Зависит от:** —
**Зона:** `opencart/extension/cmteh/admin/controller/event/cmteh_admin.php` · `opencart/extension/cmteh/admin/controller/module/cmteh.php` · `opencart/extension/cmteh/admin/view/` · `opencart/extension/cmteh/admin/language/` · `opencart/extension/cmteh/system/` · `opencart/bin/` · `opencart/tests/` · `opencart/dev/`
**Волна:** 1
**Ревью:** нет
**Модель:** сильная

## Что должно заработать

Один основной каталог и рабочий стол СМ ТЕХНО после входа, полезные системные функции по прежним правам, контекстный расширенный редактор. Инструмент безопасного удаления строго доказанных установочных записей с dry-run, отказом на изменённые данные/ссылки и проверяемым узким восстановлением. На хостинге инструмент пока НЕ запускать.

## Из брифа, дословно

> «старые тестовые записи opencart»
> «товары opencart категории opencart, бренды opencart»
> «Убери все что не относится к моему сайту, все что там может быть полезного, надо оставить но тогда перенастроить для нашего сайта.»
> «например пользователей и разных настроек доступа»

## Разделы спецификации

§1, §2, системные функции §5; interfaces.md полностью, notes.md. Business shell extension point для T02: заранее оставить безопасный reusable menu/dashboard hook; T02 последовательно дополнит.

## Критерии приёмки

- [ ] Штатная главная/логотип/мобильное меню показывают CMTEH без дублирующих каталогов/John Doe, сохраняют native assets/security/session и полезные разрешённые tools/users/groups. Native-only и custom-only roles не теряют доступ и не получают новый.
- [ ] Товары/категории/бренды сайта открываются из одного основного меню, native расширенная форма реальной записи доступна по правам; URL/ID/неуправляемые поля и unmapped unknown data сохраняются.
- [ ] Положительно установленные seed products/categories/manufacturers и безопасные иные seed entities перечислены в защищённом guard manifest; никакого классификатора unmapped=demo. Official seed exact hash/owned rowsets/references verified. Отчёт даёт причину сохранения зависимых/неопределённых.
- [ ] Реальная DB доказывает атомарную очистку только manifest, отказ на изменение/ссылку/неожиданнуюсхему, повторный запуск, узкое восстановление и отсутствие потери mapped/owner/new submissions/media/settings/ACL. Host применяет ops после отдельного нового GO.
- [ ] Idempotent installed upgrade/new hook removal и существующие catalog/native security regressions проходят. Точные affected команды/результаты и краткий contract возврата; новых рассылок/бизнесdatawrites нет.

## Примечания

Без отдельного point-review: owner policy один полезный whole-review после обоих тасков до публикации/cleanup. Root разрешает только локальный isolated cleanup replay; никакой host mutation в ticket.
