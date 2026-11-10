# T01 local UX evidence

Source worktree: D:/codex/worktrees/cmteh-site-ux-density-20261110. Dev runtime http://127.0.0.1:3179. Executor changed presentation only. No host deployment, commit or push performed.

RED before edits: permanent short browser journey failed with textarea602..831 while fixed actions begin596. A real check observed result heading1354 and offer1682..1728, outside the visible viewport. Baseline screenshot red-result.png.

Final390x664: input367..459; check517..561; result heading16..44; first result action341..387. Catalogue/search/category-filter first card380..574 and action517..561. Brand first card276 and action413..457. Product add459..505. Empty cart heading362. Contact form starts491. Fixed actions begin596. Post-check focus is list-results-title; source and empty cart retained.

Final affected command: CMTEH_BROWSER_BASE=http://127.0.0.1:3179 CMTEH_BROWSER_TOOLING=D:/codex/cmteh node opencart/tests/browser/storefront.mjs. PASS14 journeys, previously13. The final run follows catalogue/result-context changes.

Focused external command: node D:/codex/temp/cmteh-site-ux-density-20261110/focused.cjs. PASS375x667,844x390,20px text and reduced-motion settings;28 route samples no horizontal overflow; file chooser;51-row pagination with50 rows per page; result focus and edit preserve input; empty result and network error retain input. Synthetic photo and165-character title/43-character SKU exist only in disposable dev DB. Real image loaded; full SKU wraps visibly in focused-detail screenshots. Enlarged text and landscape grow naturally and remain scrollable.

All17 public route samples captured at390/1024/1440: after-*.png and after-measurements.json. Every390 template and targeted tablet/desktop home,catalogue,list,brand,product,contacts visually inspected. Whole home photo and CMS block order retained. No permanent screenshot matrix added.

git diff --check owned files: PASS. CSS/module/XLSX graph20261110-7. Payload/storage/parsing/limits/matching/submission unchanged. Generic completion message removed; native quote/contact/brand/category disclosures retain content.

R13i host acceptance remains placeholder for root operations. Full strict gate, independent review, blind acceptance, PR/merge and test-host operation are root-owned and pending executor handoff.
