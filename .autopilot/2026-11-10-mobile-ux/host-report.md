# Mobile UX: test.cmteh.ru host acceptance

Final state at 2026-11-10 20:20:39 MSK: main 8ff70dd85e11e904e37f74b8ad74831f12b8d4fd / accepted product f5a61e94120c9f580904e1bd2b305ac48594c0b1 deployed. 43 grouped host scenarios PASS; 0 open failures. PR10 https://github.com/alexdubaev/cmteh/pull/10 and narrow F2 PR11 https://github.com/alexdubaev/cmteh/pull/11 merged before their deployments. Initial r2 details below are preserved as release history; final r3 supersedes r2.

Only test.cmteh.ru changed. Production/engine/StoreLand/DNS untouched. Prepared 20:02:15 MSK; activated 20:05:00 MSK. Exact strict-gate r2 ZIP SHA256 7c6e133b1fedcbfd5901bd9f8a25f965ac4f6f44e50aa468d298ad7536d86aaf, nested extension 0590a84d687260f5c31baa8a2f8ad7b8fac425279fa482f4c8de5d8b426d63ff. Server CLI PHP8.2.35 lint34 PHP passed. Actual web PHP version not separately established. Server MySQL5.7.44-48.

## Operational evidence

- Private release `/var/www/u2797422/data/.cmteh-opencart-test/release/mobile-ux-20261110-200215`; root .htaccess/config/admin/config hashes unchanged by activation; previous extension retained. Files extracted with current mtimes to avoid stale OPcache; native upgrade cleared owned templates. No DB/schema/auth changes.
- Fresh DB531931 bytes SHA dcb8fc26ae398fd96d018bf0bef0b137cf680997a247869129b0f94e08c6cb8b; full site13528434 bytes SHA df9276c011489e69a81a05ffe0ed1cd8ba20c8c7b11a213ae512e9cb88634dcc; private1679431 bytes SHA76f99d887554accce955caf7c19030d9472254eb5f73050ce0032909cd1955ff. Downloaded DB/site/snapshot/manifest to restricted Windows ACL C:/Users/Alexandr/.codex/private/cmteh-mobile-ux-20261110-200215, hashes matched. Private storage archive with secrets remains closed on host.
- Baseline first comparison against entire cached ZIP correctly failed29files: historical cache release had patched five files only over original admin ZIP. Verified all89code files against original admin ZIP plus exactly recorded5cache hashes. No foreign changes found. Early backup probe looked for nonexistent upload/ prefix and counted0; not treated as proof, replaced by guarded count>=80 comparison.
- Fresh host dump restored to separate scratch DB `cmteh_mobile_ux_copy_20261110` in ready local MySQL8.4.13. New code quick submit200 persisted; old actual live Lead reader read its no-items pending-outbox record and all original product identities. Scratch only; host DB never restored over new data. This run did not claim a second MySQL5.7 recovery rehearsal.
- Native admin update: login, idempotent upgrade, approved phone native save, reload persisted:4PASS. Phone is +7 911 921-22-14 / tel:+79119212214. Only semantic site-field change was phone. Existing native editor also stores absent `confirm_zero_price` as false on every save; this automatic metadata addition is recorded rather than hidden. All remaining site values, all non-site content, ACL groups, product ID/source/SKU/MPN and event semantics unchanged. No explicit home_blocks existed, so fallback order applies without changing home JSON.

## Browser scenarios

- Mobile Chromium + WebKit390x664,12PASS: whole hero/compact categories before products/coherent asset v20261110-4/nooverflow; exact call URI and2actions; separate3visiblefield form/name+phone validation; actual empty-basket quick submit from/request; basket quick lost-response→same public ID/replayedtrue/empty payload items; basket survives/reload; back-to-top; contacts submit stays unobstructed after Escape; legacy empty RFQ rejects400. No phone call performed.
- Native admin confirms all4actual quick leads without item rows; all had pending outbox. Four own records closed-backed-up then removed with exact public_id/request_key/name/null-email guards. Counts return16products/8cats/11brands/27content/0leads/0private media, native35/46/17. Owner records preserved. SMTP config absent; delivery NOT RUN.
- Existing public critical journeys18PASS: desktopChromium/mobileChromium/mobileWebKit MIME/module init, menus/catalog, search suggestions/product add, basket qty/reload/remove, catalogue filters/sorting/page2, exact pasted lists, TXT/CSV/XLSX including leading zeros.
- Desktop1440x900 narrow visual acceptance PASS: whole hero bottom732 within900; nooverflow; categories before products. Initial harness incorrectly imposed mobile product-y<1100 on desktop1292 and was corrected only for desktop; it was not an app defect.
- Private config/storage/.htpasswd guards403/404 and unauthenticated native admin login PASS. REG.RU challenge followed by actual browser where necessary.
- Preservation/strict cleanup bundle PASS. Together4native+12mobileUX+18existing+2desktop/admin+1guards+1preservation=38 grouped scenarios. This is not a test-count comparison with103local strict-gate assertions.

## Confirmed F2 defect, repaired and closed

