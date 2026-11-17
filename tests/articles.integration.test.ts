import assert from "node:assert/strict";
import { createServer } from "node:http";
import { once } from "node:events";
import { resolve } from "node:path";
import { pathToFileURL } from "node:url";
import { after, before, beforeEach, describe, it } from "node:test";
import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { setFixtureEnv } from "./fixture-env";

type Row = Record<string, unknown>;
const cover = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa";
const privateImage = "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb";
let rows: Row[] = [];
let unavailable = false;
let ignoreFilter = false;
let ignorePaging = false;
let catalogRows: Record<string, Row[]> = {};
let useActualGateway = false;
let virtualArticleTotal = 0;
type GatewayResponse = { status: (code: number) => GatewayResponse; set: (headers: Record<string, string>) => GatewayResponse; json: (body: unknown) => GatewayResponse };
let actualGatewayHandler: (request: Row, response: GatewayResponse) => Promise<unknown>;
const requests: URL[] = [];
function matches(row: unknown, filter: unknown): boolean {
  if (!filter || typeof filter !== "object") return true;
  return Object.entries(filter).every(([key, term]) => {
    if (key === "_and") return (term as unknown[]).every(value => matches(row, value));
    if (key === "_or") return (term as unknown[]).some(value => matches(row, value));
    if (key === "_eq") return row === term;
    if (key === "_null") return (row == null) === term;
    if (key === "_nnull") return (row != null) === term;
    if (key === "_lte") return typeof row === "string" && Number.isFinite(Date.parse(row)) && new Date(row).toISOString() === row && Date.parse(row) <= Date.parse(String(term));
    return matches((row as Row)?.[key], term);
  });
}
const server = createServer((request, response) => {
  const url = new URL(request.url!, "http://synthetic.invalid");
  requests.push(url);
  response.setHeader("content-type", "application/json");
  if (unavailable) { response.statusCode = 503; return response.end(JSON.stringify({ errors: [{ message: "private upstream diagnostic" }] })); }
  const collection = /\/items\/([a-z_]+)$/.exec(url.pathname)?.[1];
  if (useActualGateway) {
    const reply: GatewayResponse = {
      status(code) { response.statusCode = code; return this; },
      set(headers) { for (const [key, value] of Object.entries(headers)) response.setHeader(key, value); return this; },
      json(body) { response.end(JSON.stringify(body)); return this; },
    };
    void actualGatewayHandler({ accountability: { user: "12345678-1234-1234-1234-123456789abc", admin: false }, params: { collection }, query: Object.fromEntries(url.searchParams) }, reply).catch(() => reply.status(500).json({ error: "Fixture gateway failure" }));
    return;
  }
  const source = collection === "articles" ? rows : catalogRows[collection ?? ""] ?? [];
  const filtered = ignoreFilter ? source : source.filter(row => matches(row, JSON.parse(url.searchParams.get("filter") ?? "{}")));
  const sorted = [...filtered].sort((a, b) => String(b.published_at).localeCompare(String(a.published_at)) || String(a.slug).localeCompare(String(b.slug)));
  const limit = Number(url.searchParams.get("limit"));
  const start = ignorePaging ? 0 : (Number(url.searchParams.get("page") ?? 1) - 1) * limit;
  const fields = (url.searchParams.get("fields") ?? "").split(",");
  response.end(JSON.stringify({ data: sorted.slice(start, start + limit).map(row => Object.fromEntries(fields.filter(field => field in row).map(field => [field, row[field]]))), meta: { filter_count: filtered.length } }));
});
let articles: typeof import("../lib/articles");
let renderer: typeof import("../components/articles/ArticleContent");
let restoreEnv: (() => void) | undefined;

