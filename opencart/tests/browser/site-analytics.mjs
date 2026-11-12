import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {resolve} from 'node:path';
import {readFile} from 'node:fs/promises';
const base=new URL(process.env.CMTEH_BROWSER_BASE || 'http://127.0.0.1:3194');
assert.ok(base.protocol==='http:' && ['localhost','127.0.0.1'].includes(base.hostname),'Loopback runtime required');
const require=createRequire(resolve(process.env.CMTEH_BROWSER_TOOLING || 'D:/codex/cmteh','package.json'));
const {chromium}=require('playwright');
const password=(await readFile(process.env.CMTEH_ADMIN_PASSWORD_FILE,'utf8')).trim();
const browser=await chromium.launch();
const context=await browser.newContext({baseURL:base.href});
const requests=[];
// No remote scripts, fonts, or telemetry execute in this acceptance check.
await context.route('**/*',async route=>{
 const url=new URL(route.request().url());
 if(url.origin===base.origin) return route.continue();
 requests.push(url.href);
 if(url.hostname==='mc.yandex.ru'||url.hostname==='www.googletagmanager.com') return route.fulfill({status:200,contentType:'application/javascript',body:'/* provider intercepted */'});
 return route.abort();
});
const page=await context.newPage(); const shop=await context.newPage();
page.setDefaultTimeout(10000); shop.setDefaultTimeout(10000);
let token='';let siteId='';let restore=null;
const values={yandex_metrika_id:'12345678',google_analytics_id:'G-TEST1234',yandex_verification:'abcdef0123456789',google_site_verification:'Synthetic_Verification-123'};
const editor=(type,id)=>`/admin/index.php?route=extension/cmteh/module/cmteh_catalog&user_token=${token}&type=${type}${id?'&id='+id:'&new=1'}`;
const field=name=>page.locator(`#cmteh-editor [name="${name}"]`);
const save=async()=>{await page.locator('#cmteh-editor > button[type=submit]').click();await page.waitForLoadState('domcontentloaded');assert.equal(await page.locator('.alert-danger').count(),0,(await page.locator('.alert-danger').allTextContents()).join(' '));await page.getByText('Запись сохранена',{exact:true}).waitFor();};
const inspectEmpty=async()=>{
 await shop.goto('/');await shop.waitForLoadState('networkidle');
 assert.equal(await shop.locator('meta[name="yandex-verification"],meta[name="google-site-verification"],script[src*="analytics.js"],script[src*="metrika"],script[src*="gtag/js"]').count(),0);
 assert.equal(await shop.evaluate(()=>typeof window.ym),'undefined');assert.equal(await shop.evaluate(()=>typeof window.gtag),'undefined');
};
try{
 await page.goto('/admin/');await page.locator('[name=username]').fill(process.env.CMTEH_ADMIN_USERNAME || 'cmteh-admin');await page.locator('[name=password]').fill(password);await page.locator('#form-login button[type=submit]').click();await page.waitForURL(/user_token=/);token=new URL(page.url()).searchParams.get('user_token');
 // Native CMS lazily creates its editorial defaults in an empty synthetic runtime.
 await page.goto(`/admin/index.php?route=extension/cmteh/module/cmteh_catalog&user_token=${token}&type=home`);
 await page.goto(`/admin/index.php?route=extension/cmteh/module/cmteh_catalog&user_token=${token}&type=site`);
 await page.locator('tbody a[href*="cmteh_catalog"]').first().click();
 siteId=await field('id').inputValue();
 assert.equal(await page.locator('fieldset > legend').filter({hasText:'Аналитика и подтверждение прав'}).count(),1);
 restore={phone:await field('phone').inputValue()};
 for(const name of Object.keys(values)){restore[name]=await field(name).inputValue();assert.equal(restore[name],'','Synthetic runtime must start with no providers');}
 await field('phone').fill('+7 900 000-00-00');await save();
 await inspectEmpty();
 await page.goto(editor('site',siteId));for(const [name,value] of Object.entries(values)) await field(name).fill(value);await save();await page.reload();
 for(const [name,value] of Object.entries(values)) assert.equal(await field(name).inputValue(),value);
 await shop.goto('/');await shop.waitForLoadState('networkidle');
 for(const [name,value] of [['yandex-verification',values.yandex_verification],['google-site-verification',values.google_site_verification]]) assert.equal(await shop.locator(`head meta[name="${name}"]`).getAttribute('content'),value);
 assert.deepEqual(await shop.evaluate(()=>Array.from(window.ym.a[0])),[12345678,'init',{clickmap:false,trackLinks:false,accurateTrackBounce:true,webvisor:false,trackForms:false}]);
 assert.deepEqual(await shop.evaluate(()=>Array.from(window.dataLayer[1])),['config',values.google_analytics_id]);
 assert.equal(await shop.locator('script[src="https://mc.yandex.ru/metrika/tag.js"]').evaluate(node=>node.async),true);
 assert.equal(await shop.locator('script[src^="https://www.googletagmanager.com/gtag/js"]').evaluate(node=>node.async),true);
 assert.ok(requests.includes('https://mc.yandex.ru/metrika/tag.js'));assert.ok(requests.includes('https://www.googletagmanager.com/gtag/js?id=G-TEST1234'));
 const before=requests.length;await page.reload();await page.waitForLoadState('networkidle');
 assert.equal(await page.locator('script[src*="analytics.js"],meta[name="yandex-verification"],meta[name="google-site-verification"]').count(),0,'Admin has no analytics');
 assert.equal(requests.slice(before).filter(url=>/metrika|gtag/.test(url)).length,0);
 await field('google_site_verification').fill('<script>alert(1)</script>');await field('phone').fill('Must roll back');
 await page.locator('#cmteh-editor > button[type=submit]').click();await page.locator('.alert-danger').waitFor();assert.match(await page.locator('.alert-danger').innerText(),/Google Search Console/);
 await page.goto(editor('site',siteId));assert.equal(await field('phone').inputValue(),'+7 900 000-00-00');assert.equal(await field('google_site_verification').inputValue(),values.google_site_verification);
 for(const name of Object.keys(values)) await field(name).fill('');await save();await page.reload();
 for(const name of Object.keys(values)) assert.equal(await field(name).inputValue(),'');
 await inspectEmpty();
 console.log('PASS native analytics group/save/reload, atomic invalid/clear, public head, async queues, empty/admin exclusion; providers intercepted');
}catch(error){console.error('FAIL analytics browser: '+String(error.message||error).replaceAll(password,'[REDACTED]').replace(/(?:user_token|login_token)=[^&\s"'<>]+/g,'session=[REDACTED]').replaceAll(token||'__absent__','[REDACTED]').slice(0,1200));process.exitCode=1;}
finally{
 if(restore)try{await page.goto(editor('site',siteId));for(const [name,value] of Object.entries(restore))await field(name).fill(value);await save();}catch{console.error('FAIL synthetic analytics fixture restore');process.exitCode=1;}
 if(token)try{await page.goto(`/admin/index.php?route=common/logout&user_token=${token}`);}catch{}
 await browser.close();
}

