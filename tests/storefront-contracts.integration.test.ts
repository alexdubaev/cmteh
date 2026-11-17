import assert from "node:assert/strict";
import { createServer } from "node:http";
import { once } from "node:events";
import { after, before, beforeEach, describe, it } from "node:test";
import { renderToStaticMarkup } from "react-dom/server";
import { setFixtureEnv } from "./fixture-env";

type Row = Record<string, unknown>;
const token = "synthetic-server-secret-never-public";
const imageId = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa";
const pdfId = "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb";
const htmlId = "cccccccc-cccc-4ccc-8ccc-cccccccccccc";
const privateId = "dddddddd-dddd-4ddd-8ddd-dddddddddddd";
const timestamp = "2026-10-07T12:00:00.000Z";
let rows: Record<string, Row[]>;
let unavailable = false;
let partialSearch = false;
const requests: string[] = [];

// Synthetic HTTP data service, independent of the adapter's request order.
function matches(value: unknown, filter: unknown): boolean {
  if (!filter || typeof filter !== "object") return true;
  return Object.entries(filter).every(([key, expected]) => {
    const row = value && typeof value === "object" ? value as Row : {};
    const scalar = row.id ?? value;
    if (key === "_and") return (expected as unknown[]).every(term => matches(value, term));
    if (key === "_or") return (expected as unknown[]).some(term => matches(value, term));
    if (key === "_eq") return scalar === expected;
    if (key === "_in") return (expected as unknown[]).includes(scalar);
    if (key === "_null") return (value == null) === expected;
    if (key === "_nnull") return (value != null) === expected;
    return matches(row[key], expected);
  });
}

function sliceRows(items: Row[], params: URLSearchParams) {
  const offset = Number(params.get("offset") ?? (Number(params.get("page") ?? 1) - 1) * Number(params.get("limit") ?? 100));
  return items.slice(offset, offset + Number(params.get("limit") ?? 100));
}

const server = createServer((request, response) => {
  const json = (body: unknown, status = 200) => {
    response.writeHead(status, { "content-type": "application/json" });
    response.end(JSON.stringify(body));
  };
  if (request.headers.authorization !== `Bearer ${token}`) return json({}, 403);
  if (unavailable) return json({ errors: [{ message: `upstream diagnostic ${token}` }] }, 503);
  const url = new URL(request.url!, "http://localhost");
  requests.push(`${url.pathname}${url.search}`);
  if (url.pathname.startsWith("/commerce/storefront/assets/")) {
    response.writeHead(200, { "content-type": url.pathname.endsWith(htmlId) ? "text/html" : url.pathname.endsWith(pdfId) ? "application/pdf" : "image/png" });
    return response.end("synthetic asset bytes");
  }
  if (url.pathname === "/commerce/storefront/search") {
    const raw = url.searchParams.get("q")!.trim();
    const query = raw.replace(/[^a-z0-9]/gi, "").toUpperCase();
    let products = rows.products.filter(row => row.status === "published").filter(row => {
      const sku = String(row.sku ?? "").replace(/[^a-z0-9]/gi, "").toUpperCase();
      const title = String(row.title ?? "").toLocaleLowerCase("ru");
      const brand = String(row.brand ?? "").toLocaleLowerCase("ru");
      return (query && (sku === query || sku.startsWith(query))) || title.includes(raw.toLocaleLowerCase("ru")) || brand.includes(raw.toLocaleLowerCase("ru"));
    });
    const brand = url.searchParams.get("brand");
    const category = url.searchParams.get("category");
    const availability = url.searchParams.get("availability");
    const partType = url.searchParams.get("partType");
    if (brand) products = products.filter(row => row.brand === brand);
    if (category) products = products.filter(row => (row.category as Row | null)?.slug === category);
    if (availability) products = products.filter(row => row.availability_status === availability);
    if (partType) products = products.filter(row => row.part_type === partType);
    const sort = url.searchParams.get("sort");
    if (sort === "price_asc") products.sort((a, b) => Number(a.price) - Number(b.price));
    else if (sort === "price_desc") products.sort((a, b) => Number(b.price) - Number(a.price));
    else if (sort === "title") products.sort((a, b) => String(a.title).localeCompare(String(b.title), "ru"));
    else products.sort((a, b) => Number(b.popularity_score) - Number(a.popularity_score));
    return json({ data: sliceRows(products, url.searchParams).map(({ id }) => ({ id })), meta: { total: products.length, ...(partialSearch ? { partial: true } : {}) } });
  }
  const collection = /^\/commerce\/storefront\/items\/([a-z_]+)$/.exec(url.pathname)?.[1];
  if (!collection || !rows[collection]) return json({}, 404);
  const items = rows[collection].filter(row => matches(row, JSON.parse(url.searchParams.get("filter") ?? "{}")));
  if (url.searchParams.has("aggregate[count]")) {
    const brands = [...new Set(items.map(row => row.brand))];
    return json({ data: brands.map(brand => ({ brand, count: items.filter(row => row.brand === brand).length })) });
  }
  for (const field of (url.searchParams.get("sort") ?? "").split(",").reverse().filter(Boolean)) {
    const key = field.replace(/^-/, "");
    items.sort((a, b) => (typeof a[key] === "number" && typeof b[key] === "number"
      ? Number(a[key]) - Number(b[key]) : String(a[key] ?? "").localeCompare(String(b[key] ?? ""))) * (field.startsWith("-") ? -1 : 1));
  }
  const fields = [...new Set((url.searchParams.get("fields") ?? "").split(",").map(field => field.split(".")[0]))];
  const data = sliceRows(items, url.searchParams).map(row => Object.fromEntries(fields.filter(field => field in row).map(field => [field, row[field]])));
  json({ data: ["site_settings", "home_page"].includes(collection) ? data[0] ?? null : data, meta: { filter_count: items.length } });
});

