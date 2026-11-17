import assert from "node:assert/strict";
import { describe, it } from "node:test";
import { getSiteUrl } from "../lib/seo";
import { setFixtureEnv } from "./fixture-env";

describe("site URL configuration", () => {
  it("uses localhost only when explicit mock mode has no configured URL", (t) => {
    t.after(setFixtureEnv({ STOREFRONT_MOCK_MODE: "true", NEXT_PUBLIC_SITE_URL: "" }));
    assert.equal(getSiteUrl().origin, "http://localhost:3000");
  });

  it("fails clearly for a malformed configured live URL", (t) => {
    t.after(setFixtureEnv({ STOREFRONT_MOCK_MODE: "false", NEXT_PUBLIC_SITE_URL: "https://example.com/not-a-site-root" }));
    assert.throws(getSiteUrl, /NEXT_PUBLIC_SITE_URL/);
  });

  for (const siteUrl of ["https:example.com", "https:/example.com", "https:\\example.com", " https://example.com"]) {
    it(`rejects non-canonical origin syntax ${siteUrl}`, (t) => {
      t.after(setFixtureEnv({ STOREFRONT_MOCK_MODE: "false", NEXT_PUBLIC_SITE_URL: siteUrl }));
      assert.throws(getSiteUrl, /NEXT_PUBLIC_SITE_URL/);
    });
  }

  it("fails clearly when live mode has no configured site URL", (t) => {
    t.after(setFixtureEnv({ STOREFRONT_MOCK_MODE: "false", NEXT_PUBLIC_SITE_URL: "" }));
    assert.throws(getSiteUrl, /NEXT_PUBLIC_SITE_URL/);
  });

  it("rejects localhost as a live canonical origin", (t) => {
    t.after(setFixtureEnv({ STOREFRONT_MOCK_MODE: "false", NEXT_PUBLIC_SITE_URL: "http://localhost:3000" }));
    assert.throws(getSiteUrl, /NEXT_PUBLIC_SITE_URL/);
  });
});