before(async () => {
  // Exercise the real gateway request contract, with an in-memory virtual dataset
  // so the 1M offset boundary needs no database or million-row fixture allocation.
  const cmsRoot = process.env.CMS_SOURCE_ROOT;
  if (!cmsRoot) throw new Error("Set CMS_SOURCE_ROOT to a Universal Commerce CMS checkout.");
  const gatewaySource = pathToFileURL(resolve(cmsRoot, "directus", "extensions", "commerce-api", "src", "storefront.mjs")).href;
  const { registerStorefrontGateway } = await import(gatewaySource);
  class ItemsService {
    async readByQuery(query: { aggregate?: unknown; fields?: string[]; page?: number; limit: number; filter?: unknown }) {
      if (query.aggregate) return [{ count: { id: virtualArticleTotal } }];
      const start = ((query.page ?? 1) - 1) * query.limit;
      return Array.from({ length: Math.max(0, Math.min(query.limit, virtualArticleTotal - start)) }, (_, index) => ({ id: `article-${start + index}`, slug: `article-${start + index}`, status: "published", title: `Синтетическая статья ${start + index}`, published_at: "2020-01-01T12:00:00.000Z" })).filter(row => matches(row, query.filter)).map(row => Object.fromEntries((query.fields ?? []).filter(field => field in row).map(field => [field, (row as Row)[field]])));
    }
  }
  registerStorefrontGateway({ get(path: string, handler: typeof actualGatewayHandler) { if (path === "/storefront/items/:collection") actualGatewayHandler = handler; }, post() {} }, { env: { COMMERCE_STOREFRONT_ENABLED: "true", COMMERCE_STOREFRONT_USER_ID: "12345678-1234-1234-1234-123456789abc", COMMERCE_STOREFRONT_PUBLIC_FOLDER_ID: cover }, services: { ItemsService, AssetsService: class {} }, getSchema: async () => ({ collections: { articles: { primary: "id" } } }), database: {} });
  server.listen(0, "127.0.0.1"); await once(server, "listening");
  const address = server.address(); if (!address || typeof address === "string") throw new Error("No fixture port");
  restoreEnv = setFixtureEnv({
    DIRECTUS_URL: `http://127.0.0.1:${address.port}`,
    DIRECTUS_TOKEN: undefined,
    STOREFRONT_MOCK_MODE: "false",
    STOREFRONT_ALLOW_MOCK_FALLBACK: "true",
    STOREFRONT_DIRECTUS_GATEWAY: "true",
    NEXT_PUBLIC_SITE_URL: "https://synthetic.example",
  });
  [articles, renderer] = await Promise.all([import("../lib/articles"), import("../components/articles/ArticleContent")]);
});
after(async () => {
  try {
    if (server.listening) await new Promise<void>((resolve, reject) => server.close(error => error ? reject(error) : resolve()));
  } finally {
    restoreEnv?.();
  }
});
beforeEach(() => {
  unavailable = false; ignoreFilter = false; ignorePaging = false; requests.length = 0;
  catalogRows = {}; useActualGateway = false; virtualArticleTotal = 0;
  const published = { id: "one", status: "published", slug: "one", title: "Первый материал", excerpt: "Краткий анонс", content: "<h2>Один</h2><p>Текст</p>", published_at: "2020-01-02T12:00:00.000Z", cover_image: cover, image_alt: "Содержательная иллюстрация", author: "Указанный автор" };
  rows = [published, { ...published, id: "two", slug: "two", title: "Второй материал", published_at: "2020-01-01T12:00:00.000Z" }, { ...published, id: "draft", slug: "draft", status: "draft" }, { ...published, id: "future", slug: "future", published_at: "2999-01-01T12:00:00.000Z" }, { ...published, id: "invalid", slug: "invalid", published_at: "2020-02-30T12:00:00.000Z" }];
});

