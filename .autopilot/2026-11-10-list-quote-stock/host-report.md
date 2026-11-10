# Lists / quote / chosen-offer history: actual test host acceptance

Operational result confirmed 2026-11-10 22:58:20 MSK. Only test.cmteh.ru changed. Production/cmteh.ru/engine/StoreLand/DNS untouched. No Git/product changes were made by this host executor.

PR13/main1efdaf379af95427b54966f164795f2458f17dac introduced the flow; narrow F3 PR14/main dccf970c519dd3958cdfd773c01ad2fc73d8ad30 repaired host MIME portability. Root verified product7769899, strict local185PASS/0FAIL/exit0 before final publication. Exact final r3 outer ZIP SHA2567b361d15f5824dfa15081fd6ac0d8abf7d587a63af380f41e83b99eadf3c5593; nested ead159b2990e2d81b3860fc052956132e888eba1ad8def13baec26e86dfcda56. All97 live extension files match, zero extras. Activation22:47:58MSK; new native upgrade completed22:48:08. Root .htaccess/config.php/admin/config.php hashes unchanged.

## Manager navigation and persisted history

Native OpenCart admin → **СМ ТЕХНО → Заявки** opens existing basket requests. The **Расценка списков** link opens the separate quote list; its **Корзинные заявки** link returns to basket requests. Quotes show number/date/contact/source/mail status; detail keeps original multiline text or original filename/type/size/SHA256 and authenticated **Скачать оригинал**. Email acceptance does not remove the quote/history. Sent-attachment removal and failed retry remain explicit staff actions under existing access/modify permissions. No new role, account or permission grant was installed.

Actual hosted critical basket journey passed: pasted RE568158;4 → check → explicitly choose John Deere offer → canonical product mock-11 in cart → actual RFQ HTTP200 → native history shows RE568158 / quantity4 / John Deere / Фильтр масляный / mock-11 immutable snapshot. Confirmation removed only the acknowledged cart quantity. Its old lead outbox stayed pending with0attempts throughout quote-worker operation. Synthetic RFQ33a9b921-e90d-4016-8bfd-06329280e23d was closed-backed-up and strictly cleaned afterward.

## Host checks

- Actual CLI PHP8.2.35, MySQL5.7.44-48, max_allowed_packet32MiB. Final exact-source quote integration54PASS/0FAIL/exit0 on random qt_ tables, including real original5MiB PDO persistence, idempotency/concurrency/transaction faults/MIME/retry; no MTA invoked. Own tables cleaned.
- Full native database copied SELECT-only into random qh_ tables on actual MySQL5.7. Six recovery phases passed: old RFQ seed; additive schema3/idempotent migrate/product_idvarchar160; legacy row/fingerprint/replay retained; new canonical snapshot plus original quote BLOB; actual1000-row exact catalog batch; old-runtime read/new old-format RFQ submit/legacy worker operate with capture only. Old migrator's exact newer-version denial is EXPECTED SAFE, marker3 retained. New snapshot and all5quote-table/original BLOB hashes unchanged; old worker did not consume quote jobs. Own copy dropped, owner data preserved.
- Pre-activation single6990742byte JSON body containing5MiB original reached old application and returned expected405JSON, establishing gateway body passage. After activation ONE actual browser file selection/FileReader/base64/HTTP/save journey accepted5MiB TXT HTTP200; authenticated native download returned exactly5242880 original bytes SHA dbbe5517996826bd5861ac22b745d21d11219055d89243ca1aea0ad31f552b12. Heavy quote stayed pending while worker disabled and was closed-backed-up/cleaned before enabling cron; it was never emailed.
- Chromium and WebKit390x664:1000rows retained,50results per page, page2 starts51,1001rows gives clear error, no automatic cart add, explicit canonical offer add/quantity, legacy cart preserved, no horizontal overflow. Native UI small text and original Windows-1251 CSV submissions succeeded without cart mutation. Lost response then repeat preserved same public ID/replayed=true and only one text job.
- Native quote detail preserved original text/file/contact metadata and original file byte hashes. Downloads use attachment/no-store/nosniff; wrong-token and unauthenticated native requests returned login/access boundary without original bytes. Actual OpenCart wrong-token login can return HTTP200; status alone was not treated as proof of access. Anonymous context first passed REG.RU browser challenge, then native login boundary was verified. Existing ACL definitions are byte-equivalent to baseline; host roles were not modified to create an artificial permission test.

## Automatic business email

Only the new quote queue is enabled. Versioned CLI is outside public docroot, cron uses actual absolute **/usr/bin/php**, static From/To info@cmteh.ru and exact Cyrillic display name **СМ ТЕХНО**. Actual Database::privateDir is `/var/www/u2797422/data/.cmteh-opencart-test/sealed`; its quote-mail.json is0600. Original foreign709byte crontab content/two jobs preserved byte for byte; own block is separate. Old lead SMTP configuration remains absent and old SMTP worker was never enabled.

Actual cron cycle22:56:01MSK processed BOTH synthetic small quotes with attempts1 and status sent; own log emitted `{busy:false,sent:2,retry:0,failed:0}`. Worker/send callback was NEVER invoked manually. Decoded subjects:

- Расценка списка 85bcf5c1-7f8b-45d7-beaa-f9814cd39cf8 — original multiline text.
- Расценка списка 60fddb46-94f3-47fb-9db4-7b63ad54c608 — original **Потребность 0001.csv**,29bytes Windows-1251, SHA256 dbd6eb42599d4cacf2ca90c6129d8a29f6c82baad8b9e4e9ea448888c2996d9d.

