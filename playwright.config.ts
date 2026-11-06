import { defineConfig } from "@playwright/test";

export default defineConfig({
  testDir: "./e2e",
  workers: 1,
  retries: 0,
  reporter: "list",
  use: {
    baseURL: "http://127.0.0.1:3137",
    browserName: "chromium",
    viewport: { width: 1440, height: 900 },
    trace: "retain-on-failure",
    screenshot: "only-on-failure",
  },
  webServer: {
    command: "npm run start -- --hostname 127.0.0.1 --port 3137",
    url: "http://127.0.0.1:3137",
    timeout: 120_000,
    reuseExistingServer: false,
    env: {
      STOREFRONT_MOCK_MODE: "true",
      NEXT_PUBLIC_SITE_URL: "http://127.0.0.1:3137",
    },
  },
});
