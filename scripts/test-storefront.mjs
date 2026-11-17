import { spawnSync } from "node:child_process";
import { readdirSync } from "node:fs";
import { join } from "node:path";
import { fileURLToPath } from "node:url";

const root = fileURLToPath(new URL("../", import.meta.url));
const directory = join(root, "tests");
const files = readdirSync(directory, { recursive: true })
  .filter((name) => name.endsWith(".test.ts"))
  .sort()
  .map((name) => join(directory, name));
if (files.length === 0) throw new Error("No storefront tests found.");

// Pass paths explicitly for TypeScript discovery on Node 20 and Windows.
const result = spawnSync(process.execPath, ["--import", "tsx", "--test", ...files], {
  cwd: root,
  env: { ...process.env, NODE_ENV: "test", TSX_TSCONFIG_PATH: join(directory, "tsconfig.json") },
  stdio: "inherit",
});
if (result.error) throw result.error;
process.exitCode = result.status ?? 1;
