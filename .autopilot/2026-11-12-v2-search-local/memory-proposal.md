# V2 search: durable operator notes

- Read the repository-root `AGENTS.md` and this folder's `PROGRESS.md` before editing; preserve its staged gates and owner boundaries.
- Current app is `opencart/extension/cmteh`; root Next.js is reference only. The V2 presentation overlay lives in `opencart/designs/v2-search/`; local launcher/checker are `opencart/dev/v2-local.py` and `v2-check.py`.
- Keep V1 (`D:/codex/cmteh`, `https://test.cmteh.ru/`) untouched. V2 is local-only, isolated runtime/DB/private storage, synthetic fixtures; never copy production secrets or client data.
- Read `opencart/designs/v2-search/README.md` for current launch, refresh, stop and check commands. `D:/codex/CMTEH.RU/versions.json` is authoritative for the current URL/runtime state.
- For a fresh instance, choose a unique `cmteh-v2-search-*` runtime and free port; if Docker subnet allocation fails, inspect existing networks/routes and assign an unused `/28` via `CMTEH_DOCKER_SUBNET`. Never delete shared networks.
- Repeating `start` preserves fixture identities; `refresh` only reapplies presentation. A changed demo fixture needs a fresh runtime. Stop only the owned instance; do not remove its data casually.
- At finalization, Autopilot renames `.autopilot/2026-11-12-v2-search-local--wip/` to `.autopilot/2026-11-12-v2-search-local/`; update durable evidence links accordingly.
- Small missing handoff convention: record `PROGRESS.md` before/after state and include a concise git trailer/history note so later agents can identify the accepted source revision and remaining work.