describe("published article routes over a synthetic HTTP service", () => {
  it("paginates published materials and uses explicit bounded queries", async () => {
    const first = await articles.getPublishedArticles({ limit: 1 });
    const next = await articles.getPublishedArticles({ page: 2, limit: 1 });
    assert.deepEqual(first.items.map(row => row.slug), ["one"]);
    assert.deepEqual(next.items.map(row => row.slug), ["two"]);
    const result = await articles.getPublishedArticles({ limit: 999 });
    assert.equal(result.limit, 24);
    assert.deepEqual(result.items.map(row => row.slug), ["one", "two"]);
    assert.equal(requests.every(url => !url.searchParams.get("fields")?.includes("*")), true);
  });
  it("rejects private, malformed and future rows even if upstream ignores filters", async () => {
    ignoreFilter = true;
    const list = await articles.getPublishedArticles();
    assert.deepEqual(list.items.map(row => row.slug), ["one", "two"]);
    assert.equal(list.total, 2);
    assert.equal(await articles.getPublishedArticle("draft"), null);
    assert.equal(await articles.getPublishedArticle("future"), null);
    assert.equal(await articles.getPublishedArticle("../one"), null);
    assert.deepEqual((await articles.getArticlesForSitemap()).map(row => row.slug), ["one", "two"]);
  });
  it("renders the list, safe detail metadata and published-only sitemap with persistent dates", async () => {
    const { default: List } = await import("../app/articles/page");
    const html = renderToStaticMarkup(await List({ searchParams: Promise.resolve({}) }));
    assert.ok(html?.includes('href="/articles/one"')); assert.ok(!html?.includes('/articles/draft'));
    const { default: Detail, generateMetadata } = await import("../app/articles/[slug]/page");
    const props = { params: Promise.resolve({ slug: "one" }) };
    const { title, description, alternates, openGraph } = await generateMetadata(props);
    assert.equal(title, "Первый материал");
    assert.equal(description, "Краткий анонс");
    assert.equal(alternates?.canonical, "https://synthetic.example/articles/one");
    assert.ok(openGraph && "type" in openGraph && openGraph.type === "article");
    assert.equal(openGraph.publishedTime, "2020-01-02T12:00:00.000Z");
    assert.equal(openGraph.url, "https://synthetic.example/articles/one");
    const detail = renderToStaticMarkup(await Detail(props));
    assert.equal(detail.match(/<h1[ >]/g)?.length, 1); assert.ok(detail?.includes('"datePublished":"2020-01-02T12:00:00.000Z"')); assert.ok(!detail?.includes('dateModified'));
    assert.ok(detail?.includes("Второй материал"));
    assert.equal(requests.some(url => url.pathname.endsWith("/items/articles") && url.searchParams.get("limit") === "2"), true);
    const { GET } = await import("../app/sitemaps/[name]/route");
    const map = await GET(new Request("https://synthetic.example/sitemaps/static.xml"), { params: Promise.resolve({ name: "static.xml" }) });
    const xml = await map.text(); assert.ok(xml?.includes('/articles/one</loc>')); assert.ok(!xml?.includes('/articles/draft')); assert.ok(!xml?.includes('/articles/future'));
    assert.equal(map.headers.get("cache-control"), "no-store");
  });
  it("fails closed on live errors even when catalog fallback is enabled and hides empty home section", async () => {
    unavailable = true;
    assert.equal(await articles.getPublishedArticle("one"), null);
    assert.deepEqual((await articles.getPublishedArticles()).items, []);
    assert.deepEqual(await articles.getArticlesForSitemap(), []);
    const { ArticlesSection } = await import("../components/articles/ArticlesSection");
    assert.equal(await ArticlesSection(), null);
    const { default: List } = await import("../app/articles/page");
    assert.ok(renderToStaticMarkup(await List({ searchParams: Promise.resolve({}) }))?.includes("Опубликованных материалов пока нет"));
  });
  it("rechecks publication on subsequent reads and resolves only known public catalog links", async () => {
    rows[0].related_categories = ["known-category", "missing", { slug: "javascript:alert(1)" }];
    rows[0].related_products = ["known-product", "draft-product"];
    catalogRows = { categories: [{ id: "known-category", slug: "known-category", title: "Категория", status: "published" }], products: [{ id: "known-product", slug: "known-product", title: "Товар", status: "published", category: { status: "published" } }, { id: "draft-product", slug: "draft-product", title: "Черновик товара", status: "draft" }] };
    const article = await articles.getPublishedArticle("one");
    assert.notEqual(article, null);
    assert.deepEqual(await articles.getArticleRelatedLinks(article!), [{ title: "Категория", href: "/category/known-category" }, { title: "Товар", href: "/product/known-product" }]);
    rows[0].status = "draft";
    assert.equal(await articles.getPublishedArticle("one"), null);
    assert.deepEqual((await articles.getArticlesForSitemap()).map(row => row.slug), ["two"]);
  });
  it("includes publications beyond the first 500 and fails closed if upstream pagination does not progress", async () => {
    rows = Array.from({ length: 501 }, (_, index) => ({ ...rows[0], id: `article-${index}`, slug: `article-${String(index).padStart(4, "0")}` }));
    const all = await articles.getArticlesForSitemap();
    assert.equal(all?.length, 501);
    assert.equal(new Set(all.map(row => row.slug)).size, 501);
    assert.equal(all.some(row => row.slug === "article-0500"), true);
    ignorePaging = true;
    assert.deepEqual(await articles.getArticlesForSitemap(), []);
  });
  it("keeps list links, metadata and adapter pages within the real gateway's bounded article window", async () => {
    useActualGateway = true; virtualArticleTotal = 12_001;
    const { default: List, generateMetadata } = await import("../app/articles/page");
    const first = renderToStaticMarkup(await List({ searchParams: Promise.resolve({ page: "1000" }) }));
    assert.ok(first?.includes('href="/articles?page=1001"'));
    const next = await articles.getPublishedArticles({ page: 1001, limit: 12 });
    assert.deepEqual(next.items.map(row => row.id), ["article-12000"]);
    const following = renderToStaticMarkup(await List({ searchParams: Promise.resolve({ page: "1001" }) }));
    assert.ok(following?.includes('href="/articles/article-12000"'));
    assert.ok(!following?.includes('rel="next"'));
    virtualArticleTotal = 1_000_020;
    const oversized = await articles.getPublishedArticles({ page: 9_999_999, limit: 12 });
    assert.equal(oversized.page, 83_334);
    assert.equal(oversized.items[0]?.id, "article-999996");
    const last = renderToStaticMarkup(await List({ searchParams: Promise.resolve({ page: "9999999" }) }));
    assert.ok(last?.includes('href="/articles/article-999996"'));
    assert.ok(!last?.includes('rel="next"'));
    const metadata = await generateMetadata({ searchParams: Promise.resolve({ page: "9999999" }) });
    assert.equal(metadata.alternates?.canonical, "https://synthetic.example/articles?page=83334");
    const last24 = await articles.getPublishedArticles({ page: 9_999_999, limit: 24 });
    assert.equal(last24.page, 41_667);
    assert.equal(last24.items[0]?.id, "article-999984");
  });
});

