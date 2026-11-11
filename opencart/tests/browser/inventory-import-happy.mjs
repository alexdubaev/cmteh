import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
import {resolve} from 'node:path';
import {execFileSync} from 'node:child_process';
import {randomBytes} from 'node:crypto';
import {tmpdir} from 'node:os';
import {readFile,unlink} from 'node:fs/promises';
import {join} from 'node:path';

const base=new URL(process.env.CMTEH_BROWSER_BASE||'http://127.0.0.1:3190');
assert.ok(base.protocol==='http:'&&['127.0.0.1','localhost'].includes(base.hostname),'Loopback import fixture only');
const root=resolve(process.cwd());
const runtime=process.env.CMTEH_WAREHOUSE_RUNTIME||'D:/codex/temp/cmteh-warehouse-dev-20261111';
const port=base.port||'3190';
const tooling=process.env.CMTEH_BROWSER_TOOLING||'D:/codex/temp/cmteh-opencart-reference-gate';
const require=createRequire(resolve(tooling,'package.json'));
const {chromium}=require('playwright');
const nonce=randomBytes(6).toString('hex');
const passwordFile=join(tmpdir(),`inventory-import-${nonce}.password`);
const xlsxFile=join(tmpdir(),`inventory-import-${nonce}.xlsx`);
const phpFixture='/workspace/tests/browser/inventory-import-fixture.php';
let owned=false,username='',product=null,browser;