let catalog: typeof import("../lib/catalog");
let content: typeof import("../lib/content");
let directus: typeof import("../lib/directus");
let sitemap: typeof import("../app/sitemaps/[name]/route");
let assets: typeof import("../app/api/assets/[id]/route");
let lead: typeof import("../app/api/lead/route");
let catalogPage: typeof import("../app/catalog/page");
let restoreEnv: (() => void) | undefined;

before(async () => {
  server.listen(0, "127.0.0.1");
  await once(server, "listening");
  const address = server.address();
  if (!address || typeof address === "string") throw new Error("Missing synthetic backend port");
  restoreEnv = setFixtureEnv({
    DIRECTUS_URL: `http://127.0.0.1:${address.port}`,
    DIRECTUS_TOKEN: token,
    STOREFRONT_MOCK_MODE: "false",
    STOREFRONT_ALLOW_MOCK_FALLBACK: "false",
    STOREFRONT_DIRECTUS_GATEWAY: "true",
    NEXT_PUBLIC_SITE_URL: "https://synthetic.example",
  });
  [catalog, content, directus, sitemap, assets, lead, catalogPage] = await Promise.all([
    import("../lib/catalog"), import("../lib/content"), import("../lib/directus"),
    import("../app/sitemaps/[name]/route"), import("../app/api/assets/[id]/route"), import("../app/api/lead/route"), import("../app/catalog/page"),
  ]);
});

after(async () => {
  try {
    if (server.listening) await new Promise<void>((resolve, reject) => server.close(error => error ? reject(error) : resolve()));
  } finally {
    restoreEnv?.();
  }
});

beforeEach(() => {
  requests.length = 0;
  unavailable = false;
  partialSearch = false;
  const category = { id: "parts", slug: "parts", title: "Parts", status: "published" };
  const products = Array.from({ length: 32 }, (_, i) => ({
    id: `p${String(i).padStart(2, "0")}`, slug: `part-${i}`, sku: `PART-${i}`, title: `Part ${i}`,
    brand: i === 24 ? "caterpillar" : "LiuGong", category: i === 26 ? { ...category, slug: "other" } : category, status: "published", price: 100 - i,
    availability_status: i >= 20 ? "in_stock" : "on_request", part_type: i === 28 ? "aftermarket" : "original",
    popularity_score: 100 - i, main_image: i < 20 && i % 5 === 0 ? imageId : null,
    updated_at: i === 1 ? null : timestamp, is_indexable: i !== 31,
  }));
  rows = {
    products: [...products, { ...products[0], id: "draft", slug: "draft", status: "draft", main_image: privateId }],
    categories: [category],
    product_documents: [
      { id: "pdf", file: pdfId, title: "Synthetic PDF", status: "published", product: products[0] },
      { id: "html", file: htmlId, title: "Synthetic HTML", status: "published", product: products[0] },
    ],
    product_codes: [], product_images: [], product_specifications: [], products_analogs: [],
    site_settings: [{ company_name: "Synthetic Company", phone: "+79990000000", email: "sales@example.invalid" }],
    home_page: [{ id: "home", status: "published", hero_title: "Synthetic Home", is_indexable: false }],
    pages: [{ id: "payment", slug: "payment", title: "Payment", status: "published", is_indexable: false, canonical_url: "javascript:alert(1)" },
      { id: "warranty", slug: "warranty", title: "Warranty", status: "published", is_indexable: true, updated_at: timestamp }],
    page_sections: [],
    navigation_items: [
      { id: "safe", label: "Catalog", url: "/catalog", status: "published", is_visible: true, location: "header", parent: null },
      { id: "unsafe", label: "Unsafe", url: "javascript:alert(1)", status: "published", is_visible: true, location: "header", parent: null },
    ],
  };
});

