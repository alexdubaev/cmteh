# F1 — bounded whole-review repairs

Cause: native `Request::clean` HTML-encodes incoming strings, but manual inventory commands stored those encoded values as domain text. The inventory POST boundary now decodes exactly one `ENT_COMPAT` layer for top-level strings before commands, error drafts and cutover reason are consumed. The XLSX JSON bridge uses that same decoded value instead of decoding twice. Global Request/sanitizers, ACL, output escaping and domain services are unchanged. Literal entered `&amp;` remains literal `&amp;`.

External proof cause: the five-file plan mixed four ordinary suites and the dedicated recovery fixture. Setup now installs all five exact-hash files; ordinary execution validates the exact filename set and runs only the four ordinary suites. The dedicated recovery runner is hash-checked before its unchanged seed/advance/verify/cleanup scenario. All GO actions remain closed.

FILES:
- `opencart/extension/cmteh/admin/controller/module/cmteh_catalog.php`
- `opencart/tests/browser/inventory-import-happy.mjs`
- external `D:/codex/temp/cmteh-warehouse-ops-20261111/proof-ops.py`, `test-ops.py`, `ops-prepared-index.json`

REDPROOF: new actual native-HTTP browser assertion failed before the controller fix: entered `Synthetic A & B <warehouse> &amp;` reloaded as `Synthetic A &amp; B &lt;warehouse&gt; &amp;amp;`. Exact nonce fixture cleanup completed. Review already established the five-vs-four Ops defect; no target execution was attempted to reproduce it.

TESTS:
- `CMTEH_BROWSER_TOOLING=D:/codex/cmteh node opencart/tests/browser/inventory-import-happy.mjs` on ready local3190: PASS four printed scenarios. Manual name/city/default term/private address/note survive two real native form saves and reloads exactly; literal script markup stays escaped. Existing XLSX preview/apply, omitted-row preservation, explicit publication, reserve/release and exact fixture cleanup remain green.
- Installed local controller raw SHA equals workspace raw SHA `0ab08babf83bac97e791dc4d74fa05f7eec8c298789a4766bbd1310bbf94dbd3`; only that local controller was copied for this narrow check. This is not final immutable-artifact or host proof.
- `python D:/codex/temp/cmteh-warehouse-ops-20261111/test-ops.py`: 14 PASS. Added temp-directory protocol execution installs all five fixture bytes and exercises the ordinary runner with a subprocess stub: exactly four calls/results, no recovery call/log, native config-argument boundary retained; unknown/missing suite sets reject. These local protocol checks do not claim actual PHP suites ran on the target.
- Local installed controller `php -l` and browser `node --check`: PASS. Existing Python3.6 AST/API boundary check covers changed runtime helper.

External raw SHA256: proof-ops.py `c954505c3bbc9188437ec06c00fa1585753a3614bada86938b0257bdba14e06d`; test-ops.py `0d80058a6fbb20333e7add23e36d8994e3b6f31f4b5dfb0fc2aad2f74dab9c7e`; prepared index `15d9be8628981e06779c37866a8daaf3636a055c60c34a626e91d264dd373277`. Index rehashes its original 32 records/19 runtime helpers; prior target-compile receipt is explicitly marked prior-only for changed proof-ops.py. Fresh target compile/functional proof and strict clean-clone remain root gates. ROOT-GO action null, completed false, all 12 action flags false.

CONCERNS: no additional product work added. Three REPORT findings remain as classified by the whole reviewer. No host writes, SSH, SMTP, cron, real inventory activation or permissions changes occurred.
