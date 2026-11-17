import assert from "node:assert/strict";
import { after, before, beforeEach, describe, it, mock } from "node:test";
import { renderToStaticMarkup } from "react-dom/server";
import type { Product, ProductRelation } from "../lib/types";
import { formatProductPrice } from "../lib/format";

function item(id: string): Product {
  return { id, slug: id, title: `Тестовая деталь ${id}`, brand: "Perkins", sku: id, price_status: "on_request", availability_status: "on_request" };
}

const environment = {
  DIRECTUS_URL: "https://catalog.synthetic.example",
  DIRECTUS_TOKEN: "synthetic-product-test",
  STOREFRONT_MOCK_MODE: "false",
  STOREFRONT_ALLOW_MOCK_FALLBACK: "false",
  STOREFRONT_DIRECTUS_GATEWAY: "false",
  NEXT_PUBLIC_SITE_URL: "https://synthetic.example",
};
const previousEnvironment = new Map(Object.keys(environment).map(key => [key, process.env[key]]));
type ProductFilter = { slug?: { _eq: string }; main_image?: { _null?: boolean } };
let product: Product;
let related: Product[];
let relations: ProductRelation[];
let page: typeof import("../app/product/[slug]/page");
const props = { params: Promise.resolve({ slug: "current" }) };

before(async () => {
  Object.assign(process.env, environment);
  mock.method(globalThis, "fetch", async (input: RequestInfo | URL) => {
    const url = new URL(input instanceof Request ? input.url : input);
    assert.equal(url.origin, environment.DIRECTUS_URL);
    switch (url.pathname) {
      case "/items/products": {
        const { _and: filter } = JSON.parse(url.searchParams.get("filter") ?? "{}") as { _and: ProductFilter[] };
        const slug = filter.find(term => term.slug)?.slug?._eq;
        if (slug !== undefined) return Response.json({ data: slug === product.slug ? [product] : [] });
        const rows = filter.some(term => term.main_image?._null === true) ? related : [];
        const offset = Number(url.searchParams.get("offset") ?? 0);
        const limit = Number(url.searchParams.get("limit"));
        return Response.json({ data: rows.slice(offset, offset + limit), meta: { filter_count: rows.length } });
      }
      case "/items/products_analogs":
        return Response.json({ data: relations.map(relation => ({
          relation_type: relation.relation_type,
          product_from: product,
          product_to: relation.product,
        })) });
      case "/items/product_codes":
      case "/items/product_images":
      case "/items/product_documents":
      case "/items/product_specifications":
        return Response.json({ data: [] });
      default:
        throw new Error(`Unexpected catalog request ${url.pathname}`);
    }
  });
  page = await import("../app/product/[slug]/page");
});

after(() => {
  mock.restoreAll();
  for (const [key, value] of previousEnvironment) {
    if (value === undefined) delete process.env[key];
    else process.env[key] = value;
  }
});

beforeEach(() => {
  product = { ...item("current"), title: "Фильтр", sku: "2654403" };
  related = [];
  relations = [];
});

describe("product price formatting", () => {
  it("preserves each currency's fractional minor units and keeps integer rubles compact", () => {
    assert.equal(formatProductPrice(12.5, "RUB"), "12,50\u00a0₽");
    assert.equal(formatProductPrice(12.5, "USD"), "12,50\u00a0$");
    assert.equal(formatProductPrice(1.234, "KWD"), "1,234\u00a0KWD");
    assert.equal(formatProductPrice(12, "RUB"), "12\u00a0₽");
  });

  it("normalizes valid codes, defaults missing currency, and rejects malformed nonempty currency", () => {
    assert.equal(formatProductPrice(12.5, " usd "), "12,50\u00a0$");
    assert.equal(formatProductPrice(12.5, undefined), "12,50\u00a0₽");
    assert.equal(formatProductPrice(12.5, "invalid"), "Цена по запросу");
    assert.equal(formatProductPrice(null, "RUB"), "Цена по запросу");
  });
});

describe("rendered product offers", () => {
  it("suppresses price and Offer for malformed nonempty currencies", async () => {
    product.price_status = "fixed";
    product.price = 12.5;
    product.currency = "invalid";
    const html = renderToStaticMarkup(await page.default(props));
    assert.ok(html.includes("Цена по запросу"));
    assert.ok(!html.includes('"offers"'));
  });
});

describe("rendered product identity and typed links", () => {
  it("keeps the real SKU when the title contains a different number with the same prefix", async () => {
    product.title = "Фильтр 26544030";
    const heading = "Фильтр 26544030 Perkins 2654403";
    const metadata = await page.generateMetadata(props);
    assert.equal(metadata.title, heading);
    assert.equal(metadata.openGraph?.title, heading);
    const html = renderToStaticMarkup(await page.default(props));
    assert.ok(html.includes(`<h1>${heading}</h1>`));
    assert.ok(html.includes(`"name":"${heading}"`));
    assert.ok(!html.includes('"offers"'));
  });

  it("recognizes the complete article with equivalent hyphen spelling without appending it twice", async () => {
    product.title = "Фильтр Perkins 1R1808";
    product.sku = "1R-1808";
    assert.equal((await page.generateMetadata(props)).title, product.title);
    assert.ok(renderToStaticMarkup(await page.default(props)).includes(`<h1>${product.title}</h1>`));
  });

  it("shows every distinct relation meaning next to one card per position and excludes them from related", async () => {
    const analog = item("analog"), linked = item("linked"), other = item("other");
    relations = [
      { product: analog, relation_type: "superseded_by" },
      { product: analog, relation_type: "analog" },
      { product: analog, relation_type: "analog" },
      { product: linked, relation_type: "compatible" },
      { product: linked, relation_type: "oem_cross" },
      { product, relation_type: "analog" },
    ];
    related = [analog, linked, product, other, other];
    const html = renderToStaticMarkup(await page.default(props));
    for (const label of ["Аналог", "Связь замены", "Связь применяемости", "Перекрёстная ссылка OEM"]) {
      assert.ok(html.includes(`>${label}</span>`), label);
    }
    for (const id of ["analog", "linked", "other"]) {
      assert.equal(html.match(new RegExp(`class="product-title" href="/product/${id}"`, "g"))?.length, 1, id);
    }
    assert.ok(!html.includes('class="product-title" href="/product/current"'));
  });
});
