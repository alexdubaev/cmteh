# Universal Commerce Storefront

Responsive Next.js storefront for [Universal Commerce CMS](https://github.com/alexdubaev/Universal-Commerce-CMS). Extracted from its [storefront tree](https://github.com/alexdubaev/Universal-Commerce-CMS/tree/a54d54aade9a23dee6e5eb0b930dd45ff3344e1d/storefront).

## Implemented

- one responsive desktop/mobile codebase;
- search-first B2B home page;
- multi-brand catalog;
- live brand discovery from published Directus products, using one shared brand template;
- working brand, category, availability and part-type filters;
- price/title/popularity sorting and pagination;
- dynamic brand routes at `/brand/[brand]`;
- dynamic category routes at `/category/[slug]`;
- dynamic product routes at `/product/[slug]`;
- search suggestions and local recent-search history;
- product codes / OEM numbers, child specifications, documents, gallery and analog/supersession UI;
- local RFQ list stored in the browser;
- manual bulk article input;
- XLSX / CSV / TXT request-list import;
- delivery, payment, about, contacts and request pages;
- CMS-driven header/footer/legal navigation;
- CMS-driven page rendering for published `pages` + visible `page_sections`;
- generic `/[slug]` route for future standard CMS pages;
- optional CMS-driven home hero and extra home sections;
- dynamic SEO metadata for product/brand/category pages;
- Organization, Product and BreadcrumbList JSON-LD;
- robots.txt;
- chunked product sitemap plus brand/category/static sitemap;
- server-side Directus adapter;
- explicit mock mode for development;
- `/commerce/search` integration;
- `/commerce/leads` proxy with durable request-key reuse;
- `/commerce/orders` proxy prepared for later cart/account work;
- Directus asset proxy with UUID validation and allowlisting of files referenced by published storefront content; the server token never reaches the browser;
- health endpoint without upstream error-detail leakage;
- request-size/search guard rails and sanitized public API errors;
- Local route/adapter integration tests, minimal domain tests, two critical Chromium journeys and Directus concurrency tests.

The storefront does **not** change Directus schema, roles, permissions, Docker, database or deployment configuration.

## Run in mock mode

```powershell
Copy-Item .env.example .env.local
npm ci
npm run dev
```

`.env.example` defaults to `STOREFRONT_MOCK_MODE=true`, so Directus is not required.

Open `http://localhost:3000`.

## Connect Directus

Set:

```env
DIRECTUS_URL=http://127.0.0.1:18056
DIRECTUS_TOKEN=<server-side service token>
STOREFRONT_MOCK_MODE=false
STOREFRONT_ALLOW_MOCK_FALLBACK=false
NEXT_PUBLIC_SITE_URL=https://your-store.example
```

The token is server-side only. Do not rename it to a `NEXT_PUBLIC_*` variable.

When `STOREFRONT_MOCK_MODE=false`, catalog errors fail closed instead of silently substituting fictional products. `STOREFRONT_ALLOW_MOCK_FALLBACK=true` exists only for controlled development/testing.

The current Directus baseline keeps anonymous access closed. Production should use a reviewed least-privilege storefront service identity rather than an Administrator token.

## Existing CMS contracts used

- `GET /items/products`
- `GET /items/categories`
- `GET /items/product_codes`
- `GET /items/product_images`
- `GET /items/product_specifications`
- `GET /items/product_documents`
- `GET /items/products_analogs`
- `GET /items/site_settings`
- `GET /items/navigation_items`
- `GET /items/pages`
- `GET /items/page_sections`
- `GET /items/home_page`
- `GET /commerce/search?q=...`
- `POST /commerce/leads`
- `POST /commerce/orders`
- `GET /assets/:id`

The storefront preserves the current CMS schema. No duplicate storefront-only catalog schema is introduced.

## Quality checks

The storefront runs independently in mock mode. For the full local gate, provide a compatible Universal Commerce CMS source checkout. The gate runs the CMS backend tests and the storefront test against the actual gateway handler without starting Directus. The source revision linked above is the baseline for this extraction.

```powershell
npm ci
npx playwright install chromium
$env:CMS_SOURCE_ROOT = 'D:/codex/Universal Commerce CMS'
node scripts/verify-local.mjs
```

Checks run locally only; there are no GitHub Actions workflows. The gate includes backend/storefront integrations, minimal domain tests, TypeScript, one dependency audit, a mock production build, deployed-extension/client-secret checks and two short Chromium E2E journeys. The committed lockfile remains the dependency source of truth.

The browser tests start their own loopback server on port 3137. The opt-in live scripts require an explicitly selected CMS test checkout and its reviewed, active synthetic fixture manifest. For the live probe, pass the observer environment explicitly with `node --env-file="<CMS checkout>/dev/.env" scripts/live-probe.mjs --runtime-approved`. It can write owned fixtures or restart a local storefront and is outside the normal gate. [CMS test policy](https://github.com/alexdubaev/Universal-Commerce-CMS/blob/a54d54aade9a23dee6e5eb0b930dd45ff3344e1d/docs/development/testing.md).

## Fast design changes

The storefront is intentionally design-swappable.

Before any redesign, read:

- `AGENTS.md`
- `DESIGN-CONTRACT.md`

Global visual tokens, including component colors and translucent RGB primitives, are isolated in `app/theme.css`. Search, request storage/import and lead submission state/actions live in `hooks/**`; their components retain the visual markup. A normal design-only task should preserve `hooks/**`, `lib/**`, `app/api/**`, Directus contracts, routes and RFQ/order behavior.

A future agent can therefore take a screenshot/Figma/reference and replace the presentation layer while the same catalog, search, SEO entities and commerce flows continue feeding it.
