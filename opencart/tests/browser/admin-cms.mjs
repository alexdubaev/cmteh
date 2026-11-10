import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { resolve, join } from 'node:path';
import { readFile, mkdir } from 'node:fs/promises';
const base = new URL(process.env.CMTEH_BROWSER_BASE || 'http://127.0.0.1:3170');
assert.ok(base.protocol === 'http:' && ['127.0.0.1','localhost','[::1]'].includes(base.hostname) && !base.username && !base.password, 'Isolated local runtime required');
const require = createRequire(resolve(process.env.CMTEH_BROWSER_TOOLING || 'D:/codex/cmteh', 'package.json'));
const { chromium } = require('playwright');
const browser = await chromium.launch();
const context = await browser.newContext({ baseURL: base.href });
const publicContext = await browser.newContext({ baseURL: base.href });
const page = await context.newPage();
const shop = await publicContext.newPage();
page.setDefaultTimeout(10000);
shop.setDefaultTimeout(10000);
const password = (await readFile(process.env.CMTEH_ADMIN_PASSWORD_FILE, 'utf8')).trim();
const suffix = Date.now().toString(36) + Math.random().toString(36).slice(2,6);
const own = [];
let token;
let homeRestore;
let siteRestore;
const editor = (type, extra='') => `/admin/index.php?route=extension/cmteh/module/cmteh_catalog&user_token=${token}&type=${type}${extra}`;
const edit = async (type,id) => page.goto(editor(type, '&id='+encodeURIComponent(id)));
const field = name => page.locator(`#cmteh-editor [name="${name}"]`);
const save = async () => {
  await page.locator('#cmteh-editor > button[type=submit]').click();
  await page.waitForLoadState('domcontentloaded');
  const errors = await page.locator('.alert-danger').allTextContents();
  assert.equal(errors.length,0, 'Named form save rejected: '+errors.join(' ').slice(0,350));
  await page.getByText('Запись сохранена', {exact:true}).waitFor();
};
const rich = async (name,text) => {
  const body = page.frameLocator(`#cke_field-${name} iframe`).locator('body');
  await body.fill(text);
};
const create = async (type, values) => {
  const id = `browser-${type}-${suffix}`;
  const slug = `browser-${type}-${suffix}`;
  await page.goto(editor(type,'&new=1'));
  await field('id').evaluate((node,value)=>node.value=value,id);
  if (await field('slug').count()) await field('slug').fill(slug);
  for (const [name,value] of Object.entries(values)) {
    const input = field(name);
    if (await input.evaluate(node=>node.tagName==='SELECT')) await input.selectOption(value); else await input.fill(value);
  }
  await save();
  own.push({type,id,slug});
  return {type,id,slug};
};
const remove = async record => {
  await edit(record.type,record.id);
  await page.getByText('Удаление записи',{exact:true}).click();
  await page.locator('[data-delete-form] [name=confirm_delete]').check();
  await page.locator('[data-delete-form] button[type=submit]').click();
  await page.getByText(/Запись удалена/).waitFor();
};
const selectHome = async (name,slugs) => {
  const group = page.locator(`[data-selection="${name}"]`);
  while (await group.locator('[data-selection-remove]').count()) await group.locator('[data-selection-remove]').first().click();
  for (const slug of slugs) {
    await group.locator('[data-selection-add]').click();
    await group.locator('select').last().selectOption(slug);
  }
};
const captureHome = async () => ({
  id: await field('id').inputValue(),
  modes: Object.fromEntries(await Promise.all(['brands_mode','categories_mode','categories_limit'].map(async name=>[name,await field(name).inputValue()]))),
  brands: await page.locator('[data-selection=brand_slugs] select').evaluateAll(nodes=>nodes.map(node=>node.value)),
  categories: await page.locator('[data-selection=category_slugs] select').evaluateAll(nodes=>nodes.map(node=>node.value)),
  blocks: await page.locator('[name^="home_blocks["]').evaluateAll(nodes=>nodes.map(node=>({name:node.name,value:node.value,checked:node.checked,type:node.type})))
});
const restoreHome = async () => {
  if (!homeRestore) return;
  await edit('home',homeRestore.id);
  for (const [name,value] of Object.entries(homeRestore.modes)) {
    if (name === 'categories_limit') await field(name).fill(value); else await field(name).selectOption(value);
  }
  await selectHome('brand_slugs',homeRestore.brands); await selectHome('category_slugs',homeRestore.categories);
  for (const entry of homeRestore.blocks) {
    if (entry.type === 'checkbox') await field(entry.name).setChecked(entry.checked); else await field(entry.name).fill(entry.value);
  }
  await save(); homeRestore=null;
};
const restoreSite = async () => {
  if (!siteRestore) return;
  await edit('site',siteRestore.id);
  await field('phone').fill(siteRestore.phone);
  // The test adds one social row after all stored rows; remove exactly that row.
  const rows=page.locator('[data-collection=social_links] [data-collection-row]');
  if (await rows.count() > siteRestore.socialCount) await rows.last().locator('[data-collection-remove]').click();
  await save(); siteRestore=null;
};
const openFirst = async type => {
  await page.goto(editor(type));
  await page.locator('tbody a[href*="cmteh_catalog"]').first().click();
};
const reviewClosure = async () => {
  const category=await create('category',{title:'Категория проверки '+suffix,h1:'Заголовок категории '+suffix,status:'published'});
  await page.reload(); assert.equal(await field('h1').inputValue(),'Заголовок категории '+suffix);
  await shop.goto('/category/'+category.slug); assert.equal(await shop.locator('main h1').textContent(),'Заголовок категории '+suffix);
  console.log('PASS review category H1: reload and public heading');
  const article=await create('article',{title:'Статья проверки '+suffix,excerpt:'Анонс проверки '+suffix,status:'published'});
  await page.reload(); assert.equal(await field('excerpt').inputValue(),'Анонс проверки '+suffix);
  await shop.goto('/articles/'+article.slug); assert.equal(await shop.locator('.article-intro').textContent(),'Анонс проверки '+suffix);
  console.log('PASS review article excerpt: reload and public intro');
  const brand=await create('brand',{name:'Бренд проверки '+suffix,status:'published'});
  const product=await create('product',{title:'Товар проверки '+suffix,seo_title:'Товар проверки '+suffix,sku:'00000042',brand_slug:brand.slug,price_status:'on_request',status:'published'});
  const nativeLink=await page.getByText('Открыть штатную форму OpenCart',{exact:true}).getAttribute('href');
  await page.goto(nativeLink); await page.locator('a[href="#tab-data"]').click(); await page.locator('[name=model]').fill('Independent-'+suffix);
  await page.locator('a[href="#tab-seo"]').click();
  for (const input of await page.locator('[name^="product_seo_url["]').all()) await input.fill(product.slug);
  const saved=page.waitForResponse(response=>response.request().method()==='POST' && response.url().includes('catalog/product.save'));
  await page.locator('button[form="form-product"][type=submit]').click();
  assert.ok((await (await saved).json()).success,'Native model change must save');
  await edit('product',product.id); await field('short_description').fill('Изменение описания '+suffix); await save(); await page.reload();
  assert.equal(await field('sku').inputValue(),'00000042');
  await page.goto(nativeLink); assert.equal(await page.locator('[name=model]').inputValue(),'Independent-'+suffix,'CMS save preserves independent native model');
  await openFirst('site'); siteRestore={id:await field('id').inputValue(),phone:await field('phone').inputValue(),socialCount:await page.locator('[data-collection=social_links] [data-collection-row]').count()};
  await page.locator('[data-collection=social_links] [data-collection-add]').click();
  const social=page.locator('[data-collection=social_links] [data-collection-row]').last();
  await social.locator('[data-column=label]').fill('Проверка соцссылки'); await social.locator('[data-column=url]').fill('https://example.org/review-'+suffix); await save();
  await shop.goto('/'); assert.equal(await shop.locator('.footer-social-links').getAttribute('aria-label'),'Социальные сети');
  await restoreSite();
  for (const record of [product,brand,article,category]) { await remove(record); own.splice(own.indexOf(record),1); }
  console.log('PASS review closure: category H1 and article excerpt reach storefront after reload; native model survives CMS save; social navigation label is Russian');
};
try {
  await page.goto('/admin/');
  await page.locator('[name=username]').fill(process.env.CMTEH_ADMIN_USERNAME || 'cmteh-admin');
  await page.locator('[name=password]').fill(password);
  await page.locator('#form-login button[type=submit]').click();
  await page.waitForURL(/user_token=/);
  token = new URL(page.url()).searchParams.get('user_token');
  await reviewClosure();
  if (process.env.CMTEH_ADMIN_CMS_SCENARIO !== 'review-closure') {
  await page.goto(editor('product','&new=1'));
  assert.equal(await page.locator('select[name=brand_slug]').count(),1,'Brand must be chosen from catalog records');
  assert.equal(await page.locator('[name=details]').count(),0,'No JSON editor');
  for (const type of ['brand','category']) {
    await openFirst(type);
    const id=await field('id').inputValue(); const name=type==='brand'?'name':'title';
    const before=await field(name).inputValue(); const slug=await field('slug').inputValue();
    try {
      await field(name).fill(before+' — браузерная проверка'); await save(); await page.reload();
      assert.equal(await field(name).inputValue(),before+' — браузерная проверка');
      assert.equal(await field('slug').inputValue(),slug,'Rename preserves existing URL');
      await shop.goto(`/${type}/${slug}`);
      assert.ok((await shop.locator('main').textContent()).includes(before+' — браузерная проверка'));
    } finally { await edit(type,id); await field(name).fill(before); await save(); }
  }
  console.log('PASS existing native brand/category rename, reload, storefront and URL preservation');
  const brand=await create('brand',{name:'Синтетический бренд '+suffix,status:'published'});
  const category=await create('category',{title:'Синтетическая категория '+suffix,status:'published'});
  // A second category uses an independent source ID and URL, never an existing record.
  await page.goto(editor('category','&new=1'));
  const nested={type:'category',id:'browser-child-'+suffix,slug:'browser-child-'+suffix};
  await field('id').evaluate((node,value)=>node.value=value,nested.id);
  await field('slug').fill(nested.slug); await field('title').fill('Синтетическая вложенная '+suffix);
  await field('parent_slug').selectOption(category.slug); await field('status').selectOption('published'); await save(); own.push(nested);
  await page.reload(); assert.equal(await field('parent_slug').inputValue(),category.slug);
  await shop.goto('/catalog'); assert.ok((await shop.locator('body').textContent()).includes('Синтетическая вложенная '+suffix));
  await page.goto(editor('product','&new=1'));
  const image='catalog/demo/canon_eos_5d_1.jpg';
  assert.equal((await publicContext.request.get('/image/'+image)).status(),200,'Public fixture photo available');
  const product={type:'product',id:'browser-product-'+suffix,slug:'browser-product-'+suffix};
  await field('id').evaluate((node,value)=>node.value=value,product.id);
  await field('slug').fill(product.slug); await field('title').fill('Синтетический товар '+suffix); await field('seo_title').fill('Синтетический товар '+suffix);
  await field('sku').fill('0000123456'); await field('mpn').fill('0000987654');
  await field('brand_slug').selectOption(brand.slug); await field('category_slug').selectOption(category.slug);
  await page.locator('#product-categories').selectOption([category.slug,nested.slug]);
  await field('main_image_path').fill(image); await field('price_status').selectOption('on_request');
  await field('status').selectOption('published');
  await page.locator('[data-collection=specification_items] [data-collection-add]').click();
  for (const [key,value] of Object.entries({group_name:'Синтетическая группа',name:'Размер проверки',value:'123',unit:'мм'})) await page.locator(`[data-collection=specification_items] [data-column=${key}]`).fill(value);
  await rich('full_description','Синтетическое описание '+suffix); await save(); own.push(product);
  if (process.env.CMTEH_BROWSER_EVIDENCE) { await mkdir(process.env.CMTEH_BROWSER_EVIDENCE,{recursive:true}); await page.screenshot({path:join(process.env.CMTEH_BROWSER_EVIDENCE,'admin-cms.png'),fullPage:true}); }
  await page.reload(); assert.equal(await field('sku').inputValue(),'0000123456'); assert.equal(await field('mpn').inputValue(),'0000987654');
  assert.equal(await page.locator('#product-categories option:checked').count(),2);
  assert.equal(await page.locator('[data-column=name]').first().inputValue(),'Размер проверки');
  assert.ok(await page.getByText('Открыть штатную форму OpenCart',{exact:true}).count());
  // Follow the native edit link and make a native model change; then use the CMS identity again.
  const nativeLink=await page.getByText('Открыть штатную форму OpenCart',{exact:true}).getAttribute('href');
  await page.goto(nativeLink); await page.locator('a[href="#tab-data"]').click(); await page.locator('[name=model]').fill('CMS-'+suffix);
  await page.locator('a[href="#tab-seo"]').click();
  for (const input of await page.locator('[name^="product_seo_url["]').all()) await input.fill(product.slug);
  const nativeSaved=page.waitForResponse(response=>response.request().method()==='POST' && response.url().includes('catalog/product.save'));
  await page.locator('button[form="form-product"][type=submit]').click();
  const nativeResult=await (await nativeSaved).json(); assert.ok(nativeResult.success,'Native save errors: '+Object.keys(nativeResult.error || {}).join(','));
  await page.locator('.alert-success').waitFor();
  await edit('product',product.id); assert.equal(await field('sku').inputValue(),'0000123456');
  await shop.goto('/product/'+product.slug);
  const productText=await shop.locator('main').textContent();
  assert.ok(productText.includes('0000123456') && productText.includes('0000987654') && productText.includes('Размер проверки') && productText.includes('123'));
  assert.ok(productText.toLowerCase().includes('по запросу'));
  assert.ok(await shop.locator(`main img[src$="${image}"]`).count());
  await page.goto(editor('product')); await page.getByLabel('Поиск по названию, артикулу или адресу').fill('0000123456'); await page.getByRole('button',{name:'Найти',exact:true}).click();
  assert.ok(await page.locator(`tbody a[href*="id=${product.id}"]`).count());
  console.log('PASS new brand, root/nested category and product with leading zeros, photo, categories, specs, native editing and search');
  await edit('product',product.id); await field('status').selectOption('draft'); await save();
  assert.equal((await publicContext.request.get('/product/'+product.slug)).status(),404);
  await field('status').selectOption('published'); await save();
  assert.equal((await publicContext.request.get('/product/'+product.slug)).status(),200);
  const oldSlug=product.slug;
  await field('slug').fill(oldSlug+'-new');
  await page.locator('#cmteh-editor > button[type=submit]').click();
  await page.getByRole('alert').filter({hasText:'Подтвердите изменение адреса'}).waitFor();
  assert.equal(await field('slug').inputValue(),oldSlug+'-new','Rejected edit retains typed URL');
  await field('confirm_slug_change').check(); await save(); product.slug=oldSlug+'-new';
  const redirect=await publicContext.request.get('/product/'+oldSlug,{maxRedirects:0}); assert.equal(redirect.status(),301); assert.equal(new URL(redirect.headers().location,base).pathname,'/product/'+product.slug);
  console.log('PASS hide, republish and explicitly confirmed URL change with actual 301');
  const draft=await create('page',{title:'Синтетическая страница '+suffix,status:'draft'});
  await rich('content','Закрытый синтетический текст '+suffix); await save(); await page.reload();
  const preview=page.locator('iframe[title="Предпросмотр материала"]');
  await page.getByText('Предпросмотр сохранённого материала в админке',{exact:true}).click();
  await page.frameLocator('iframe[title="Предпросмотр материала"]').getByText('Закрытый синтетический текст '+suffix,{exact:true}).waitFor();
  assert.equal(await preview.getAttribute('sandbox'),'');
  const hidden=await publicContext.request.get('/'+draft.slug); assert.equal(hidden.status(),404); assert.ok(!(await hidden.text()).includes('Закрытый синтетический текст '+suffix));
  await field('status').selectOption('published'); await save();
  await shop.goto('/'+draft.slug); await shop.getByText('Закрытый синтетический текст '+suffix,{exact:true}).waitFor();
  await create('navigation',{label:'Браузерное меню '+suffix,location:'header',status:'published',internal_target:'/'+draft.slug});
  await field('internal_target').selectOption('/'+draft.slug); await save();
  await shop.goto('/'); assert.ok(await shop.locator(`.site-header a[href="/${draft.slug}"]`).count());
  console.log('PASS private authenticated draft preview, publication and internal menu selector');
  await openFirst('home'); homeRestore=await captureHome();
  await field('brands_mode').selectOption('selected'); await selectHome('brand_slugs',[brand.slug]);
  await field('categories_mode').selectOption('auto_roots'); await field('categories_limit').fill('0');
  await field('home_blocks[brands][sort_order]').fill('-100'); await save(); await page.reload();
  assert.equal(await field('categories_limit').inputValue(),'0');
  await shop.goto('/'); assert.ok(await shop.locator(`#brands a[href="/brand/${brand.slug}"]`).count());
  assert.equal(await shop.locator(`.home-categories a[href="/category/${nested.slug}"]`).count(),0,'Nested category excluded from automatic roots');
  assert.ok(await shop.locator(`.home-categories a[href="/category/${category.slug}"]`).count());
  await field('categories_mode').selectOption('selected'); await selectHome('category_slugs',[nested.slug,category.slug]); await save(); await page.reload();
  await shop.goto('/'); const selectedLinks=await shop.locator('.home-categories a[href^="/category/"]').evaluateAll(nodes=>nodes.map(node=>node.getAttribute('href')));
  assert.deepEqual(selectedLinks,['/category/'+nested.slug,'/category/'+category.slug]);
  assert.ok(await shop.locator('#brands').evaluate(node=>node.compareDocumentPosition(document.querySelector('.hero')) & Node.DOCUMENT_POSITION_FOLLOWING));
  await openFirst('site'); siteRestore={id:await field('id').inputValue(),phone:await field('phone').inputValue(),socialCount:await page.locator('[data-collection=social_links] [data-collection-row]').count()};
  await field('phone').fill('+7 999 111-22-33');
  await page.locator('[data-collection=social_links] [data-collection-add]').click();
  const social=page.locator('[data-collection=social_links] [data-collection-row]').last(); await social.locator('[data-column=label]').fill('Синтетическая соцссылка'); await social.locator('[data-column=url]').fill('https://example.org/browser-'+suffix);
  await save(); await page.reload(); assert.equal(await field('phone').inputValue(),'+7 999 111-22-33');
  await shop.goto('/'); assert.ok((await shop.locator('.site-header').textContent()).includes('+7 999 111-22-33')); assert.ok((await shop.locator('.site-footer').textContent()).includes('+7 999 111-22-33'));
  assert.ok(await shop.locator(`.site-footer a[href="https://example.org/browser-${suffix}"]`).count());
  if (process.env.CMTEH_BROWSER_EVIDENCE) { await mkdir(process.env.CMTEH_BROWSER_EVIDENCE,{recursive:true}); await shop.screenshot({path:join(process.env.CMTEH_BROWSER_EVIDENCE,'admin-cms-home.png'),fullPage:true}); }
  await restoreSite(); await restoreHome();
  console.log('PASS home order/modes/selected order, nested inclusion, unlimited categories and shared contacts/social links');
  for (const record of [...own].reverse()) { await remove(record); own.splice(own.indexOf(record),1); if (record.type !== 'navigation') assert.equal((await publicContext.request.get((record.type==='page'?'/':('/'+record.type+'/'))+record.slug)).status(),410); }
  console.log('PASS explicit deletion of isolated browser records and old URL410; existing content restored');
  }
} catch (error) {
  let message=String(error?.message || error).replaceAll(password,'[REDACTED]').replace(/user_token=[^&\s"'<>]+/g,'user_token=[REDACTED]');
  if (token) message=message.replaceAll(token,'[REDACTED]');
  console.error('FAIL admin CMS: '+message.slice(0,1600)); process.exitCode=1;
} finally {
  try { await restoreSite(); await restoreHome(); for (const record of [...own].reverse()) await remove(record); }
  catch { console.error('FAIL admin CMS cleanup; isolated browser data retained for investigation'); process.exitCode=1; }
  if (token) { try { await page.goto(`/admin/index.php?route=common/logout&user_token=${token}`); } catch { process.exitCode=1; console.error('FAIL admin CMS logout'); } }
  await publicContext.close(); await context.close(); await browser.close();
}
