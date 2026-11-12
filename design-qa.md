# V2 — visual and interaction acceptance

final result: passed

## Visual truth and evidence

- Source: selected «Чистый поиск» board, `C:/Users/Alexandr/.codex/generated_images/01a1caaf-1a30-75b0-80b4-8fcd81fb769b/exec-3b0ca6c1-5754-4e98-9c87-42f94330bd91.png`.
- Evidence directory: `.autopilot/2026-11-12-v2-search-local/evidence/` (the run loses `--wip` when Autopilot lands).
- `reference-board.png`: 1672×941. `reference-home.png`: 1043×788, source crop `(31,79,1074,867)`.
- Implementation: http://127.0.0.1:3162/ ; `home-reference-size-final.png`: 1043×788 at CSS viewport 1043×788, density 1×, homepage with an empty search field and closed menus.
- Full-view comparisons: `compare-home-matched-initial.png` and `compare-home-matched-final.png`, both 1668×630. Each pairs the same 1043×788 source/render at the same uniform approximately 0.8 scale. Source and implementation were opened together in each comparison, rather than judged from separate views.
- Supporting final views: `home-desktop-final.png` (1440×1080, CSS 1440×1080, 1×); `home-tablet-final.png` (1024×900, CSS 1024×900, 1×); `home-mobile-final.png` (375×812 native capture from CSS 390×844, renderer scale approximately 0.962). Mobile is a responsive/interaction check; there is no separate mobile reference requiring a pixel-exact comparison.
- Final native user-browser view: `preview-visible.png`, 1872×882. Browser viewport override was reset and the live V2 homepage was left open for the owner.

## Findings and closure

| Initial finding | Severity | Applied fix | Post-fix evidence |
| --- | --- | --- | --- |
| Desktop/tablet search icon collapsed visually because existing padding consumed its 25px box | P2 | Explicit zero padding on the image icon | Final matched comparison shows a readable search symbol |
| Excavator drawing competed with the search | P2 | Decorative opacity reduced to .055 desktop and .045 mobile | Final paired comparison shows a recognizable, low-contrast drawing |
| Inherited footer margin and homepage minimum height pushed the footer outside the desktop viewport | P2 | Removed inherited margin, compacted vertical rhythm and legal links, allowed the homepage to fill the available flex height | Matched 1043×788 fits; 1440×1080 body is 1080px with no horizontal overflow |
| Search-button shape and logo optical sizes drifted from the intended balance | P3 | 16px button radius; larger CAT; horizontal John Deere/CNH assets; compact CNH and more legible Deere opacity | Final matched comparison and final desktop/mobile captures |

No unresolved P0, P1 or P2 issue was observed in the final comparison. The actual source company logo and real partner wordmarks intentionally replace the generated board's approximate marks. The quiet menu button and existing footer routes preserve access to existing functions. Existing catalog/filter structure is retained; the homepage is the selected 1:1 composition target, while working inner pages retain the established functionality.

## Required fidelity surfaces

- **Fonts/typography:** ordinary sans-serif UI follows the source's restrained hierarchy. The search remains primary; the heading is muted and regular weight. Existing CMS custom headlines remain dynamic. No industrial display typeface dominates the new shell.
- **Spacing/layout:** central company logo, wide search, example, compact list/file link and two rows of brands follow the intended hierarchy. Desktop footer is visible without the initial unnecessary scroll. At 390px the layout recomposes and keeps the main controls reachable.
- **Colors/tokens:** white canvas, dark body text, gray supporting text and brands, yellow search action. The list/file action remains gray text with a small document icon; no rejected large colored banner was restored.
- **Images/assets:** separate low-contrast excavator artwork, the real company logo, real brand files and Feather icon assets are used. No page screenshot or code-drawn approximation replaces an interactive screen. Final browser inspection reported no broken images.
- **Copy/content:** the exact compact action «Проценить список или файл» sits directly below search. Company/contact/navigation/catalog values use the existing models. Visible test catalog values are synthetic fixtures, not asserted real stock or commercial prices.

The full paired view makes search, supporting action and brands readable at the comparison scale. Focused functional captures additionally cover `search-desktop.png`, `search-mobile.png`, `warehouse-mobile.png`, `cart-acknowledgement-mobile.png`, `list-check-mobile.png`, `file-quote-acknowledgement.png` and `parts-list-desktop-final.png`. The parts-list native capture is 1028×777 from CSS 1043×788; it is interaction evidence, not a homepage fidelity comparison.

