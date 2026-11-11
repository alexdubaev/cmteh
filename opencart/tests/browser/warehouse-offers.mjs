import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { resolve } from 'node:path';
import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
const base=new URL(process.env.CMTEH_BROWSER_BASE||'http://127.0.0.1:3190');
assert.ok(base.protocol==='http:' && ['localhost','127.0.0.1'].includes(base.hostname));
const require=createRequire(resolve(process.env.CMTEH_BROWSER_TOOLING||'D:/codex/cmteh','package.json'));
const {chromium}=require('playwright'); const browser=await chromium.launch();
const token=randomBytes(6).toString('hex');
const runtimeDir=process.env.CMTEH_WAREHOUSE_RUNTIME||'D:/codex/temp/cmteh-warehouse-dev-20261111';
const warehouseFixture=process.env.CMTEH_BROWSER_WAREHOUSE_FIXTURE||'/workspace/tests/warehouse-offers-integration.php';
function fixture(mode) {
 const text=execFileSync('python',['opencart/dev/runtime.py','--runtime-dir',runtimeDir,'--port',base.port,'compose','exec','-T','web','php',warehouseFixture,'/var/www/html/config.php','--browser-'+mode,token],{encoding:'utf8'});
 return JSON.parse(text);
}
let owned=false;
try {
 const page=await browser.newPage({baseURL:base.href,viewport:{width:390,height:844}}); await page.goto('/request');
 const result=await page.evaluate(async()=>{
  const {writeCart,mergeCart,readCart,acknowledgeCart}=await import('/extension/cmteh/catalog/view/assets/cart.mjs?v=20261111-1');
  const legacy={article:'SYN-0001',brand:'Synthetic',title:'Synthetic',quantity:2,productId:'synthetic'};
  writeCart([legacy]);
  const line=(offerId,offerVersion='a'.repeat(64))=>({...legacy,offerId,offerVersion,warehouseName:'Synthetic '+offerId,warehouseCode:'SYN-'+offerId,deliveryTerm:offerId+' days',price:offerId==='1'?'1000.10':'850.25',currency:'RUB',quantity:3});
  mergeCart([line('1'),line('2')]); const submitted=readCart();
  mergeCart([{...line('1'),quantity:1}]); acknowledgeCart(submitted.filter(x=>x.offerId));
  return {submitted,remaining:readCart(),legacy:JSON.parse(localStorage.getItem('smtechno-request')),selected:JSON.parse(localStorage.getItem('smtechno-warehouse-request-v1')||'[]')};
 });
 assert.equal(result.submitted.length,3,'Legacy and two warehouses of one product remain separate');
 assert.deepEqual(result.remaining.map(x=>[x.offerId||null,x.quantity]),[[null,2],['1',1]],'Acknowledgement subtracts submitted quantities and preserves new additions');
 assert.equal(result.legacy.length,1); assert.equal(result.selected.length,1);
 console.log('PASS Separate warehouse storage and revision-aware acknowledgement');
 await page.close();
 const data=fixture('setup'); owned=true;
 const live=await browser.newPage({baseURL:base.href,viewport:{width:390,height:844}});
 const response=await live.goto('/product/'+data.source);
 assert.equal(response.status(),200);
 await live.locator('[data-offer-id]').first().waitFor();
 assert.equal(await live.locator('[data-offer-id]').count(),2);
 assert.equal(await live.locator('body').innerText().then(text=>text.includes('Do not expose private address')),false);
 await live.getByRole('button',{name:'Добавить 000-WOB-001, склад Synthetic warehouse A',exact:true}).click();
 await live.getByRole('button',{name:'Добавить 000-WOB-001, склад Synthetic warehouse B',exact:true}).click();
 await live.goto('/request');
 assert.equal(await live.locator('.request-line').count(),2);
 await live.getByRole('button',{name:'Увеличить количество 000-WOB-001, склад Synthetic warehouse A',exact:true}).click();
 await live.reload();
 await live.locator('.cart-price-label').getByText(/1000|1.000/).waitFor();
 assert.equal(await live.locator('.request-line').filter({hasText:'Synthetic warehouse A'}).locator('.qty b').innerText(),'2');
 assert.equal(await live.locator('.request-line').filter({hasText:'Synthetic warehouse B'}).locator('.qty b').innerText(),'1');
 assert.ok((await live.locator('.request-line').filter({hasText:'Synthetic warehouse A'}).innerText()).includes('2 days'));
 fixture('mutate'); await live.reload();
 await live.getByRole('button',{name:'Принять новые условия 000-WOB-001, склад Synthetic warehouse A',exact:true}).waitFor();
 const form=live.locator('form[data-lead="request"]');
 await form.getByLabel('Контактное лицо').fill('Synthetic buyer');
 await form.getByLabel('Телефон').fill('+70000000000');
 await form.getByLabel('Компания',{exact:true}).fill(data.marker);
 await form.getByRole('button',{name:'Отправить заявку',exact:true}).click();
 await form.locator('[data-lead-result][role="alert"]').getByText(/измен|склад|предложени/i).waitFor();
 assert.equal(fixture('state').leads.length,0);
 await live.getByRole('button',{name:'Принять новые условия 000-WOB-001, склад Synthetic warehouse A',exact:true}).click();
 const calls=[];
 await live.route('**/api/lead/warehouse',async route=>{
   calls.push(route.request().postDataJSON());
   const response=await route.fetch();
   if(calls.length===1) { await route.abort('failed'); return; }
   await live.evaluate(async()=>{
     const {readCart,mergeCart}=await import('/extension/cmteh/catalog/view/assets/cart.mjs?v=20261111-1');
     mergeCart([{...readCart().find(row=>row.warehouseName==='Synthetic warehouse A'),quantity:1}]);
   });
   await route.fulfill({response});
 });
 await form.getByRole('button',{name:'Отправить заявку',exact:true}).click();
 await form.locator('[data-lead-result][role="alert"]').getByText(/подтверждени/).waitFor();
 assert.equal(fixture('state').leads.length,1);
 await form.getByRole('button',{name:'Отправить заявку',exact:true}).click();
 await form.locator('[data-lead-result]').getByText(/Заявка принята/).waitFor();
 assert.equal(calls.length,2); assert.equal(calls[0].request_key,calls[1].request_key);
 assert.equal(calls[0].request_items.length,2); assert.notEqual(calls[0].request_items[0].offer_id,calls[0].request_items[1].offer_id);
 const state=fixture('state'); assert.equal(state.leads.length,1); assert.equal(state.leads[0].items.length,2);
 assert.deepEqual(state.leads[0].items.map(x=>[x.unit_price,x.delivery_term]),[['1234.56','2 days'],['850.25','5 days']]);
 assert.equal(state.stock.on_hand,10); assert.equal(state.stock.reserved,0);
 const remaining=await live.evaluate(()=>JSON.parse(localStorage.getItem('smtechno-warehouse-request-v1')));
 assert.equal(remaining.length,1); assert.equal(remaining[0].quantity,1); assert.equal(remaining[0].warehouseName,'Synthetic warehouse A');
 console.log('PASS Real product A+B, independent quantities, stale review, lost acknowledgement replay and fresh addition retained');
 // A previous cached module can only normalize its own original key.
 const oldSource=execFileSync('git',['show','2031f82:opencart/extension/cmteh/catalog/view/assets/cart.mjs'],{encoding:'utf8'});
 await live.route('**/__previous-cart.mjs',route=>route.fulfill({contentType:'text/javascript',body:oldSource}));
 const mixed=await live.evaluate(async()=>{
   const before=localStorage.getItem('smtechno-warehouse-request-v1');
   const old=await import('/__previous-cart.mjs');
   old.mergeCart([{article:'OLD-UNASSIGNED',brand:'Synthetic',title:'Older draft',quantity:2}]);
   const current=await import('/extension/cmteh/catalog/view/assets/cart.mjs?v=20261111-1');
   return {same:before===localStorage.getItem('smtechno-warehouse-request-v1'),rows:current.readCart()};
 });
 assert.ok(mixed.same); assert.equal(mixed.rows.length,2); assert.ok(mixed.rows.some(x=>x.offerId));
 console.log('PASS Previous cached cart module preserves isolated warehouse selection');
 const failure=await live.evaluate(async()=>{
   const {readCart,mergeCart,acknowledgeCart}=await import('/extension/cmteh/catalog/view/assets/cart.mjs?v=20261111-1');
   const previous=Storage.prototype.setItem; Storage.prototype.setItem=()=>{throw new DOMException('Denied','QuotaExceededError');};
   try {const chosen=readCart().find(x=>x.offerId); mergeCart([{...chosen,quantity:1}]); const before=readCart(); const ack=acknowledgeCart(before); return {before,after:readCart(),ack};}
   finally {Storage.prototype.setItem=previous;}
 });
 assert.equal(failure.ack,false); assert.deepEqual(failure.before,failure.after);
 console.log('PASS Storage failure retains selected quantities and refuses acknowledgement clearing');
 await live.close();
 const edge=await browser.newPage({baseURL:base.href,viewport:{width:390,height:844}});
 const version='f'.repeat(64); let selectedPosts=0,legacyPosts=0;
 await edge.route('**/api/warehouse-offers?*',route=>route.fulfill({json:{data:{synthetic:{managed:true,offers:[{offer_id:'1',offer_version:version,product_id:'synthetic',warehouse:{name:'Synthetic A',code:'SYN-A',city:''},availability_status:'in_stock',price:'9999999999999.99',currency:'RUB',delivery_term:'2 days'}],partial:false}}}}));
 await edge.route('**/api/lead/warehouse',route=>{selectedPosts++;return route.fulfill({status:405,json:{error:'Selected operation unavailable'}});});
 await edge.route('**/api/lead',route=>{legacyPosts++;return route.fulfill({json:{id:'unsafe-fallback',replayed:false}});});
 await edge.goto('/request');
 await edge.evaluate(async version=>{
  const {writeCart}=await import('/extension/cmteh/catalog/view/assets/cart.mjs?v=20261111-1');
  writeCart([{productId:'synthetic',article:'SYN-EXACT',brand:'Synthetic',title:'Synthetic exact decimal',quantity:100000,offerId:'1',offerVersion:version,warehouseName:'Synthetic A',warehouseCode:'SYN-A',deliveryTerm:'2 days',price:'9999999999999.99',currency:'RUB'}]);
 },version);
 await edge.locator('.cart-totals b').waitFor();
 assert.equal((await edge.locator('.cart-totals b').innerText()).replace(/\s/g,''),'999999999999999000₽');
 console.log('PASS BigInt total preserves every minor unit beyond the Number safe-integer boundary');
 const edgeForm=edge.locator('form[data-lead="request"]');
 await edgeForm.getByLabel('Контактное лицо').fill('Synthetic boundary buyer');await edgeForm.getByLabel('Телефон').fill('+70000000000');
 const before=await edge.evaluate(()=>localStorage.getItem('smtechno-warehouse-request-v1'));
 await edgeForm.getByRole('button',{name:'Отправить заявку',exact:true}).click();
 await edgeForm.locator('[data-lead-result][role="alert"]').getByText('Selected operation unavailable').waitFor();
 assert.equal(selectedPosts,1);assert.equal(legacyPosts,0);assert.equal(await edge.evaluate(()=>localStorage.getItem('smtechno-warehouse-request-v1')),before);
 console.log('PASS Unsupported selected endpoint preserves draft and never falls back to generic SKU submission');
 await edge.evaluate(()=>{localStorage.setItem('smtechno-request',JSON.stringify(Array.from({length:100},(_,i)=>({article:'LEGACY-'+i,title:'Legacy draft',brand:'Synthetic',quantity:1}))));});
 await edge.reload();
 const overflow=await edge.evaluate(async()=>{
   const {readCart,mergeCart}=await import('/extension/cmteh/catalog/view/assets/cart.mjs?v=20261111-1');
   const result=mergeCart([{article:'NEW-OVER-LIMIT',title:'New',brand:'Synthetic',quantity:1}]);return {length:readCart().length,selected:JSON.parse(localStorage.getItem('smtechno-warehouse-request-v1')).length,legacy:JSON.parse(localStorage.getItem('smtechno-request')).length,overflow:result.overflow};
 });
 assert.deepEqual(overflow,{length:101,selected:1,legacy:100,overflow:true});
 await edgeForm.getByRole('button',{name:'Отправить заявку',exact:true}).click();
 await edgeForm.locator('[data-lead-result][role="alert"]').getByText(/не более 100/).waitFor();
 assert.equal(selectedPosts,1);assert.equal(legacyPosts,0);
 console.log('PASS Older-tab hundred-row cart plus selected auxiliary row survives combined limit enforcement');
 await edge.close();

} finally {await browser.close(); if(owned) fixture('cleanup');}
