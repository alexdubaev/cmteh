import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { resolve, join } from 'node:path';
import { readFile, mkdir } from 'node:fs/promises';
const base = new URL(process.env.CMTEH_BROWSER_BASE);
assert.ok(base.protocol === 'http:' && ['127.0.0.1','localhost'].includes(base.hostname), 'Local fixture only');
const require = createRequire(resolve(process.env.CMTEH_BROWSER_TOOLING || 'D:/codex/cmteh', 'package.json'));
const { chromium } = require('playwright');
const browser = await chromium.launch();
try {
  const context = await browser.newContext({ baseURL: base.href });
  const page = await context.newPage();
  await page.goto('/admin/');
  await page.locator('[name=username]').fill(process.env.CMTEH_ADMIN_USERNAME || 'cmteh-admin');
  await page.locator('[name=password]').fill((await readFile(process.env.CMTEH_ADMIN_PASSWORD_FILE,'utf8')).trim());
  await page.locator('#form-login button[type=submit]').click();
  await page.waitForURL(url => url.searchParams.get('route') === 'common/dashboard');
  assert.equal(await page.locator('#security').count(),1,'Native security block preserved');
  if (await page.locator('#modal-security').isVisible()) await page.locator('#modal-security [data-bs-dismiss="modal"]').first().click();
  for (const [width,height] of [[1440,1000],[390,844]]) {
    await page.setViewportSize({width,height});
    assert.equal(await page.locator('#header .navbar-brand').innerText(),'СМ ТЕХНО');
    assert.equal(await page.locator('h1').innerText(),'Рабочий стол СМ ТЕХНО');
    assert.ok(await page.locator('#header img').evaluateAll(images=>images.every(image=>image.complete && image.naturalWidth>0)),'Header contains a broken image');
    if (width===390) {
      await page.waitForFunction(()=>document.querySelector('#column-left').getBoundingClientRect().right<=1 && document.querySelector('#content').getBoundingClientRect().left<=1);
      assert.ok(await page.evaluate(()=>document.querySelector('#header').getBoundingClientRect().height<=64),'Mobile header wraps into multiple rows');
      if (process.env.CMTEH_BROWSER_EVIDENCE) await page.screenshot({path:join(process.env.CMTEH_BROWSER_EVIDENCE,'admin-shell-390-closed.png')});
    }

    if (width===390) {
      await page.locator('#button-menu').click();
      await page.waitForFunction(()=>document.querySelector('#column-left').getBoundingClientRect().x>=0);
      assert.ok(await page.locator('#column-left a').first().isVisible());
      await page.locator('#menu-catalog > a').click();
      await page.locator('#menu-catalog > ul.show').waitFor();
      assert.ok(await page.evaluate(()=>document.elementFromPoint(220,150)?.closest('#column-left')),'Content paints above open sidebar');
    }
    await page.locator('#nav-language > a').click();
    assert.ok(await page.locator('#nav-language .dropdown-menu').isVisible(),'Native language selector must still open');
    await page.locator('#nav-language > a').click();
    assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Shell overflows viewport');
    if (process.env.CMTEH_BROWSER_EVIDENCE) {
      await mkdir(process.env.CMTEH_BROWSER_EVIDENCE,{recursive:true});
      await page.screenshot({path:join(process.env.CMTEH_BROWSER_EVIDENCE,`admin-shell-${width}.png`)});
    }
  }
  console.log('PASS native admin desktop/mobile brand, dashboard, security, sidebar interaction and viewport');
} catch (error) {
  // Browser exception text can contain an authenticated URL; never emit it.
  throw new Error(String(error?.message || error).replace(/user_token=[^&\s"'<>]+/g,'user_token=[REDACTED]'));
} finally { await browser.close(); }
