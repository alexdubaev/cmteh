# F1 — Три подтверждённых границы перед выпуском

**Требования:** R02, R09, R11i, R12i, G05, D01
**Зависит от:** —
**Зона:** `opencart/extension/cmteh/system/LeadInput.php` · `opencart/extension/cmteh/system/Lead.php` · `opencart/tests/lead-integration.php` · `opencart/extension/cmteh/catalog/view/assets/parts-list.mjs` · `opencart/extension/cmteh/catalog/view/assets/request.mjs` · `opencart/tests/browser/storefront.mjs`
**Волна:** 3
**Ревью:** да — ремонт ветки
**Модель:** сильная

## Что должно заработать
Только подтверждённые ревью границы, без нового UI/рефакторинга. Два fix-now и один bounded report исправляются одним проходом. Lead сообщил владельцу о добавлении третьей проверки, не расширять scope дальше.

## Findings — дословно
- **Spec · `opencart/extension/cmteh/system/LeadInput.php:143`, `Lead.php:83`** — каталог допускает canonical `source_id` до 160 символов, новый RFQ ограничивает его 128. Опубликованный товар с ID длиной 129–160 добавляется в корзину, но заявка отклоняется. PHP-воспроизведение: 128 принят, 129/160 отклонены. Исправить validator и snapshot-колонку до 160, включая безопасное расширение уже созданной колонки.
- **Craft · `opencart/extension/cmteh/catalog/view/assets/parts-list.mjs:22`–`:23`, `:142`–`:144`** — при отказе sessionStorage pending key теряется даже внутри открытой страницы. После принятого сервером запроса с потерянным ответом повтор получает новый ключ и создаёт вторую расценку/письмо. Browser-воспроизведение: запрещённый `Storage.setItem`, первый ответ оборван, повтор неизменённой формы → два разных ключа. Нужен авторитетный fallback в памяти вкладки.
- **Craft · `opencart/extension/cmteh/catalog/view/assets/request.mjs:146`** — cache identity цен учитывает только артикул/бренд, хотя строки теперь различаются `productId`. Если storage-синхронизация заменяет offer A на offer B с теми же SKU/брендом, цена остаётся «Загружаем цену…» до reload. Browser-воспроизведение подтвердило отсутствие нового search-запроса. Включить `productId` в identity; отправка RFQ сохраняет правильный ID, поэтому сдачу отдельно не блокирует.

## Приёмка
- MeaningfulRED→GREEN legalcanonical160char id throughrealPDOsubmission/nativehistory; 161invalid. Mirrorexistingcanonicalvalidator(char semantics), безопасноеwiden128→160 безпотерисуществующихrows/fingerprint; oldreplayunchanged.
- BrowserdeniedsessionStoragewrites/lostack→sameUUID/однаquote/одинjob. Fallbackauthoritativeвпамяти, втомчислеclear/tombstoneнеподнимаетстарыйpendingkeyизreadonlystorage; обычныйrefresh/storageповеденческисохраняется.
- Browserstorage-syncreplacespidAsameSKUbrandwithpidB→priceзагружается/разрешается, не вечныйspinner. NewRFQpayloadpidB; legacykeysunchanged.
- ПослеfixrunaffectedLead/browserfile; rootfullstrictпослеfreeze. Новыйwhole-reviewнепроводить; narrowclosureтолькоэти3условия.

## Ограничения
КодT02 8e7dcd3 ещё не опубликован: graphversion20261110-6 остаётся согласованнойверсиейбудущегорелиза, не трогатьвсеassetsдляненужногобампа. НеизменятьQuote/backend/mail/admin/Catalog/staticCMS. Толькосвоязона. Безcommit/deploy/.autopilot; rootвладеетфиксацией. Новыхзависимостейнет.
