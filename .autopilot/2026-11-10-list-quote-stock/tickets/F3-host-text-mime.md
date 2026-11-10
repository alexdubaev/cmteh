# F3 — Большой TXT/CSV на реальном PHP8.2

**Требования:** R04, R10i, R12i, G02, D03
**Зависит от:** —
**Зона:** `opencart/extension/cmteh/system/QuoteInput.php` · `opencart/tests/quote-integration.php`
**Волна:** 5
**Ревью:** нет
**Модель:** сильная

## Фактический отказ
HostPHP8.2.35/libmagic классифицирует какapplication/octet-stream иоднострочныйASCII5MiB, иреальныйmultiline5MiBTXT/CSV '001A;2\r\n'. Quoteintegrationпосле22PASSпадает 'Five MiB original survives real PDO BLOB'. Это неSQL/packet и неWAF:7MiBJSONдоходитдоoldapp405; packet32MiB, PCRE/base64валидны. Siteещёнепереключён. Примерошибкивалидноготекста, неоснованиеослабитьbinary/fileguards илискрытьтест.

## Приёмка
ДляCSV/TXT ambiguousapplication/octet-stream допускается ТОЛЬКОпослеполнойпроверкидопустимогоUTF8/Windows1251/NUL/controlbytes/size. Известныйнетекстовыйтип(PNG/PDF/ZIP/EXEи т.п.) срасширениемtxt/csvпо-прежнемуотклоняется. MIMEсохранённого текстаопределяетсясерверомкактекст,bytesнеперекодируются. Другиерасширенияничегонеменяют.

ActualhostRED→GREENнуженчерезоперационногоагентанаизолированномprefix, так какlocalPHP8.3не воспроизводитfinfofalsepositive. ДополнитьrealPDOграницусmultiline5MiBCSV/TXT+originalhash/MIME иnegativebinarymaskedtest. Существующие50checksнеослаблять. Rootactualhostprefixcheckподхватитstagedновыйexactsourceпослеmerge; доэтогопарсерныйнаписанныйbytefixtureможнопередатьоператорудляisolatedдиагностикибезlivecode/schema/mail.

Только2файла, графJSv6неизменён, QuoteMail/queues/admin/legacyLead/guardsнеизменять. Неcommit/deploy/.autopilot. Rootполныйstrictfinalпакетпослеfreeze+PRmerge; повторG4/whole-reviewненужен: целевоевоспроизведение наPHP8.2 иnarrowприватныеchecks. Еслинеяснопочемуfinfooctetstream—причинаисследуется, неplaintextизрасширениябезпроверки.
