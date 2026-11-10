# Blind mobile UX acceptance

**Source:** clean clone, branch `codex/mobile-ux-20261110`, commit `3c41855c9b2b28251d3bf7a1d4197ca5b3fd5399`. Acceptance followed only the customer brief and its additions. No code-quality review, external site writes, calls, messages, email or payment.

## Findings

| Customer requirement | Verdict | Runtime evidence |
|---|---|---|
| Hero image fully visible on phone at initial load | **Implemented** | At 390×844, loaded hero image is y=328–621 (293 px high), fully inside the initial viewport. Screenshot: `mobile.png`. No horizontal overflow (390 px document width). |
| Remove down arrow from header Catalog button | **Implemented** | Desktop header shows `Каталог` without a down arrow. Screenshot: `desktop.png`. |
| Reduce the distance to products; compact categories before products | **Implemented** | Mobile home has six category tiles in a compact 3×2 grid, followed directly by product cards. First card begins at document y=953, about 109 px below the initial 844 px viewport. Home shows 6 cards; catalog has 16 demo items and 12 visible cards across its rendered listing. |
| Add a quick return-to-top control | **Implemented** | `Наверх` appears after scrolling and returns scrollY to 0. Also opened and dismissed the request dialog, then used the control; no lead API request was issued. |
| Bottom actions for phone call and request | **Partial** | Mobile bottom bar has `Позвонить` (`tel:` link) and `Запрос`. It reads the runtime-configured phone; the synthetic demo fixture currently displays its placeholder number, so the customer-approved published number is not yet reflected in this test CMS. The task owner plans to apply it after merge. |
| “Запрос” opens a separate short form | **Implemented** | Rechecked rendered visibility on the 390×844 mobile viewport: the separate dialog displays only name, phone and optional comment. Company, email and website controls have no visible box. No form was submitted. |

Desktop 1440×900 also has no horizontal overflow; the loaded 480 px hero image is fully within the initial viewport. No browser page errors occurred in the mobile or desktop capture. The main screenshots are `mobile.png` and `desktop.png` in this report directory.

## Setup and checks

- `git clone D:\codex\cmteh D:\codex\temp\cmteh-mobile-ux-blind-clone-20261110`
- In the clone: `git fetch origin`; `git checkout -b codex/mobile-ux-20261110 3828b38`, then refreshed to the accepted SHA with `git reset --hard 3c41855`.
- Runtime: `$env:CMTEH_DOCKER_SUBNET='10.213.0.0/28'; python opencart/dev/runtime.py --runtime-dir 'D:\codex\temp\cmteh-mobile-ux-blind-runtime-20261110' --port 3178 start --upstream 'D:\codex\temp\cmteh-admin-final-runtime-20261110-r4\opencart-4.1.0.4.zip' --refresh` — passed; loopback runtime at `127.0.0.1:3178`, Compose project `cmteh-oc-a1873f26e7af`, cached MySQL 8.4.
- Demo fixture preview: `python opencart/dev/runtime.py --runtime-dir 'D:\codex\temp\cmteh-mobile-ux-blind-runtime-20261110' --port 3178 compose exec -T web php /workspace/bin/import-catalog.php --config=/var/www/html/config.php --input=/workspace/fixtures/demo.ndjson --preview` — 36 added, 13 updated, 0 skipped/errors.
- Applied only that synthetic fixture to the isolated test DB using its preview SHA-256 and run key `blind-mobile-acceptance-20261110` — 36 added, 13 updated, 0 skipped/errors.
- Browser acceptance used locked Playwright via `CMTEH_BROWSER_TOOLING=D:\codex\cmteh` and `D:\codex\cmteh\node_modules\playwright`: mobile 390×844 and desktop 1440×900 initial-view captures; home/catalog/contact route checks; modal open/close; back-to-top interaction. App opened successfully, images loaded, no page errors or horizontal overflow. No third-party calls or lead submissions.

No run-blocking dependency was missing. Clone and runtime are intentionally retained for the lead.