function fixture(mode,...args) {
  try {
    const output=execFileSync('python',['opencart/dev/runtime.py','--runtime-dir',runtime,'--port',port,'compose','exec','-T','web','php',phpFixture,mode,...args],{cwd:root,encoding:'utf8',stdio:['pipe','pipe','pipe']});
    return output.trim()?JSON.parse(output):{};
  } catch(error) {
    const message=String(error?.stderr||error?.message||error).replace(/user_token(?:=|%3D)[^&\s"'<>]+/gi,'user_token=[REDACTED]');
    throw new Error(message);
  }
}

function offers(mode) {
  try {
    const output=execFileSync('python',['opencart/dev/runtime.py','--runtime-dir',runtime,'--port',port,'compose','exec','-T','web','php','/workspace/tests/warehouse-offers-integration.php','/var/www/html/config.php',`--browser-${mode}`,nonce],{cwd:root,encoding:'utf8',stdio:['pipe','pipe','pipe']});
    return output.trim()?JSON.parse(output):{};
  } catch(error) {
    const message=String(error?.stderr||error?.message||error).replace(/user_token(?:=|%3D)[^&\s"'<>]+/gi,'user_token=[REDACTED]');
    throw new Error(message);
  }
}

async function makeWorkbook(article,warehouseCode,sourceId) {
  const input={file:xlsxFile,rows:[['article','warehouse_code','quantity','price','delivery_term','source_id'],[article,warehouseCode,'9','1777.25','',sourceId]]};
  const py=`import html,json,sys,zipfile\np=json.load(sys.stdin)\nrows=p['rows']\ncols='ABCDEFGHIJKLMNOPQRSTUVWXYZ'\ndef cell(ref,value): return '<c r="'+ref+'" t="inlineStr"><is><t>'+html.escape(value,quote=False)+'</t></is></c>'\nxml='<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'+''.join('<row r="'+str(i+1)+'">'+''.join(cell(cols[j]+str(i+1),v) for j,v in enumerate(row))+'</row>' for i,row in enumerate(rows))+'</sheetData></worksheet>'\nentries={'[Content_Types].xml':'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>','_rels/.rels':'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>','xl/workbook.xml':'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>','xl/_rels/workbook.xml.rels':'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>','xl/worksheets/sheet1.xml':xml}\nwith zipfile.ZipFile(p['file'],'w',compression=zipfile.ZIP_DEFLATED) as z:\n for name,body in entries.items(): z.writestr(name,body)\n`;
  execFileSync('python',['-c',py,xlsxFile],{cwd:root,input:JSON.stringify(input),stdio:['pipe','ignore','pipe']});
}

const roleSetup=`import json,sys\nfrom pathlib import Path\nsys.path.insert(0, str(Path('opencart/dev').resolve()))\nfrom runtime import LocalOpenCart\nfrom release_checks import create_role, MODULE, CATALOG\nr=LocalOpenCart(Path(sys.argv[1]),int(sys.argv[2]))\nr.login((r.directory/'admin-password').read_text(encoding='ascii').strip())\na=create_role(r,'Inventory XLSX happy '+sys.argv[4],[MODULE,CATALOG],[CATALOG],password_file=Path(sys.argv[3]))\nname=a.username\na.logout(); r.logout()\nprint(json.dumps({'username':name}))`;

async function rowForWarehouse(page,sku,warehouseCode) {
  for(const row of await page.locator('table tbody tr').all()) {
    const identity=await row.locator('td').first().innerText();
    if(identity.includes(sku)&&identity.includes(warehouseCode)) return row;
  }
  return null;
}

try {
  // The existing ticket-04 fixture owns and later removes this synthetic product and warehouses.
  product=offers('setup'); owned=true;
  fixture('--migrate'); // Explicit local test migration; no request/read creates the staging table.
  fixture('--unpublish',nonce,String(product.pid),String(product.a));
  const before=fixture('--state',String(product.pid),String(product.a));
  assert.equal(before.balance.quantity,6,'Fixture should begin with six units in the selected warehouse');
  assert.equal(before.balance.price,'1000.10','Fixture price baseline should be stable');
  assert.equal(before.balance.published,false,'The import must explicitly publish the previously hidden offer');

  const role=JSON.parse(execFileSync('python',['-c',roleSetup,runtime,port,passwordFile,nonce],{cwd:root,encoding:'utf8',stdio:['pipe','pipe','pipe']}));
  username=role.username;
  browser=await chromium.launch();
  const context=await browser.newContext({baseURL:base.href});
  await context.route(url=>['fonts.googleapis.com','fonts.gstatic.com'].includes(url.hostname),route=>route.abort());
  const page=await context.newPage(); page.setDefaultTimeout(15000); await page.setViewportSize({width:1920,height:1200});
  const observedPosts=[];
  page.on('request',request=>{if(request.method()==='POST') observedPosts.push(new URL(request.url()).searchParams.get('route')||'(no route)');});
  await page.goto('/admin/',{waitUntil:'domcontentloaded'});
  await page.locator('[name=username]').fill(username);
  await page.locator('[name=password]').fill((await readFile(passwordFile,'utf8')).trim());
  await page.locator('#form-login button[type=submit]').click();
  await page.waitForURL(url=>url.searchParams.get('route')==='common/dashboard');
  if(await page.locator('#modal-security').isVisible()) await page.locator('#modal-security [data-bs-dismiss="modal"]').first().click();
  const token=new URL(page.url()).searchParams.get('user_token');
  const route='extension/cmteh/module/cmteh_catalog.inventory';
  const inventoryUrl='/admin/index.php?'+new URLSearchParams({route,user_token:token,product_id:String(product.pid)});
  await page.goto(inventoryUrl);
  assert.equal(await page.locator('h1').innerText(),'Склады и предложения');
  const warehouseCode=`WOB-C-${nonce}`;
  const warehouseForm=page.locator('form').filter({has:page.locator('input[name="inventory_action"][value="inventory.warehouse.save"]')}).filter({has:page.locator('input[name="warehouse_id"][value="0"]')});
  await warehouseForm.locator('[name="code"]').fill(warehouseCode);
  await warehouseForm.locator('[name="name"]').fill(`Synthetic XLSX warehouse ${nonce}`);
  await warehouseForm.locator('[name="city"]').fill('Synthetic city C');
  await warehouseForm.locator('[name="delivery_term"]').fill('3 days');
  const formState=await warehouseForm.evaluate(form=>({valid:form.checkValidity(),invalid:[...form.elements].filter(el=>!el.checkValidity()).map(el=>el.name),method:form.method,route:new URL(form.action).searchParams.get('route')}));
  assert.ok(formState.valid,`UI warehouse-create form should be valid (${JSON.stringify(formState)})`);
  const warehouseResponse=page.waitForResponse(response=>response.request().method()==='POST',{timeout:5000}).catch(()=>null);
  await warehouseForm.evaluate(form=>form.requestSubmit()); // Native browser form submission preserves HTML validation and HTTP serialization.
  const warehouseHttp=await warehouseResponse;
  assert.ok(warehouseHttp,`Admin warehouse-create should submit the real form (${JSON.stringify(formState)}; POSTs ${JSON.stringify(observedPosts)})`);
  assert.equal(warehouseHttp.status(),200,'Admin warehouse-create POST should return HTTP 200');
  await page.getByText(`${warehouseCode} · Synthetic XLSX warehouse ${nonce}`,{exact:false}).first().waitFor();
  const targetWarehouse=fixture('--warehouse-id',nonce).warehouse_id;
  const preImport=fixture('--state',String(product.pid),String(product.a));
  assert.equal(preImport.product_count,before.product_count,'Manual warehouse creation must not create products');
  assert.equal(preImport.warehouse_count,before.warehouse_count+1,'The UI should add only the explicitly requested warehouse');
  const initialRow=await rowForWarehouse(page,'000-WOB-001',`WOB-A-${nonce}`);
  const initialRows=await page.locator('table tbody tr').allTextContents();
  assert.ok(initialRow,`The selected product and warehouse must appear in the admin inventory table (${initialRows.slice(0,5).join(' | ')})`);
  const initialCells=await initialRow.locator('td').allTextContents();
  assert.match(initialCells[1],/^\s*6\s*$/);
  assert.match(initialCells[4],/1000\.10/);
  assert.match(initialCells[4],/скрыто/);

  await makeWorkbook('000-WOB-001',warehouseCode,`warehouse-browser-${nonce}`);
  await page.locator('[data-inventory-file]').setInputFiles(xlsxFile);
  await page.locator('#publish-offers').check();
  const previewResponse=page.waitForResponse(response=>response.request().method()==='POST'&&new URL(response.url()).searchParams.get('route')===route);
  await page.getByRole('button',{name:'Проверить и показать preview'}).click();
  const previewHttp=await previewResponse;
  const previewError=await page.locator('[data-inventory-focus]').innerText().catch(()=>'(no inline import result)');
  assert.equal(previewHttp.status(),200,`Actual XLSX preview POST should return HTTP 200 (${previewError})`);
  const preview=page.locator('[data-import-preview]'); await preview.waitFor();
  const previewText=await preview.innerText();
  assert.match(previewText,/Проверено строк:\s*1/);
  assert.match(previewText,/0 шт/);
  assert.match(previewText,/9 шт/);
  assert.match(previewText,/1777\.25/);
  assert.match(previewText,/опубликовано/);
  assert.equal(await preview.locator('.alert-danger').count(),0,'XLSX rows must reach strict server preview without parser/escaping errors');
  console.log('PASS actual XLSX upload and HTTP preview show destination 0→9, price 1777.25 and explicit publication');

  const applyResponse=page.waitForResponse(response=>response.request().method()==='POST'&&new URL(response.url()).searchParams.get('route')===route);
  await page.getByRole('button',{name:'Применить весь preview целиком'}).click();
  const applyHttp=await applyResponse;
  assert.equal(applyHttp.status(),200,'Actual confirmed import apply POST should return HTTP 200');
  assert.match(await page.locator('[data-inventory-focus]').innerText(),/Импорт применён целиком: 1 строк/);

  const after=fixture('--state',String(product.pid),String(targetWarehouse));
  assert.equal(after.balance.quantity,9,'Database stock must equal the absolute XLSX quantity after one apply');
  assert.equal(after.balance.price,'1777.25','Database offer price must match XLSX');
  assert.equal(after.balance.published,true,'Database offer must be published by the explicit selection');
  assert.equal(after.product_count,preImport.product_count,'Import must not create catalog products');
  assert.equal(after.warehouse_count,preImport.warehouse_count,'Import must not create additional warehouses');
  const omitted=fixture('--state',String(product.pid),String(product.a));
  assert.equal(omitted.balance.quantity,6,'An omitted existing warehouse row must remain unchanged');
  assert.equal(omitted.balance.price,'1000.10');
  assert.equal(omitted.balance.published,false);

  await page.goto(inventoryUrl);
  const importedRow=await rowForWarehouse(page,'000-WOB-001',warehouseCode);
  assert.ok(importedRow);
  const cells=await importedRow.locator('td').allTextContents();
  assert.match(cells[1],/^\s*9\s*$/,'Reloaded admin inventory UI must show nine units, with no repeated increment');
  assert.match(cells[4],/1777\.25/);
  assert.match(cells[4],/опубликовано/);

  const operation=importedRow.locator('details').nth(1);
  await operation.locator('summary').click();
  const reserveForm=operation.locator('form');
  await reserveForm.locator('select[name="inventory_action"]').selectOption('inventory.reservation.create');
  await reserveForm.locator('[name="quantity"]').fill('3');
  await reserveForm.locator('[name="reason"]').fill('Browser happy path reservation');
  const reserveResponse=page.waitForResponse(response=>response.request().method()==='POST'&&new URL(response.url()).searchParams.get('route')===route);
  await reserveForm.getByRole('button',{name:'Подтвердить'}).click();
  assert.equal((await reserveResponse).status(),200,'Manual reserve POST should return HTTP 200');
  const reserved=fixture('--state',String(product.pid),String(targetWarehouse));
  assert.equal(reserved.balance.quantity,9);
  assert.equal(reserved.balance.reserved,3);
  assert.equal(reserved.balance.available,6);

  await page.goto(inventoryUrl);
  const releaseForm=page.locator('form').filter({has:page.locator('option[value="inventory.reservation.release"]')});
  await releaseForm.locator('select[name="inventory_action"]').selectOption('inventory.reservation.release');
  const releaseResponse=page.waitForResponse(response=>response.request().method()==='POST'&&new URL(response.url()).searchParams.get('route')===route);
  await releaseForm.getByRole('button',{name:'Подтвердить'}).click();
  assert.equal((await releaseResponse).status(),200,'Manual reserve release POST should return HTTP 200');
  await page.goto(inventoryUrl);
  const finalRow=await rowForWarehouse(page,'000-WOB-001',warehouseCode);
  assert.ok(finalRow);
  const finalCells=await finalRow.locator('td').allTextContents();
  assert.match(finalCells[1],/^\s*9\s*$/,'Stock remains nine after reserve/release without duplicate increment');
  assert.match(finalCells[2],/^\s*0\s*$/,'Released reservation returns reserved quantity to zero');
  assert.match(finalCells[3],/^\s*9\s*$/,'Available quantity returns to nine');
  assert.match(finalCells[4],/1777\.25/);
  assert.match(finalCells[4],/опубликовано/);
  const released=fixture('--state',String(product.pid),String(targetWarehouse));
  assert.equal(released.balance.quantity,9);
  assert.equal(released.balance.reserved,0);
  assert.equal(released.balance.available,9);
  assert.equal(released.balance.price,'1777.25');
  assert.equal(released.balance.published,true);
  console.log('PASS explicit apply changes actual database and UI once; no product/warehouse is auto-created');
  console.log('PASS admin reserve changes available 9→6; admin release restores 6→9');
  await context.close();
} catch(error) {
  const safe=String(error?.message||error).replace(/user_token(?:=|%3D)[^&\s"'<>]+/gi,'user_token=[REDACTED]');
  throw new Error(safe);
} finally {
  if(browser) await browser.close();
  let cleanupError='';
  if(username&&product) { try { fixture('--cleanup-import',nonce,username,String(product.pid)); } catch(error) { cleanupError=String(error?.message||error); } }
  if(owned) { try { offers('cleanup'); } catch(error) { cleanupError ||= String(error?.message||error); } }
  await unlink(passwordFile).catch(()=>{});
  await unlink(xlsxFile).catch(()=>{});
  if(cleanupError) throw new Error(`Owned browser fixture cleanup failed: ${cleanupError}`);
}