const mapResponse = (name: string) => sitemap.GET(new Request(`https://synthetic.example/sitemaps/${name}`), { params: Promise.resolve({ name }) });
const assetResponse = (id: string) => assets.GET(new Request(`https://synthetic.example/api/assets/${id}`), { params: Promise.resolve({ id }) });

describe("storefront routes and adapters over HTTP", () => {
  it("maps CMS timestamps, future brand labels and published singleton content", async () => {
    const brands = await catalog.getBrands();
    assert.ok(brands.some(({ slug, name }) => slug === "liugong" && name === "LiuGong"));
    assert.ok(brands.some(({ slug, name }) => slug === "caterpillar" && name === "caterpillar"));
    assert.equal((await catalog.getProducts({ brand: "liugong", sort: "title" })).items.every(row => row.brand === "LiuGong"), true);
    assert.deepEqual((await catalog.getProducts({ brand: "caterpillar", sort: "title" })).items.map(row => row.id), ["p24"]);
    const product = await catalog.getProductDetail("part-0");
    assert.equal(product?.date_updated, timestamp);
    assert.deepEqual(product?.documents?.map(({ file, title }) => ({ file, title })), [
      { file: pdfId, title: "Synthetic PDF" }, { file: htmlId, title: "Synthetic HTML" },
    ]);
    assert.equal((await catalog.getProduct("part-1"))?.date_updated, null);
    assert.equal(await catalog.getProduct("draft"), null);
    assert.equal((await content.getCmsSiteSettings())?.company_name, "Synthetic Company");
    assert.equal((await content.getCmsHome())?.hero_title, "Synthetic Home");
    rows.home_page[0].status = "draft";
    assert.equal(await content.getCmsHome(), null);
  });

  it("paginates across the image boundary without missing or duplicating products", async () => {
    const first = await catalog.getProducts({ limit: 3 });
    const boundary = await catalog.getProducts({ limit: 3, page: 2 });
    const last = await catalog.getProducts({ limit: 3, page: 11 });
    assert.deepEqual(first.items.map(row => row.id), ["p00", "p05", "p10"]);
    assert.deepEqual(boundary.items.map(row => row.id), ["p15", "p01", "p02"]);
    assert.deepEqual(last.items.map(row => row.id), ["p30", "p31"]);
    assert.equal(boundary.total, 32);
    assert.deepEqual((await catalog.getProducts({ page: 12, limit: 3 })).items, []);
  });

  it("applies facets and sorting beyond the first search candidate page", async () => {
    const query = { q: "PART", brand: "liugong", category: "parts", availability: "in_stock", partType: "original", limit: 3 } as const;
    const filtered = await catalog.getProducts({ ...query, page: 2 });
    assert.equal(filtered.total, 9);
    assert.deepEqual(filtered.items.map(row => row.id), ["p23", "p25", "p27"]);
    assert.deepEqual((await catalog.getProducts({ ...query, sort: "price_asc" })).items.map(row => row.id), ["p31", "p30", "p29"]);
    assert.deepEqual((await catalog.getProducts({ q: "PART-31" })).items.map(row => row.id), ["p31"]);
  });

  it("keeps invalid search queries out of the upstream adapter while blank searches browse", async () => {
    for (const q of ["A", "x".repeat(65)]) {
      const { items, total, source } = await catalog.getProducts({ q });
      assert.deepEqual({ items, total, source }, { items: [], total: 0, source: "directus" });
    }
    assert.equal(requests.length, 0);

    const browse = await catalog.getProducts({ q: "   " });
    assert.equal(browse.total, 32);
    assert.equal(browse.items?.length, 12);
    assert.equal(requests.some(path => path.startsWith("/commerce/storefront/items/products?")), true);
  });

  it("renders actionable catalog guidance for too-short and too-long search queries", async () => {
    for (const [q, guidance] of [
      ["A", "Поисковый запрос должен содержать не менее 2 символов."],
      ["x".repeat(65), "Сократите поисковый запрос до 64 символов."],
    ]) {
      const html = renderToStaticMarkup(await catalogPage.default({ searchParams: Promise.resolve({ q }) }));
      assert.ok(html?.includes(guidance));
      assert.ok(html?.includes('role="status"'));
    }
    assert.equal(requests.some(path => path.startsWith("/commerce/storefront/search?")), false);
  });

  it("requests one filtered search page and hydrates only its returned product IDs", async () => {
    const result = await catalog.getProducts({ q: "PART", brand: "liugong", category: "parts", availability: "in_stock", partType: "original", page: 2, limit: 3 });
    const searchRequests = requests.filter(path => path.startsWith("/commerce/storefront/search?"));
    assert.equal(searchRequests?.length, 1);
    const search = new URL(searchRequests[0], "http://local");
    assert.equal(search.searchParams.get("brand"), "LiuGong");
    assert.equal(search.searchParams.get("category"), "parts");
    assert.equal(search.searchParams.get("availability"), "in_stock");
    assert.equal(search.searchParams.get("partType"), "original");
    assert.equal(search.searchParams.get("page"), "2");
    const hydration = requests.find(path => path.startsWith("/commerce/storefront/items/products?") && path.includes("_in"));
    assert.notEqual(hydration, undefined);
    assert.equal(JSON.parse(new URL(hydration!, "http://local").searchParams.get("filter")!)._and[1].id._in?.length, 3);
    assert.equal(result.total, 9);
  });

  it("uses a consistent 20-item search page cap and announces partial alias results", async () => {
    const capped = await catalog.getProducts({ q: "PART", limit: 24 });
    assert.equal(capped.limit, 20);
    assert.equal(capped.items?.length, 20);

    partialSearch = true;
    const html = renderToStaticMarkup(await catalogPage.default({ searchParams: Promise.resolve({ q: "PART" }) }));
    assert.ok(html?.includes("Часть совпадений по дополнительным кодам не проверена."));
    assert.ok(html?.includes('role="status"'));
  });

  it("emits crawlable asset paths while disallowing storefront API data", async () => {
    const { default: robots } = await import("../app/robots");
    assert.deepEqual(robots().rules, [{ userAgent: "*", allow: ["/", "/api/assets/"], disallow: ["/api/"] }]);
  });

  it("canonicalizes clean catalog pagination and noindexes searches, facets, and sorts", async () => {
    const { generateMetadata } = await import("../app/catalog/page");
    const page = await generateMetadata({ searchParams: Promise.resolve({ page: "2" }) });
    assert.equal(page.alternates?.canonical, "/catalog?page=2");
    for (const searchParams of [{ q: "bolt", page: "2" }, { brand: "liugong", sort: "price_asc" }] as const) {
      const { alternates, robots } = await generateMetadata({ searchParams: Promise.resolve(searchParams) });
      assert.equal(alternates?.canonical, "/catalog");
      assert.ok(robots && typeof robots === "object");
      assert.equal(robots.index, false);
      assert.equal(robots.follow, true);
    }
    const invalidPage = await generateMetadata({ searchParams: Promise.resolve({ page: "-2" }) });
    assert.equal(invalidPage.alternates?.canonical, "/catalog");
  });

  it("excludes CMS routes whose canonical points elsewhere from the sitemap", async () => {
    rows.pages.push({ id: "external", slug: "external", title: "External", status: "published", is_indexable: true, canonical_url: "https://outside.example/canonical" });
    rows.home_page[0].canonical_url = "https://outside.example/";
    const statics = await (await mapResponse("static.xml")).text();
    assert.ok(!statics?.includes("https://synthetic.example/</loc>"));
    assert.ok(!statics?.includes("/external</loc>"));
  });

  it("excludes drafts and noindex products/pages from sitemap and metadata", async () => {
    assert.equal(await catalog.getSitemapProductCount(), 31);
    const xml = await (await mapResponse("products-0.xml")).text();
    assert.ok(xml?.includes("/product/part-0</loc>"));
    assert.ok(xml?.includes(`<lastmod>${timestamp}</lastmod>`));
    assert.ok(!xml?.includes("/product/part-31</loc>"));
    assert.ok(!xml?.includes("/product/draft</loc>"));
    const statics = await (await mapResponse("static.xml")).text();
    assert.ok(!statics?.includes("https://synthetic.example/</loc>"));
    assert.ok(!statics?.includes("/payment</loc>"));
    assert.ok(statics?.includes("/delivery</loc>"));
    assert.ok(statics?.includes("/warranty</loc>"));
    const { generateMetadata } = await import("../app/product/[slug]/page");
    const { robots } = await generateMetadata({ params: Promise.resolve({ slug: "part-31" }) });
    assert.ok(robots && typeof robots === "object");
    assert.equal(robots.index, false);
  });

  it("serves assets safely and rechecks visibility after publication revocation", async () => {
    const image = await assetResponse(imageId);
    assert.equal(image.status, 200);
    assert.equal(await image.text(), "synthetic asset bytes");
    assert.equal(image.headers.get("content-type"), "image/png");
    assert.equal((await assetResponse(pdfId)).headers.get("content-disposition"), "inline");
    const html = await assetResponse(htmlId);
    assert.equal(html.headers.get("content-type"), "application/octet-stream");
    assert.equal(html.headers.get("content-disposition"), "attachment");
    assert.equal(html.headers.get("cache-control"), "no-store");
    assert.equal(html.headers.get("x-content-type-options"), "nosniff");
    assert.ok(html.headers.get("content-security-policy")?.includes("sandbox"));
    assert.equal((await assetResponse(privateId)).status, 404);
    assert.equal((await assetResponse("../../etc/passwd")).status, 404);
    rows.product_documents[0].status = "draft";
    assert.equal((await assetResponse(pdfId)).status, 404);
  });

  it("drops unsafe CMS links and renders script-closing content safely", async () => {
    assert.deepEqual((await content.getNavigation("header")).map(item => item.url), ["/catalog"]);
    const { generateMetadata } = await import("../app/payment/page");
    assert.equal((await generateMetadata()).alternates?.canonical, "/payment");
    rows.products[0].title = "</script><script>alert(1)</script>";
    const { default: ProductPage } = await import("../app/product/[slug]/page");
    const html = renderToStaticMarkup(await ProductPage({ params: Promise.resolve({ slug: "part-0" }) }));
    assert.ok(!html?.includes("</script><script>alert(1)</script>"));
    assert.ok(html?.includes("\\u003c/script>"));
    assert.ok(!html?.includes(token));
  });

  it("links product JSON-LD to stable entities and only emits truthful offers", async () => {
    rows.products[0].price_status = "fixed";
    rows.products[0].price = 1234;
    rows.products[0].currency = "USD";
    rows.products[0].availability_status = "unknown_value";
    rows.products[0].specifications = { Width: "120 mm" };
    const { default: ProductPage } = await import("../app/product/[slug]/page");
    const html = renderToStaticMarkup(await ProductPage({ params: Promise.resolve({ slug: "part-0" }) }));
    assert.ok(html?.includes('"@id":"https://synthetic.example/product/part-0#product"'));
    assert.ok(html?.includes('"@id":"https://synthetic.example/brand/liugong#brand"'));
    assert.ok(html?.includes('"priceCurrency":"USD"'));
    assert.ok(!html?.includes('"availability":"https://schema.org/PreOrder"'));
    assert.ok(!html?.includes('"availability":'));
    assert.ok(html?.includes('"name":"Width","value":"120 mm"'));
    assert.ok(html?.includes("$"));
    const { default: BrandPage } = await import("../app/brand/[brand]/page");
    const brandHtml = renderToStaticMarkup(await BrandPage({ params: Promise.resolve({ brand: "liugong" }) }));
    assert.ok(brandHtml?.includes('"@type":"Brand","@id":"https://synthetic.example/brand/liugong#brand"'));
    assert.ok(brandHtml?.includes('"@type":"CollectionPage"'));
  });

  it("fails closed and keeps upstream diagnostics out of public failures", async (test) => {
    await assert.rejects(directus.directusFetch("/items/users"), { message: /not allowlisted/ });
    unavailable = true;
    test.mock.method(console, "error", () => {});
    await assert.rejects(catalog.getProducts(), { status: 503 });
    const response = await lead.POST(new Request("https://synthetic.example/api/lead", {
      method: "POST", body: JSON.stringify({ name: "Synthetic Lead", phone: "+79990000000" }),
    }) as never);
    assert.equal(response.status, 502);
    assert.equal(await response.text(), JSON.stringify({ error: "Сервис заявок временно недоступен." }));
    assert.equal((await assetResponse(imageId)).status, 404);
  });
});
