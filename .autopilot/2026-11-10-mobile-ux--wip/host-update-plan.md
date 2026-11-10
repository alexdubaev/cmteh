# Безопасное обновление test.cmteh.ru

Авторизация: текущий UX-запрос, подтверждённый целевой test.cmteh.ru из этой сессии и standing owner AGENTS §11 разрешают deploy после успешного merge при известной процедуре и откате. Production cmteh.ru/StoreLand/DNS/engine не входят. Номер +7 911 921-22-14 явно одобрен для test CMS.

## Перед обновлением

1. Сверить live extension с baseline из PR9, .htaccess MIME и текущий native site.phone/home_blocks. Не переписывать неожиданные чужие live-изменения.
2. В закрытой /var/www/u2797422/data/.cmteh-opencart-test/release/ создать новый0700 release; свежий dump выделенной MySQL5.7 через закрытый config и MYSQL_PWD environment/proc_open (не argv, не stdout). Для клиента8 использовать --column-statistics=0. Сохранить extension/media/config/private storage; manifest SHA256, имена без значений секретов. Скачанные чувствительные копии — только в restricted Windows ACL директорию.
3. На отдельной копии убедиться, что старый принятый Lead::find/read admin model читает новую quick заявку без items и что чтение/rollback не удаляют её. Схема не меняется, восстанавливать старую БД поверх текущей не нужно.

## Переключение

4. Использовать именно финальный локально проверенный ZIP; сверить extension SHA, safe paths, server PHP lint. Распаковать рядом, права0755/0644, сохранить прежнее extension целиком, атомарно переключить каталог. Сохранять .htaccess, native configs, media и private data.
5. Native module upgrade идемпотентно обновляет/чистит собственные compiled templates; не менять роли, аккаунт или Basic Auth.
6. Через штатный site editor поменять только phone на одобренный номер, сохранив актуальные остальные поля/sections. До изменения сохранить исходную site запись и её hash. Home blocks на текущем test отсутствуют: новый fallback порядок работает без записи поверх CMS. Если к deploy появились explicit blocks — сохранить их приоритет и согласовать фактический путь с владельцем, не стирать.

## Host-приёмка

7. Реальные Chromium/WebKit действия с новым asset version: первый экран и y первой карточки, меню/категории/поиск, header basket и импорт/qty/reload, quick dialog/validation/Escape/focus/back-to-top. Не выдавать чистый context за физический iPhone; учесть saved-module-cache и короткую высоту браузера.
8. Настоящая quick заявка с /request при пустой корзине и при заполненной: корректный page_url/empty items, корзина не очищена, запись и pending outbox есть в native admin. Потерянный ответ→повтор даёт тот же ID, старый пустой RFQ остаётся400. Все тестовые контакты синтетические, SMTP не включать.
9. Очистить только собственные synthetic records после закрытой копии и строгой проверки public_id/request_key/name/email; остальные данные/счётчики не трогать. Сравнить исходные ID/SKU/MPN/количества и новые данные пользователя, если появились параллельно. Скриншоты/отчёт с фактическими checks и ограничениями.

## Откат

Вернуть целиком previous-extension при подтверждённом дефекте кандидата; повторить безопасную очистку CMTEH compiled templates. Текущая БД и свежие заявки остаются на месте. Старые обработчики событий/схема уже поддерживают существующие records, новые event codes не добавляются. Телефон сохранять одобренным, не откатывать его молча. Если partial config restore понадобится — только конкретное изменённое поле с guard актуальной записи. Не восстанавливать dump поверх новых обращений. При host-ошибке сначала исследовать причину; old-code rollback — резервный путь, а не замена приёмки.