## Browser acceptance — performed by the lead in the owner's browser

- Keyboard search `RE-568158` → suggestion → the John Deere product; unknown search → clear empty result.
- Mobile warehouse B → cart, authoritative price 850.25 and five-day term retained, quantity 2 → accepted local lead and empty cart acknowledgement.
- Text list preserves leading zeros, quantities 2 and 3, and an unknown-SKU result. The list check does not silently add items to the cart.
- Own synthetic CSV uploaded through the native user-browser file dialog, two rows checked, file quote accepted locally. No permission settings were changed and no real external email was sent.
- A temporary clearly marked synthetic product with a 160-character title, 52-character SKU and no image remained readable at CSS 390px; DOM scroll width 375≤390. Adding it retained the long title/SKU in the cart. Its cart line was removed through UI; the own test product was then unpublished through the existing confirmed CLI workflow, retaining its record.
- Brand Caterpillar and delivery routes, mobile menu and quick-request dialog work. Final browser console errors: none observed. No horizontal overflow or broken images observed in the final views.

## Deterministic checks and limits

Before the shared-head synchronization below, `python opencart/dev/v2-check.py --runtime-dir D:/codex/temp/cmteh-v2-search-20261112 --port 3162` passed: five ownership tests, smoke checks, 24 HTTP/API/assets checks, three existing PHP/MySQL suites (catalog, warehouse offers and quote), and the then-current unchanged 126-file default extension archive. A repeat start preserved 17 product records, two warehouses and exact warehouse-fixture identities before the later temporary stress fixture.

After the presentation fixes, `--http-only` passed the narrow safety/HTTP/package recheck; unchanged business suites were not rerun. Subsequent optical changes were checked by overlay refresh and asset GETs. Full and narrow evidence are separate files in the own runtime's `evidence/` directory. That historical default extension SHA-256 was `b78dbe14f9132c7a6a4164a1eb79d665fcd9f3488064b09b3f470e59c5dae442`; the current package is recorded below.

This is local V2 acceptance on isolated synthetic data. It is not a production release/recovery gate or proof of deployment. V1 source/runtime and its real admin/database were not modified or connected. V1/V2 remain presentation options for one existing backend/admin; binding the selected design to the real system is a separate owner decision.

## Shared head synchronization — accepted baseline 8a5ada0

PR26 added shared native-CMS analytics/ownership settings. V2 now consumes exactly the same three server-provided head blocks as the default layout. No admin/API/schema/business changes were made. The own local runtime at port 3162 was refreshed from this accepted base; fixture identities were preserved. No V1 checkout/runtime or real admin/database was modified or connected.

The focused HTTP regression failed before the change because the Yandex verification meta was absent. It now passes for both verification metas and the conditional local analytics loader using synthetic values saved through the existing CMS model. Exact original site-row restoration and the default-off state pass. Returned HTML was inspected only over HTTP; provider scripts were never executed or requested.

Current checks: 5 ownership safety tests, 24 HTTP/API/assets checks, `admin-catalog-integration.php` and `catalog-integration.php` passed. The current exact default archive has **127 files**, SHA-256 `bcc029517fa3419682f2ca9cd1d6b6a0692085a44d6ad64213edf8e407933ac4`; the 126-file evidence above is historical, for the previous base. Unchanged warehouse/quote suites and browser journeys were not repeated. Pre/post rendered homepage body and every loaded CSS file have identical hashes, so the accepted visual captures remain applicable. Evidence and retained HTTP regression: `D:/codex/temp/cmteh-v2-search-20261112/evidence/v2-head-sync.json` and `D:/codex/temp/cmteh-v2-search-20261112/head-sync-check.py`. Local preview remains running.

## Follow-up polish

- On mobile, the long placeholder naturally truncates inside the input; the full accessible search label remains available.
- Further optical balancing of partner wordmarks is optional and does not block use.

Implementation checklist: initial P2 fixes verified; final paired comparison inspected; desktop/tablet/mobile and critical interactions checked; live local preview left open; no further app-code changes required for this handoff.