This proves application-to-hosting-MTA acceptance on an automatic cron cycle. It does **not** prove final Inbox arrival or reading. Root asked owner to confirm both subjects and the original CSV; that final feature Inbox confirmation is pending at this report. The earlier separate control probe was owner-confirmed Inbox, and is not substituted for these two feature messages. No further sends were made.

First two actual cron cycles stopped before claiming any job because this executor initially placed its own settings one directory above resolved privateDir. Both quote jobs remained attempts0; no mail was submitted. Relocated only the own0600static settings to resolved sealed directory and verified settings without calling sender; next actual cycle succeeded once per quote. Initial log/failure proof retained. This was an operational placement correction, not a product/auth/transport/DNS change.

## Backups, preservation and cleanup

Closed first DB/site/private/crontab backup stored under release/list-quote-20261110-222821 and downloaded to restricted Windows ACL owner+SYSTEM only at `C:/Users/Alexandr/.codex/private/cmteh-list-quote-20261110-222821`, all hashes matched. Site13529607bytes SHA19486bfec2b297677425ad92536bacf7ac15825f17bf35d6b32b15c390b2df01; private66302989bytes SHA378619dc6fa8c0014c83b419a00799f5546ca2b467a7b0913dfa21624cc4352a.

Before final switch, fresh full hex-BLOB SQL535658bytes SHA c93c1968165c0ce22699fc3e4147d521c628cfa6c28d28811bb2652a977367b6 was created/copied/hash-verified. Existing closed site/private archives were rehashed; file comparison found0site differences and exactly2currency/language cache replacements. Their528byte private incremental archive plus deletion inventory were closed/copied/hash-verified, avoiding redundant66MiB transfer. Final release is `/var/www/u2797422/data/.cmteh-opencart-test/release/list-quote-20261110-224527-r3`; local final backup/receipts under the restricted folder's r3-corrected subdirectory.

Only1heavy+2small quote records and1RFQ own synthetic record were removed AFTER closed original-byte/metadata/outbox backups were copied into that restricted directory and SHA-verified. Guards checked exact IDs/request keys/tagged names/source/contact/type/file size/hash/text/queue state and RFQ canonical snapshot. Quota subtraction was transactional; rate counters and concurrent owner records were preserved. This synthetic cleanup does not alter normal customer history retention.

Final preservation:16mapped products/8categories/11brands/27content/0leads/0media/0quotes/0quote originals/0quote jobs/quota0; native35products/46categories/17manufacturers. Product identities/content/approved phone/ACL/event semantics/original leads unchanged. Lead schema3 and nullable canonical identity width160 are present. No private media was published. Synthetic evidence exists only in closed backups/screenshots and the two already-submitted emails.

Rollback: pause only own cron block, acquire own flock, atomically restore this final release's previous-extension with fresh mtimes, clear only compiled CMTEH templates via standalone cache-only helper. Keep current DB/new quotes/original BLOBs and approved phone. **Never invoke old native upgrade against schema3, never lower schema marker, never restore old DB over fresh requests.** Previous accepted90-file extension is retained. Actual copied-DB recovery established old normal runtime compatibility and expected old migrator refusal.

## Defects and evidence

Initial r2 host quote integration retained22PASS then real FAIL: host libmagic returns application/octet-stream for valid large text. F3 minimal repair was implemented/reviewed/verified/merged by root, never patched live. Private parse-only host RED→GREEN preserved all5MiB original hashes and rejected PDF renamed TXT/CSV; final exact r3 full host integration54PASS supersedes that failure. Initial Python3.6 subprocess alias mismatch and native harness HTTP200 login/challenge assumptions were corrected outside Git; diagnostic histories remain, no unavailable check was labelled PASS.

Primary evidence local `D:/codex/temp/cmteh-list-quote-stock-20261110/`: quote-host-r3-proof.json; quote-r3-integration.stdout; copy-recovery-proof.json; copy-new-schema-quote-snapshot.stdout; copy-old-runtime-and-safe-deny.stdout; pre-go-body-probe.json; staged-r3.json; activation.json; native-upgrade-proof.json; host-heavy-proof.json; native-heavy-proof.json; host-feature-proof.json; host-rfq-proof.json; native-feature-proof.json; actual-cron-proof.json; cleanup-heavy-proof.json; cleanup-feature-proof.json; cleanup-rfq-proof.json; final-host-proof.json.

Actual hosted screenshots, visually inspected:

- chromium-list-results-top-host.png and webkit-list-results-top-host.png —390x664 results, leading-zero SKU and explicit offer buttons.
- chromium-quote-form-top-host.png —390x664 separate quote form; webkit-quote-form-top-host.png also captured.
- chromium-quote-confirmation-host.png — actual text quote acknowledgement.
- native-feature-file-receipt-host.png / native-feature-text-receipt-host.png — retained source plus actual MTA acceptance/attempt1.
- native-rfq-chosen-snapshot-host.png — actual canonical product/SKU/brand/title/quantity in existing manager history.

Physical iPhone testing is not claimed. Release elapsed first authorized staging22:28:21→working MTA cycle22:56:08 =27m47 including F3 repair/gates/merge wait and operational recovery. Final r3 activation22:47:58→MTA acceptance22:56:08 =8m10; preservation/cleanup acceptance completed22:58:20, then report/visual-capture tail. Exclusive server CPU/execution time was not separately measured. Root owns complete original-request elapsed time and final docs/Git/worktree/runtime cleanup.
