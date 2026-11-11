import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {resolve} from 'node:path';
import {execFileSync} from 'node:child_process';
import {randomBytes} from 'node:crypto';

const base=new URL(process.env.CMTEH_BROWSER_BASE||'http://127.0.0.1:3190');
assert.ok(base.protocol==='http:'&&['127.0.0.1','localhost'].includes(base.hostname),'Loopback native form fixture only');
const runtime=process.env.CMTEH_WAREHOUSE_RUNTIME||'D:/codex/temp/cmteh-warehouse-dev-20261111';
const require=createRequire(resolve(process.env.CMTEH_BROWSER_TOOLING||'D:/codex/cmteh','package.json'));
const {chromium}=require('playwright');
const nonce=randomBytes(6).toString('hex');
function fixture(script,args,input) {
  const output=execFileSync('python',['opencart/dev/runtime.py','--runtime-dir',runtime,'--port',base.port,'compose','exec','-T','web','php',script,...args],{encoding:'utf8',input:input?JSON.stringify(input):undefined,stdio:['pipe','pipe','pipe']});
  return JSON.parse(output);
}
const offers=mode=>fixture('/workspace/tests/warehouse-offers-integration.php',['/var/www/html/config.php','--browser-'+mode,nonce]);
const formEvent=(mode,input)=>fixture('/workspace/tests/native-inventory-integration.php',['--form-browser-event-'+mode,nonce],input);
let owned=false,eventOwned=false,browser;
try {
  const product=offers('setup'); owned=true;
  const password=randomBytes(24).toString('hex');
  const role=formEvent('setup',{password}); eventOwned=true;
  browser=await chromium.launch();
  const context=await browser.newContext({baseURL:base.href});
  await context.route(url=>['fonts.googleapis.com','fonts.gstatic.com'].includes(url.hostname),route=>route.abort());
  const page=await context.newPage();
  page.setDefaultTimeout(10000);
  await page.goto('/admin/');
  await page.locator('[name=username]').fill(role.username);
  await page.locator('[name=password]').fill(password);
  await page.locator('#form-login button[type=submit]').click();
  await page.waitForURL(url=>url.searchParams.get('route')==='common/dashboard');
  if(await page.locator('#modal-security').isVisible()) await page.locator('#modal-security [data-bs-dismiss="modal"]').first().click();
  const token=new URL(page.url()).searchParams.get('user_token');
  const assetResponse=page.waitForResponse(response=>new URL(response.url()).pathname==='/extension/cmteh/catalog/view/assets/inventory-native-admin.js');
  const response=await page.goto('/admin/index.php?'+new URLSearchParams({route:'catalog/product.form',user_token:token,product_id:String(product.pid)}));
  assert.ok(response.status()===200,'Actual native product form must load');
  const asset=await assetResponse;
  assert.ok(asset.status()===200&&/^(?:text|application)\/javascript\b/i.test(asset.headers()['content-type']||''),'Native form generic script must load with JavaScript MIME');
  assert.ok(new URL(asset.url()).searchParams.get('v')==='20261111-1','Native form script must use current version');
  await page.waitForFunction(()=>document.getElementById('input-quantity')?.readOnly===true);
  assert.ok(await page.locator('#input-quantity').inputValue()==='10','Native quantity shows aggregate available');
  assert.ok(await page.locator('[name="product_description[1][name]"]').isEditable(),'Native descriptive field remains editable');
  console.log('PASS actual native form script HTTP200/JavaScript MIME, version, readonly stock10 and editable description');
  await page.locator('a[href="#tab-data"]').click();
  const link=page.getByRole('link',{name:'Открыть склады',exact:true});
  const href=new URL(await link.getAttribute('href'),base);
  assert.ok(href.searchParams.get('route')==='extension/cmteh/module/cmteh_catalog.inventory'&&href.searchParams.get('product_id')===String(product.pid)&&href.searchParams.get('user_token')===token,'Native stock link must preserve existing route, session and product');
  await link.click();
  assert.ok(await page.locator('h1').innerText()==='Склады и предложения','Stock link must open existing protected inventory method');
  assert.ok(await page.locator('form[method=get] input[name=product_id]').inputValue()===String(product.pid),'Inventory page keeps native product focus');
  console.log('PASS actual native stock link opens existing protected inventory method with native product filter');
} catch(error) {
  const safe=String(error?.message||error).replace(/user_token(?:=|%3D)[^&\s"'<>]+/gi,'user_token=[REDACTED]');
  throw new Error(safe);
} finally {
  try { if(browser) await browser.close(); }
  finally {
    try { if(eventOwned) { formEvent('cleanup'); console.log('PASS scoped browser fixture cleanup preserves other native users/groups/event bytes'); } }
    finally { if(owned) offers('cleanup'); }
  }
}
