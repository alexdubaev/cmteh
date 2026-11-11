import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {resolve} from 'node:path';
import {readFile,writeFile,unlink} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {randomUUID} from 'node:crypto';

const base=new URL(process.env.CMTEH_BROWSER_BASE);
assert.ok(base.protocol==='http:'&&['127.0.0.1','localhost'].includes(base.hostname),'Local test browser only');
const require=createRequire(resolve(process.env.CMTEH_BROWSER_TOOLING,'package.json'));
const {chromium}=require('playwright');
const browser=await chromium.launch();
async function login(username,passwordFile){
  const context=await browser.newContext({baseURL:base.href}); const page=await context.newPage();
  await page.goto('/admin/'); await page.locator('[name=username]').fill(username);
  await page.locator('[name=password]').fill((await readFile(passwordFile,'utf8')).trim());
  await page.locator('#form-login button[type=submit]').click();
  await page.waitForURL(url=>url.searchParams.get('route')==='common/dashboard');
  if(await page.locator('#modal-security').isVisible()) await page.locator('#modal-security [data-bs-dismiss="modal"]').first().click();
  const token=new URL(page.url()).searchParams.get('user_token');
  return {context,page,token};
}
try {
  const writer=await login(process.env.CMTEH_ADMIN_USERNAME,process.env.CMTEH_ADMIN_PASSWORD_FILE);
  const inventoryRoute='/admin/index.php?'+new URLSearchParams({route:'extension/cmteh/module/cmteh_catalog.inventory',user_token:writer.token});
  const catalogResponse=await writer.page.goto('/admin/index.php?'+new URLSearchParams({route:'extension/cmteh/module/cmteh_catalog',user_token:writer.token}));
  const catalogLinkCount=await writer.page.getByRole('link',{name:'Склады и предложения'}).count();
  assert.equal(catalogLinkCount,1,`Catalog toolbar link must be present (HTTP ${catalogResponse?.status()}, title ${await writer.page.locator('h1').innerText().catch(()=>'(missing)')})`);
  await writer.page.goto(inventoryRoute);
  assert.equal(await writer.page.locator('h1').innerText(),'Склады и предложения');
  assert.equal(await writer.page.locator('[data-inventory-import]').count(),1);
  await writer.page.getByRole('button',{name:'Проверить и показать preview'}).waitFor();
  assert.equal(await writer.page.locator('#publish-offers').count(),1);
  assert.equal(await writer.page.locator('input[name=import_file][accept=".csv,.xlsx"]').count(),1);
  const csv=join(tmpdir(),`inventory-admin-${randomUUID()}.csv`);
  await writeFile(csv,'article;warehouse_code;quantity;price;delivery_term\nMISSING-SKU;NO-SUCH-WAREHOUSE;1;;\n','utf8');
  try {
    await writer.page.locator('[data-inventory-file]').setInputFiles(csv);
    await writer.page.getByRole('button',{name:'Проверить и показать preview'}).click();
    await writer.page.locator('[data-import-preview]').waitFor();
    assert.ok((await writer.page.locator('[data-import-preview]').innerText()).includes('Артикул не найден точно.'));
    await writer.page.waitForFunction(()=>document.activeElement?.id==='inventory-preview',null,{timeout:5000});
  } finally { await unlink(csv).catch(()=>{}); }
  for(const size of [{width:1440,height:950},{width:390,height:844}]){
    await writer.page.setViewportSize(size); await writer.page.goto(inventoryRoute);
    assert.ok(await writer.page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Inventory page must not overflow the viewport');
  }
  console.log('PASS warehouse admin navigation, strict import form and desktop/mobile layout');

  if(process.env.CMTEH_READONLY_ADMIN_USERNAME&&process.env.CMTEH_READONLY_ADMIN_PASSWORD_FILE){
    const readonly=await login(process.env.CMTEH_READONLY_ADMIN_USERNAME,process.env.CMTEH_READONLY_ADMIN_PASSWORD_FILE);
    await readonly.page.goto('/admin/index.php?'+new URLSearchParams({route:'extension/cmteh/module/cmteh_catalog.inventory',user_token:readonly.token}));
    assert.equal(await readonly.page.locator('h1').innerText(),'Склады и предложения','Read-only user should have access permission');
    const denied=await readonly.page.evaluate(async(token)=>{
      const url='/admin/index.php?'+new URLSearchParams({route:'extension/cmteh/module/cmteh_catalog.inventory',user_token:token});
      const response=await fetch(url,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({inventory_action:'inventory.warehouse.save',request_key:'readonly-browser-0001',warehouse_id:'0',expected_warehouse_version:'0',code:'READONLY',name:'Readonly'})});
      return response.status;
    },readonly.token);
    assert.equal(denied,403,'Read-only native catalog user must not POST inventory changes');
    await readonly.context.close();
    console.log('PASS read-only role is denied a direct inventory POST');
  } else {
    console.log('NOT RUN read-only POST check: CMTEH_READONLY_ADMIN_USERNAME/PASSWORD_FILE are unset');
  }
  await writer.context.close();
} finally { await browser.close(); }
