import assert from "node:assert/strict";
import { describe, it } from "node:test";
import type { Product, RequestItem } from "@/lib/types";
import { cartLineTotal, cartUnitPrice, resolveCartProduct } from "../components/cart-pricing";

const item: RequestItem = { article: "ab-12", brand: "Acme", title: "Old title", quantity: 3 };
const product = (overrides: Partial<Product> = {}): Product => ({
  id: "1", slug: "part", title: "Current title", sku: "AB 12", brand: "ACME", price: 12.345,
  currency: "USD", price_status: "fixed", availability_status: "in_stock", ...overrides,
});

describe("cart pricing", () => {
  it("matches normalized SKU and brand, without trusting stored title", () => {
    assert.equal(resolveCartProduct(item, [product()])?.title, "Current title");
    assert.equal(resolveCartProduct(item, [product({ brand: "Other" })]), null);
  });

  it("accepts a blank stored brand only when the SKU is unique", () => {
    const blankBrand = { ...item, brand: "Не указан" };
    assert.notEqual(resolveCartProduct(blankBrand, [product()]), null);
    assert.equal(resolveCartProduct(blankBrand, [product(), product({ id: "2", brand: "Other" })]), null);
  });

  it("does not infer uniqueness from a truncated search result", () => {
    assert.equal(resolveCartProduct(item, [product()], 11), null);
  });

  it("does not offer unavailable prices and rounds line totals by currency", () => {
    assert.equal(cartUnitPrice(product({ price_status: "hidden" })), null);
    assert.equal(cartUnitPrice(product({ price: 0 }))?.amount, 0);
    assert.equal(cartUnitPrice(product({ availability_status: "on_request" }))?.amount, 12.35);
    const unit = cartUnitPrice(product());
    assert.equal(unit && cartLineTotal(unit, 3), 37.05);
  });

  it("normalizes valid currency codes and rejects malformed nonempty currency", () => {
    assert.equal(cartUnitPrice(product({ currency: " usd " }))?.currency, "USD");
    assert.equal(cartUnitPrice(product({ currency: undefined }))?.currency, "RUB");
    assert.equal(cartUnitPrice(product({ currency: "US dollars" })), null);
  });
});