describe("allowlisted article HTML rendered through React", () => {
  it("preserves readable entities, stable TOC, lists/tables and drops executable elements/attributes/URLs", () => {
    const html = '<h1>Вложенный заголовок</h1><h2 id="evil">Раздел &amp; данные</h2><h2>Раздел &amp; данные</h2><h2>Итог</h2><p onclick="alert(1)">Текст &lt;script&gt; <a href="jav&#x61;script:alert(1)">ссылка</a></p><script>alert(1)</script><style>body{display:none}</style><svg><a href="javascript:alert(2)">bad</a></svg><ul><li>Пункт</li></ul><table><tr><th>Номер</th><td>1</td></tr></table><img src="/assets/' + cover + '" alt="Деталь" onerror="alert(1)"><img src="/api/assets/' + privateImage + '"><img src="https://tracker.invalid/pixel"><a href="//evil.invalid">bad</a>';
    const parsed = renderer.prepareArticleContent(html, [cover]);
    const output = renderToStaticMarkup(createElement(renderer.ArticleContent, { prepared: parsed }));
    assert.doesNotMatch(output, /<h1|<script|<style|<svg|onclick|onerror|javascript:|tracker.invalid|evil.invalid/);
    assert.ok(output?.includes('Раздел &amp; данные')); assert.ok(output?.includes('<table>')); assert.ok(output?.includes('<li>Пункт</li>'));
    assert.ok(output?.includes(`/api/assets/${cover}`)); assert.ok(!output?.includes(privateImage));
    assert.deepEqual(parsed.headings.map(row => row.id), ['section-1', 'section-2', 'section-3', 'section-4']);
    assert.deepEqual(renderer.prepareArticleContent(html, [cover]).headings, parsed.headings);
  });
  it("handles malformed unknown HTML as escaped text and bounds untrusted input", () => {
    const parsed = renderer.prepareArticleContent('<unknown><p>safe &quot;text&quot;<b>bold</unknown><iframe src="https://evil.invalid"><p>private</iframe><a href="/catalog">Каталог</a>', []);
    const output = renderToStaticMarkup(createElement(renderer.ArticleContent, { prepared: parsed }));
    assert.ok(output?.includes('safe')); assert.ok(output?.includes('href="/catalog"')); assert.ok(!output?.includes('iframe')); assert.ok(!output?.includes('private'));
    const tooLarge = renderer.prepareArticleContent('x'.repeat(250_001), []);
    assert.equal(tooLarge.truncated, true);
    assert.ok(renderToStaticMarkup(createElement(renderer.ArticleContent, { prepared: tooLarge })).length < 251_000);
    const tooDeep = renderer.prepareArticleContent('<div>'.repeat(100) + '<p>deep</p>' + '</div>'.repeat(100), []);
    assert.equal(tooDeep.truncated, true);
    assert.ok(!renderToStaticMarkup(createElement(renderer.ArticleContent, { prepared: tooDeep }))?.includes('deep'));
    const tooMany = renderer.prepareArticleContent('<p>item</p>'.repeat(6_000), []);
    assert.equal(tooMany.truncated, true);
    assert.ok(renderToStaticMarkup(createElement(renderer.ArticleContent, { prepared: tooMany })).match(/<p>/g)!.length < 5_001);
  });
});
