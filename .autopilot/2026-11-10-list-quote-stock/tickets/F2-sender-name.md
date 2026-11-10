# F2 — Согласованное имя отправителя в локальном MIME

**Требования:** G04, R12i
**Зависит от:** —
**Зона:** `opencart/extension/cmteh/system/QuoteMail.php` · `opencart/dev/release_checks.py` · `opencart/README.md` · `opencart/admin-guide.md` · `opencart/packaging/README.md`
**Волна:** 4
**Ревью:** нет
**Модель:** обычная

## Условие
G4 фактически сохранил/распарсил capture.eml: From displayname«СМТЕХНО», brief/ответвладельцаожидает«СМ ТЕХНО». Адреса ужеправильны. Это малое подтверждённое расхождение, не новая возможность.

## Приёмка
DecodedFrom actualCLIcapture == «СМ ТЕХНО» и info@cmteh.ru. ИмяDEFAULTcaptureи документированныепримерыprivateconfig согласованы, hostingпродолжаетиспользоватьнастроенноеfrom_name. ONEmeaningfulindependentPythonemailparserassert пооригинальномуshippingCLIcapture, RED→GREEN; существующиеbytes/To/retryбезизменений. НикакогоизмененияUI/адресов/очереди/реальнойпочты. Не ослаблятьcapture-onlyloopbackguard. Graphv6неизменяется,JSнет.

## Проверка/границы
Существующийquote_acceptance(runtime,buildcandidate) наdev3176; sourceisHEAD3441135. SourceфайлQuoteMail/примерыиоднаassertionтолько. Workerнеcommit/.autopilot/deploy. RootвыполнитпослеfreezeновыйstrictпакетиG4узкуюпроверкуdefaultname; нецелуюновуюприёмку/whole-review. Ошибкаиначеотражаетсявотчёте, непритворятьсячтоcaptureподтвердилещёнесозданныйHOSTconfig.
