# Project memory proposal — admin unification

- Runtime/source of truth: `opencart/` is the OpenCart 4.1.0.4 public app; root Next.js is reference only. Operational/editor instructions are in `opencart/admin-guide.md`.
- `opencart/extension/cmteh/system/seed-cleanup.json` is immutable hashed seed input: preserve exact bytes (cb85…); never normalize/reformat it or infer authorization from an old manifest hash.
- OpenCart `Request` has already HTML-escaped incoming values. Decode once at the native display/filter boundary, then Twig-escape; only native pre-escaped URL-helper output follows OpenCart’s URL convention. Avoid double decoding or rendering user input as HTML.
- Native admin accounts are not public customers; lead/quote requests are not orders. The business read model derives contacts from requests and must not imply deduplicated customer identities.
- Campaign count is `getTotalReports(campaign_id)` (tracked requests), not stale `clicks`, people, orders, or conversions. Stored `page_url` is sender-declared source, not attribution proof; see [admin capabilities](../../../docs/admin-capabilities-2026-11-11.md).
- Unified admin has top-level «Обращения», «Контакты», «Отчёты» and «Возможности и доработки», with no separate duplicate «СМ ТЕХНО» catalogue group. See `opencart/extension/cmteh/system/AdminBusiness.php` and `opencart/extension/cmteh/admin/controller/event/cmteh_admin.php`.
- Environment mismatch matters: local proof used MySQL 8.4/PHP 8.3; host target is MySQL 5.7/PHP 8.2. Check target compatibility evidence separately; local success alone is not host proof.
- Successful clean-clone startup commands are retained with the run evidence; choose a new owned runtime/port/non-overlapping subnet for a new test. Existing fixtures and their directories are not disposable foreign data.
- Every release receipt must match current product source and exact outer/nested artifact hashes. An older 305-PASS receipt does not prove a later package.
