# 01 — Расценка сохраняется, передаётся почте и видна в админке

**Требования:** R04, R05, R06, R10i, R11i, R12i, G01, G02, G04, G05
**Зависит от:** —
**Зона:** `opencart/extension/cmteh/system/Quote.php` · `opencart/extension/cmteh/system/QuoteInput.php` · `opencart/extension/cmteh/system/QuoteOutbox.php` · `opencart/extension/cmteh/system/QuoteMail.php` · `opencart/extension/cmteh/system/Storefront.php` · `opencart/extension/cmteh/admin/controller/module/` · `opencart/extension/cmteh/admin/model/module/cmteh_leads.php` · `opencart/extension/cmteh/admin/view/template/module/` · `opencart/bin/send-quotes.php` · `opencart/bin/verify-local` · `opencart/tests/quote-integration.php` · `opencart/tests/quote-*` · `opencart/dev/release_checks.py` · `opencart/admin-guide.md` · `opencart/README.md`
**Волна:** 1
**Ревью:** нет
**Модель:** сильная

## Что должно заработать
Новый публичныйquoteAPI сохраняет независимую потребность/контакты/исходныйфайл безкорзины; свойworker передаётполноеписьмолокальномуMTA, nativeadmin видитрасценки/оригиналы. СтарыеLead/RFQ/quick/SMTPjobsнеизменны. Фундамент/файлы будут покрыты единственным wholebranchreview послеинтеграции, не дублироватьpointreview по ownerpolicy.

## Из брифа, дословно
> «этот файл должен просто отправляться ко мне на почту, или список должен отправляться на почту. Это будет отдельная расценка»
> «А заказы будут попадать в админку? Для статистики и вообще на будущее для менеджеров»

## Разделы спецификации
Истории4–6,10–14,16–19; implementation иinterfaces1–4,6,8. Точныйinput/API shapeизinterfaces обязателен; дофронтенда вернутьrequest/response/CLI/admincontracts готовымипримерами.

## Критерии приёмки
- НовыйquoteAPI RED наисходномсервере, затем realPDO text/file accept; originalbytehash/MIMEfilename/contactmultiline сохранены; invalidinput/format/size/injection rejects безостаточныхblob/job.
- Идемпотентность/race/conflict, rollbackfailure иquota/rate atomics; старыйLeadдаётпрежниерезультаты. File5MiB/json8MiB/text1MiB; maxpacket32MiBhost. Additiveownquote tables; нельзяreuseoldoutbox.
- Реальнаяoutboxfailure→retry→accepted используетто же сохранённоеMIME; stableMessageID; quote не теряется наmailfail. Capturetest реально парситMIME/оригинальныеbytes, неunitstringmirror.
- Nativequote list/detail/download/retry/deletefile через существующийcmteh_leads ACL. Anonymous/rolewithoutaccessнеполучаетbytes, modify необходимдействиям. No-store/attachment/nosniff иescapingPII.
- Старыеcart/quick/nativelead/migration/rolesбезрегрессий. Неотправлятьреальнуюпочту/непубликоватькод; localcaptureтолько.
- Реальныйquote integration включён вstrictverifierкакдополнительныйcheck, existingchecksнеослаблены. Обновитьдостовернуюдокументацию/CLIprivateconfig/cron/rollback безсекретов.

## Среда
OwnruntimeD:/codex/temp/cmteh-list-quote-dev-20261110 port3176 projectcmteh-oc-2cab896878ca готов; /workspace смонтировансэтогоworktree. Refreshновыйкодчерезruntime.py start...--refresh илиадресно вinstalledextension, безчужихконтейнеров. Quoteинтеграциянаownprefix; тестыownerdataнеиспользуют. Workerнеизменяет .autopilot/ инеcommit/push/deploy. Еслиновыйслужебныйфайл/schema/bootstrapвнезоныобъективнонужен, сообщитьleadточныйпуть/почему преждеизменения. Дополнительныезависимостизапрещены.
