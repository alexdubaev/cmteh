import assert from "node:assert/strict";
import { afterEach, beforeEach, describe, it } from "node:test";

import { MAX_REQUEST_QUANTITY, mergeRequestItems, readRequestItemCount, readRequestItems, writeRequestItems } from "../lib/request-store";
import type { RequestItem } from "../lib/types";

const item = (quantity: number): RequestItem => ({ article: "SKU", title: "Part", brand: "Brand", quantity });
const originals = (["window", "localStorage"] as const)
  .map((name) => [name, Object.getOwnPropertyDescriptor(globalThis, name)] as const);

function useStorage(storage: Pick<Storage, "getItem" | "setItem">) {
  Object.defineProperty(globalThis, "localStorage", { configurable: true, value: storage });
}

describe("request quantity bounds", () => {
  let storedItems: string;
  let writtenItems: string | undefined;

  beforeEach(() => {
    storedItems = "[]";
    writtenItems = undefined;
    Object.defineProperty(globalThis, "window", { configurable: true, value: { dispatchEvent: () => true } });
    useStorage({ getItem: () => storedItems, setItem: (_, value) => { writtenItems = value; } });
  });

  afterEach(() => {
    try {
      useStorage({ getItem: () => "[]", setItem: () => {} });
      readRequestItems();
    } finally {
      for (const [name, descriptor] of originals) {
        if (descriptor) Object.defineProperty(globalThis, name, descriptor);
        else Reflect.deleteProperty(globalThis, name);
      }
    }
  });

  it("normalizes stored quantities to finite integers from 1 through the maximum", () => {
    assert.equal(MAX_REQUEST_QUANTITY, 100000);
    storedItems = JSON.stringify([
      { ...item(1), quantity: 1.8 }, { ...item(2), article: "B", quantity: 100001 },
      { ...item(3), article: "C", quantity: -2 }, { ...item(4), article: "D", quantity: Infinity },
    ]);
    assert.deepEqual(readRequestItems().map(({ quantity }) => quantity), [1, MAX_REQUEST_QUANTITY, 1, 1]);
  });

  it("bounds merge inputs and serializes only normalized update values", () => {
    const merged = mergeRequestItems([item(99999)], [item(50.7)]);
    assert.equal(merged[0].quantity, MAX_REQUEST_QUANTITY);
    writeRequestItems([item(NaN)]);
    assert.ok(writtenItems !== undefined);
    assert.equal(JSON.parse(writtenItems)[0].quantity, 1);
  });

  it("retains selections across reads and later additions when local storage is blocked", () => {
    useStorage({
      getItem: () => { throw new Error("Storage denied"); },
      setItem: () => { throw new Error("Storage denied"); },
    });
    writeRequestItems([item(2)]);
    const next = mergeRequestItems(readRequestItems(), [{ ...item(3), article: "NEXT" }]);
    writeRequestItems(next);

    assert.deepEqual(readRequestItems(), [item(2), { ...item(3), article: "NEXT" }]);
    assert.equal(readRequestItemCount(), 5);
  });

  it("does not carry blocked-storage selections into a fresh storage scope", () => {
    assert.deepEqual(readRequestItems(), []);
    assert.equal(readRequestItemCount(), 0);
  });
});
