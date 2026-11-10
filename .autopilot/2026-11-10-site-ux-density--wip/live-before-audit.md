# Live mobile UX audit — before redesign

Read-only actual https://test.cmteh.ru, Chromium390×664, isolated fresh browser context. No quote/lead/email submissions, native login or host mutation. One read-only `/api/list-check` POST. Browser closed; no shared browser/storage modified. No actual new user screenshot attachment was available in the latest message; these captures observe live deployed site.

## Visible work area

Header120px; fixed lower contact bar starts596 and covers bottom68px. Thus first-screen task content must fit within y120..596, not the entire664 viewport. No horizontal overflow on sampled routes (scrollWidth390). Large vertical expenditure is observed, not inferred from total page height alone.

| Route | Useful-content position at initial scroll0 | Observed problem |
|---|---:|---|
| /parts-list | textarea starts602; check939 | No usable input/control in initial visible area. H1 starts205; sourcepanel326, duplicated sourceheading371..446. |
| /catalog | first card562; add699 | Only upper fragment of item visible. Two-line H1205..317 plus explanatoryintro, resultcount, sorting, filter. |
| /catalog?category=filters | first card614; add751 | Activefilter strip increases overhead; first item below lower bar. |
| /catalog?q=RE568158 | first card614; add751 | Exactone-resultsearch still repeats full genericintro/sort/filter above result. |
| /catalog?brand=john-deere | first card614 | Same overhead with active brand. |
| /brand/john-deere | first card924 | Hero555px includes duplicate brand text inside large yellowplaceholder, then three summary/marketingcards. |
| /product/jd-re568158 | H1327; price525; add567..613 | Breadcrumbgap, emptyphoto banner and badges precede title; bottom bar partly covers primarybutton on initial screen. |
| /request empty | actual emptytitle610; leadform807 | Large list-promotionpanel dominates the emptycart page before real state. |
| /contacts | form939 | Contact details primary content visible but form far after long informationstack; sharedintrospacing applies. |
| /about | first contentpanel415 | Sharedbreadcrumb/headingintro consumption; some long text is real content, not disposable blankspace. |
| /articles | firstcard345; firstcardheight305 | Section/headerstack and oversizedcardheading rhythm. |
| / | hero120..582, categories582..805, products915 | Previous wholephoto goal preserved, but heading/linkheader consumes extra line before products. Real content starts well belowfold by hierarchy. |

`/search` is not an application route: direct `/search?q=...` gave404 and is excluded from UX acceptance. Actual rendered search is GET `/catalog` with `q` input. Product/category/brand URLs discovered from native UI links, not invented.

## Critical after-check failure reproduced

Input RE568158;2 / 000550380;1 / UNKNOWN-UX;1. HTTP200 returns3results; no cart mutation. Playwright click naturally scrolls button into view, resulting scrollY321. At that viewport results section remains y1635, resultsheading1663. Entire787px quotecontactform separates input/check from results. Source status exists at y750, also outside current view; aftercheck viewport shows textarea and old instructions only. No programmatic focus/scroll to results. Thus persistence/API is correct while the user journey gives no visible result.

Screenshots `before-parts-list-initial.png`, `before-parts-list-after-check.png` are uncropped viewport captures. `before-parts-list-after-check-full.png` shows complete current ordering. Raw measurements `live-audit-data.json` and `live-audit-more.json` retain exact computedpadding/margins/boxes. Hidden facet children in first datafile may have boxes despite hidden ancestor; visible filtering used in secondfile, so only visiblecard positions are used above.

## Recommended composition / measurable acceptance

- Shared mobile taskpages: one compact pageheading, breadcrumbline with small adjacentspacing, short supportinghint; no editorialgap before taskcontrol. Keep readablefont and44px controls rather than shrinking everything.
- Partslist initialscreen: inputsourcechoice, enoughtextarea to paste fewrows, Checkaction all visible in usefularea. Separate quotecontactfields should not precede stockresults. Prefer mode/action progressive disclosure; don't show an inactive large contactform by default.
- On successfulcheck: render results immediately adjacent to input, focus resultheading and reveal heading + firstmatchedoffer in unobscured viewport; offer Editlist/quotewholelist without losing draft. On zeroresults reveal real emptyresult; on error remain at relevant actionableerror. Pagination should reveal new resultbatch similarly.
- Catalog/category/search: compact contextualheading/count, collapsedfilters, sorting/controlrow with no duplicateintro; show a complete firstitem incl availability/price/add within390×664 initialviewport where normal content allows. Preserve searchquery/filterURL semantics.
- Brand: eliminate duplicate oversizedbrandplaceholder and marketingnumbers ahead ofinventory; contextualbrandheading/shortintro followed by actualproducts, retain linktofullfilteredcatalog.
- Product: prioritize actualtitle/SKU/availability/price/add. Missingphoto gets smallinlineplaceholder, not verticalcontentbarrier. Details/specifications below, readable and not piled against one another. Actualimages may retain deliberate boundedarea; don't remove businesscontent blindly.
- Cart: actualempty/itemsstate before optional listpromotion; concisesecondarylink to partslist. Contactform follows selecteditems, not misleading emptyorlargeintro.
- Content/footer: consistentspacing tiers; genuineeditorialtext stays readable. Mobilefooter can be more compact with secondarysectiondisclosure, retain visiblecontact options. Fixedbar must not coverfocus/error/actiontargets.

All measurements are CSSpixelbrowserviewport, not physicaliPhone. Original evidence directory retained outside repository: D:/codex/temp/cmteh-site-ux-density-20261110.