Both Chromium/WebKit: open short form, click empty submit, wait for mobile actionbar hidden by field focus, Escape. Dialog closes but opener does not regain focus because close handler focuses a temporarily visibility:hidden button. After two animation frames bar is visible; focus falls to body. Repro has no screenshot/viewport mutation: `focus-repro.cjs`, evidence `focus-repro.json`. It is a functional accessibility issue, not a blocked cart or lost lead. No production rollback was needed; root delegated a narrow repair. Final source f5a61e9 makes the opener visible before restoring focus; exact asset graph was advanced to20261110-5.

Early host harness selector `.home-hero img` corrected to actual `.hero img`; exact label with required asterisk corrected to nonexact accessible label. Their diagnostic FAIL evidence retained, no synthetic lead was sent in those early runs. No unavailable test infrastructure presented as PASS.

## Screenshots and evidence

- D:/codex/temp/cmteh-mobile-ux-20261110/mobile-chromium-home-host.png (390x664)
- D:/codex/temp/cmteh-mobile-ux-20261110/mobile-webkit-home-host.png (390x664)
- D:/codex/temp/cmteh-mobile-ux-20261110/mobile-chromium-quick-host.png
- D:/codex/temp/cmteh-mobile-ux-20261110/mobile-webkit-quick-host.png
- D:/codex/temp/cmteh-mobile-ux-20261110/quick-persisted-native-admin-host.png
- native-update.json, host-ux-results.json, public-results-closure.json, host-admin-proof.json, host-preservation-proof.json, host-private-guards.json, rollback-proof.json, deployment.json.

Hero effective339x254 at y328..582; bar y596. First product document y915 vs original1551: about41% less vertical distance. Real browser engines tested, physical owner's iPhone and actual SMTP delivery not tested.

Rollback: atomic return previous-extension and native owned-template clear while keeping current DB and approved phone. Never restore old DB over fresh leads.


## Final F2 deployment and closure

- Exact final r3 install ZIP: D:/codex/temp/cmteh-mobile-ux-final-runtime-20261110-r3/artifacts/cmteh-install.zip; SHA256 e2f0183e200668409cb7ab1d059cc9b0a3c12c605f620db1f5d62e2930275593; nested extension SHA ea4af2f62383707b199fdd852871c6fd4220e84d87a125f741812911344e4d7f. Parent strict r3 gate104PASS/0FAIL, exit0, source frozen before checks.
- Fresh second pre-switch private backup made after phone approval and synthetic cleanup, release subdirectory focus-fix-201804. DB533974 bytes SHA fcdc1085e69c9af4fb2419791572980e1f272d49cb814f8023b53b8534d62647; site13529490 bytes SHA9d67917e9c8f49784f9249ec00d17068b228922e64b7b1e44da863f1951f7e4d; private1712795 bytes SHA8ec79128250d19e9fbf398208fc32baac1d50cc45cbaecb4977f08c0b91c5ea6. Restricted local copies/hash matches retained in C:/Users/Alexandr/.codex/private/cmteh-mobile-ux-20261110-200215/focus-fix-201804.
- Verified all90installed r2 hashes before replacing, candidate safe paths/nested hash,34PHP lint, atomic switch with previous-extension retained, root configs unchanged and all90installed final file hashes matched final ZIP. Activated2026-11-10T17:18:19.955082Z (20:18:19 MSK). Native upgrade cleared owned templates; phone only read/verified, no second phone save.
- Narrow host closure5PASS/0FAIL at2026-11-10T17:20:39.821Z: native template clear/phone preserved; Chromium+WebKit x normal+reduced motion actual empty-submit validation, Escape, close button and backdrop each restore focus to visible opener. Module graph v20261110-5; zero lead API submissions. All initial38host scenarios remain valid for unaffected behavior. Final grouped count43PASS,0open defects. No broad host suite rerun.
- First F2 closure attempt reached successful native clear/phone, Escape and close-button checks, then failed only because a newly inserted backdrop-check Russian string in the external Windows harness was encodedcp1251. Converted that selector to ASCII and script to UTF8, reran the narrow closure. This is recorded as harness failure, not an application failure or skipped check. Evidence host-focus-closure-fail.json retained alongside successful host-focus-closure.json.
- Post-final-code read-only preservation proof passed:16mapped products/8cats/11brands/27content/0leads, same IDs/SKU/MPN/ACL/event semantics/other content. No SMTP configured or enabled. Four own synthetic quick records remain only in closed backup; none left in liveDB. Current approved phone retained.
- Clean final viewport PNGs, actually viewed: D:/codex/temp/cmteh-mobile-ux-20261110/final-chromium-home-host.png and final-chromium-quick-host.png; final-webkit-home-host.png and final-webkit-quick-host.png. All390x664, actual hosted site. Physical iPhone acceptance still not claimed.
- Host-release elapsed20:02:15->20:20:39 =18m24, including local scratch rehearsal and waiting for F2 implementation/strictgate/merge. Final server activation->narrow closure2m20. Exclusive server execution time was not measured separately. Subsequent report/cleanup tail begins after20:20:39. Local runtimes are owned/cleaned by parent; this agent changed no Git worktrees or branches.

For latest rollback use focus-fix-201804/previous-extension, preserving current DB, phone and fresh owner data. The original pre-UX extension also remains under the initial release for a broader rollback if explicitly warranted. Restore code only; do not restore oldDB over new requests.
