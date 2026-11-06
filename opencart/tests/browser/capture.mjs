import assert from "node:assert/strict";
import { mkdir } from "node:fs/promises";
import { createRequire } from "node:module";
import { join, resolve } from "node:path";

const base = new URL(process.env.CMTEH_BROWSER_BASE || "http://127.0.0.1:3180");
assert.ok(base.protocol === "http:" && ["localhost", "127.0.0.1", "[::1]"].includes(base.hostname) && !base.username && !base.password,
  "Capture only the isolated local HTTP site.");
assert.ok(process.env.CMTEH_BROWSER_EVIDENCE, "Set CMTEH_BROWSER_EVIDENCE to an output directory outside the repository.");
const output = resolve(process.env.CMTEH_BROWSER_EVIDENCE);
const tooling = process.env.CMTEH_BROWSER_TOOLING;
const require = tooling ? createRequire(resolve(tooling, "package.json")) : createRequire(import.meta.url);
const { chromium } = require("playwright");
const routes = {
  home: "/",
  catalog: "/catalog",
  product: "/product/jd-re568158",
  request: "/request",
  brands: "/brands",
  articles: "/articles",
};
await mkdir(output, { recursive: true });
const browser = await chromium.launch();
try {
  for (const width of [1440, 1024, 390]) {
    const page = await browser.newPage({ viewport: { width, height: 900 }, reducedMotion: "reduce" });
    const pageErrors = [];
    page.on("pageerror", (error) => pageErrors.push(error.message));
    for (const [name, route] of Object.entries(routes)) {
      const response = await page.goto(new URL(route, base).href, { waitUntil: "networkidle" });
      assert.equal(response.status(), 200, route);
      await page.evaluate(() => document.fonts.ready);
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true,
        "Horizontal overflow at " + width + " px: " + route);
      await page.screenshot({ path: join(output, "candidate-" + name + "-" + width + ".png"), animations: "disabled", fullPage: true });
    }
    assert.deepEqual(pageErrors, []);
    await page.close();
  }
  console.log("PASS 18 viewport captures with no page errors or horizontal overflow");
} finally {
  await browser.close();
}
