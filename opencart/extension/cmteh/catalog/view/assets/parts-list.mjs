import { mergeCart } from "./cart.mjs?v=20261110-6";
import { inspectText, inspectFile, labels } from "./request-import.mjs?v=20261110-6";

const root = document.querySelector('[data-parts-list]');
if (root) setup(root);
function setup(root) {
  const text = root.querySelector('#parts-list-text');
  const upload = root.querySelector('#parts-list-file');
  const modes = [...root.querySelectorAll('[name=list-source]')];
  const form = root.querySelector('[data-quote-form]');
  const check = root.querySelector('[data-check-list]');
  const submit = form.querySelector('[type=submit]');
  const results = root.querySelector('[data-list-results]');
  const draftKey = 'smtechno-quote-draft';
  const pendingKey = 'smtechno-quote-pending';
  const fields = ['name', 'phone', 'email', 'message'];
  let busy = false;
  let checking = false;
  let rows = [];
  let page = 0;
  let revision = 0;
  const get = (key) => { try { return JSON.parse(sessionStorage.getItem(key) || '{}'); } catch { return {}; } };
  const put = (key, value) => { try { sessionStorage.setItem(key, JSON.stringify(value)); } catch { /* Works without storage. */ } };
  const mode = () => modes.find((radio) => radio.checked).value;
  const readFields = () => Object.fromEntries(fields.map((name) => [name, form.elements.namedItem(name).value]));
  function message(selector, value, error = false) {
    const node = root.querySelector(selector);
    node.hidden = !value;
    node.textContent = value;
    node.setAttribute('role', error ? 'alert' : 'status');
    node.classList.toggle('error', error);
  }
  function saveDraft() {
    put(draftKey, { ...readFields(), text: text.value, input_kind: mode(), filename: upload.files?.[0]?.name || '' });
  }
  function syncMode() {
    root.querySelector('[data-source-text]').hidden = mode() !== 'text';
    root.querySelector('[data-source-file]').hidden = mode() !== 'file';
  }
  const draft = get(draftKey);
  text.value = typeof draft.text === 'string' ? draft.text : new URLSearchParams(location.search).get('article') || '';
  for (const field of fields) if (typeof draft[field] === 'string') form.elements.namedItem(field).value = draft[field];
  if (draft.input_kind === 'file') {
    modes[1].checked = true;
    if (draft.filename) root.querySelector('[data-file-note]').textContent = 'Выберите файл «' + draft.filename + '» заново: файлы не сохраняются в браузере.';
  }
  syncMode();
  function changed() { revision++; results.hidden = true; saveDraft(); }
  text.addEventListener('input', changed);
  for (const radio of modes) radio.addEventListener('change', () => { syncMode(); changed(); });
  upload.addEventListener('change', () => {
    modes[1].checked = true; syncMode(); changed();
    root.querySelector('[data-file-note]').textContent = upload.files?.[0] ? 'Выбран оригинал: ' + upload.files[0].name + '. При расценке отправится этот файл.' : 'После обновления страницы файл нужно выбрать заново.';
  });
  form.addEventListener('input', () => { form.elements.namedItem('phone').setCustomValidity(''); saveDraft(); });
  function sourceFile() {
    const file = upload.files?.[0];
    if (!file) throw new Error('Выберите файл списка. После обновления страницы его нужно выбрать заново.');
    if (file.size > 5 * 1024 * 1024) throw new Error('Выберите файл до 5 МБ.');
    if (!/\.(xlsx|csv|txt|pdf|jpe?g|png|webp|heic|heif)$/i.test(file.name)) throw new Error('Поддерживаются XLSX, CSV, TXT, PDF и фотографии JPG, PNG, WebP, HEIC/HEIF.');
    return file;
  }
  check.addEventListener('click', async () => {
    if (checking || busy) return;
    checking = true; check.disabled = true; check.textContent = 'Проверяем…';
    const currentRevision = revision;
    const diagnostics = root.querySelector('[data-list-diagnostics]');
    diagnostics.hidden = true;
    message('[data-list-message]', '');
    try {
      const parsed = mode() === 'file' ? await inspectFile(sourceFile()) : inspectText(text.value);
      if (parsed.truncated || parsed.items.length > 1000) throw new Error('Для одной проверки нужно не более 1000 позиций. Исходный файл можно отправить на расценку.');
      if (!parsed.items.length) throw new Error('Введите хотя бы один артикул или выберите таблицу.');
      diagnostics.replaceChildren(...parsed.problems.map((problem) => node('li', 'Строка ' + problem.row + ': ' + problem.reasons.map((reason) => labels[reason]).join(' '))));
      if (parsed.count > parsed.problems.length) diagnostics.append(node('li', 'Всего строк с замечаниями: ' + parsed.count + '. Проверьте исходный список.'));
      diagnostics.hidden = !parsed.count;
      const response = await fetch('/api/list-check', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ rows: parsed.items.map(({ article, quantity }) => ({ article, quantity })) }) });
      const data = await response.json().catch(() => null);
      if (!response.ok) throw new Error(data?.error || 'Не удалось проверить наличие. Повторите проверку.');
      if (!Array.isArray(data?.rows) || data.rows.length !== parsed.items.length) throw new Error('Получен неполный ответ. Повторите проверку.');
      if (currentRevision !== revision) { message('[data-list-message]', 'Список изменился во время проверки. Проверьте обновлённый список.', true); return; }
      rows = data.rows; page = 0; render(); results.hidden = false;
      root.querySelector('[data-result-count]').textContent = 'Проверено строк: ' + rows.length;
      message('[data-list-message]', data.partial ? 'Показана часть предложений. Для строк с ограниченной выдачей наличие уточняется у менеджера.' : 'Проверка завершена. Выберите нужные товары в результатах.');
    } catch (error) { message('[data-list-message]', error.message || 'Не удалось проверить список. Повторите попытку.', true); }
    finally { checking = false; check.disabled = busy; check.textContent = 'Проверить наличие'; }
  });
  function render() {
    root.querySelector('[data-result-rows]').replaceChildren(...rows.slice(page * 50, (page + 1) * 50).map((row) => {
      const card = node('article', '', 'list-result panel'); card.dataset.resultRow = '';
      const heading = node('div', '', 'list-result-heading');
      heading.append(node('h3', (row.index + 1) + '. ' + row.article), node('span', row.quantity + ' шт.')); card.append(heading);
      if (!row.products.length) card.append(node('p', row.more_matches ? 'Проверка этой строки ограничена. Уточните у менеджера.' : 'В каталоге не найдено'));
      else {
        if (row.status === 'ambiguous') card.append(node('p', 'Найдено несколько предложений. Выберите нужный товар.'));
        for (const product of row.products) {
          const offer = node('div', '', 'list-offer');
          const info = node('div');
          const link = node('a', product.title); link.href = '/product/' + encodeURIComponent(product.slug);
          info.append(node('strong', product.brand || 'Бренд не указан'), link, node('span', product.sku), node('small', { in_stock: 'В наличии', on_request: 'Под заказ', out_of_stock: 'Нет в наличии' }[product.availability_status] || 'Наличие уточняется'));
          const button = node('button', 'В корзину', 'button secondary'); button.type = 'button';
          const feedback = node('span', '', 'list-add-feedback'); feedback.setAttribute('role', 'status');
          button.addEventListener('click', () => {
            const result = mergeCart([{ article: product.sku, productId: product.id, brand: product.brand, title: product.title, quantity: row.quantity }]);
            feedback.textContent = result.overflow ? 'Корзина заполнена: максимум 100 товаров. Товар не добавлен.' : 'Добавлено: ' + row.quantity + ' шт.';
          });
          const action = node('div', '', 'list-offer-action'); action.append(button, feedback); offer.append(info, action); card.append(offer);
        }
      }
      if (row.more_matches) card.append(node('p', 'Выдача ограничена. Остальные предложения уточните у менеджера.'));
      return card;
    }));
    root.querySelector('[data-results-page]').textContent = (page + 1) + ' / ' + Math.ceil(rows.length / 50);
    root.querySelector('[data-results-prev]').disabled = page === 0;
    root.querySelector('[data-results-next]').disabled = (page + 1) * 50 >= rows.length;
  }
  for (const [selector, delta] of [['[data-results-prev]', -1], ['[data-results-next]', 1]]) root.querySelector(selector).addEventListener('click', () => { page += delta; render(); results.scrollIntoView({ block: 'start' }); });
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (busy) return;
    const contact = readFields();
    const phone = form.elements.namedItem('phone');
    phone.setCustomValidity(!contact.phone.trim() && !contact.email.trim() ? 'Укажите телефон или email.' : '');
    if (!form.reportValidity()) return;
    busy = true; submit.disabled = true; check.disabled = true; submit.textContent = 'Отправляем…';
    for (const input of [text, upload, ...modes, ...fields.map((field) => form.elements.namedItem(field))]) input.disabled = true;
    message('[data-quote-message]', '');
    try {
      const payload = { ...contact, page_url: location.href, input_kind: mode() };
      if (payload.input_kind === 'text') {
        if (!text.value.trim()) throw new Error('Введите исходный список.');
        if (new TextEncoder().encode(text.value).byteLength > 1048576) throw new Error('Текст для расценки должен быть до 1 МБ.');
        payload.list_text = text.value;
      } else {
        const file = sourceFile();
        const bytes = new Uint8Array(await file.arrayBuffer());
        let binary = '';
        for (let offset = 0; offset < bytes.length; offset += 32768) binary += String.fromCharCode(...bytes.subarray(offset, offset + 32768));
        payload.file = { filename: file.name, data_base64: btoa(binary) };
      }
      const hash = [...new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(JSON.stringify(payload))))].map((value) => value.toString(16).padStart(2, '0')).join('');
      const previous = get(pendingKey);
      const key = previous.fingerprint === hash && /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(previous.key) ? previous.key : crypto.randomUUID();
      put(pendingKey, { fingerprint: hash, key });
      const response = await fetch('/api/quote', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ request_key: key, ...payload }) });
      const data = await response.json().catch(() => null);
      if (!response.ok) throw new Error(data?.error || 'Не удалось отправить расценку. Повторите попытку.');
      if (typeof data?.id !== 'string' || !/^[a-f0-9-]{36}$/i.test(data.id) || typeof data.replayed !== 'boolean') throw new Error('Подтверждение не получено. Повторите отправку с тем же списком.');
      // Keep the accepted immutable key: another click of the same content is a replay.
      message('[data-quote-message]', 'Расценка принята. Номер: ' + data.id + '. Менеджер свяжется с вами.');
    } catch (error) { message('[data-quote-message]', error.message || 'Подтверждение не получено. Повторите отправку с тем же списком.', true); }
    finally {
      busy = false; submit.disabled = false; check.disabled = checking; submit.textContent = 'Отправить на расценку';
      for (const input of [text, upload, ...modes, ...fields.map((field) => form.elements.namedItem(field))]) input.disabled = false;
    }
  });
  check.disabled = false; submit.disabled = false; root.querySelector('[data-list-preparing]').hidden = true;
}
function node(tag, text = '', className = '') { const element = document.createElement(tag); element.textContent = text; element.className = className; return element; }
