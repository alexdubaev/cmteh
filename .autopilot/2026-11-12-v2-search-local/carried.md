<!-- autopilot:progress -->
# СМ ТЕХНО — склады

Последняя source-acceptance: `.autopilot/2026-11-11-site-capabilities/` (при незавершённом rename — папка с --wip), продукт62eac91. Strict immutablearchive PASS/exit0, blindG4 active9/9. Фактическая публикация и состояние хостинга — D:/codex/_handoffs/cmteh-warehouses-result-2026-11-11.md; локальная приёмка не доказывает deploy.

## Договорились — не пересматривать

- Только склады в текущем выпуске: «Да нам нужны склады, остальное оставим на будущее». R01–R42 прежнего отчёта остаются будущими работами.
- Покупатель видит предложения одного товара по складам, свои цену/наличие/срок; ID товара стабилен, выбор склада и снимок условий сохраняются в корзине/заявке. Заявка не резервирует автоматически.
- Excel/CSV с предпросмотром, явным подтверждением и атомарным применением; пропущенные строки не обнуляются. Ручные резервы, снятие и полная отгрузка; подтверждённое парное перемещение без логистического транзита.
- Срок задаётся для склада с уточнением для предложения. Примерные города/цены не являются реальными данными: владелец создаёт склады и загружает фактические остатки.
- Actualapp opencart/OpenCart4.1.0.4; rootNext reference. Толькоtest.cmteh.ru, production/StoreLand/DNS/engine исключены. Старые IDs/source_id/slug/mappings и приватные оригиналы сохраняются.
- Прежние RFQ/quote, native ACL и UX сохранены. Сырой список не добавляется в корзину; проверка exactSKU/ведущихнулей/порядка/дублей. info@cmteh.ru, владелец подтвердил Inbox и исходный CSV; почтовую приёмку не переоткрывать.
- Только quotequeuecron; старыйLeadSMTP отключён. Leadmarker3, inventorymarker1, отдельная importpreviewсхема. Constructors/read не мигрируют. Новые12hooksдоinventoryreadiness; нацелевомхосте безwholeupgrade/перезаписи67старыхhooks/автогрантовACL.
- Старый бинарный rollback допускается Opsguard только при полном отсутствии inventorytables. После DDL только совместимый code-only recovery с текущей БД; oldDBrestore/loweringmarkers запрещены. Новый incident требует нового GO.
- Seed-cleanup.json сохраняет точные подписанные rawbytes cb85…; unchangedTEXTacceptedoverlays неприменимы к изменённым warehouseфайлам. Последний архив126files/outera8520763/nestedb78dbe14 получен из cleancloneLF без overlays.
- Quote/campaign/readmodels не равны коммерческим заказам/покупателям/конверсиям; сохранённый page_url декларируемый. Публичных кабинетов/оплаты нет. Карта офиса — только trustedYandexiframeURL; фактический адрес/код владелец пока не предоставил.

## Не доделано

- Будущие R01–R42: публичные аккаунты, коммерческие заказы/оплата/возвраты, CRMстадии/ответственные, consentрассылок, купоны/партнёры, полноценная аналитика и прочие направления — docs/admin-capabilities-2026-11-11.md.
- Реальный ассортимент/фото/склады/цены и реквизиты/карта заполняет владелец; 1Ссинхронизация не запрошена. Productionпереход/нагрузка/квотыREG.RU/индексация требуют отдельной задачи.
- Private actualhost nativeHTTPbootstrap не разработан: schema-onlycopy не содержит bootstraprows, fixture guards не ослаблять. Локальные точные E2E и actualhostCLI/recovery — разные доказательства; фактическое выполнение смотреть во внешнем handoff.

## Известные баги

- Сортировка по цене пока включает отсутствующие предложения; specavailable/contractvisible расходятся. Итоговое ревью оставило отдельную доработку.
- UI читает первые50складов; для последующих нужны пагинация и получение версии при закрытии резерва.
- CSVформула в текстовом сроке сохраняется как инертный текст; формулы не исполняются, но обещанный отказ охватывает не все ячейки.
- Явная initiallyblockedInventorySchema faultinjection NOTRUN; normal/repeat migration и ранняя регистрация12hooks проверены. Не выдавать этот отдельный faultscenario заPASS.
- Старый SQLreader безопасно отказывает на SQL-похожем quoteduserdata; не ослаблять guards ради restorePASS. Некоторые advancednative заголовки ещё английские.

## Правки без автопилота

—

## Изменено после сборки
- 311dca6 — определения V1/V2 в AGENTS.md.
- bea9474 — warehouse RFQ rollback fixture для non-strict MySQL.
- 4b955aa — inventory rollback fault fixture для non-strict MySQL.
