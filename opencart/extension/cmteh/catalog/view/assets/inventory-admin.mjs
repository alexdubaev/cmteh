const MAX_FILE = 5 * 1024 * 1024;
const MAX_EXPANDED = 32 * 1024 * 1024;
const HEADERS = ["article", "warehouse_code", "quantity", "price", "delivery_term"];

function directory(buffer) {
  const view = new DataView(buffer);
  let end = buffer.byteLength - 22;
  const minimum = Math.max(0, end - 65_535);
  while (end >= minimum && (view.getUint32(end, true) !== 0x06054b50 || end + 22 + view.getUint16(end + 20, true) !== buffer.byteLength)) end--;
  if (end < minimum || view.getUint16(end + 4, true) || view.getUint16(end + 6, true)) throw new Error("XLSX: некорректный архив или несколько дисков.");
  const count = view.getUint16(end + 10, true);
  let offset = view.getUint32(end + 16, true);
  const size = view.getUint32(end + 12, true);
  if (count > 1024 || offset + size !== end) throw new Error("XLSX превышает допустимый размер архива.");
  const decoder = new TextDecoder("utf-8", { fatal: true });
  const entries = [];
  let expanded = 0;
  for (let index = 0; index < count; index++) {
    if (offset + 46 > end || view.getUint32(offset, true) !== 0x02014b50) throw new Error("XLSX: повреждённая таблица файлов.");
    const method = view.getUint16(offset + 10, true);
    const compressed = view.getUint32(offset + 20, true);
    const length = view.getUint32(offset + 24, true);
    const nameLength = view.getUint16(offset + 28, true);
    const extraLength = view.getUint16(offset + 30, true);
    const commentLength = view.getUint16(offset + 32, true);
    const localOffset = view.getUint32(offset + 42, true);
    const name = decoder.decode(new Uint8Array(buffer, offset + 46, nameLength));
    expanded += length;
    if (expanded > MAX_EXPANDED || offset + 46 + nameLength + extraLength + commentLength > end) throw new Error("XLSX превышает допустимый распакованный размер.");
    if (name.includes("..") || name.startsWith("/") || /externalLinks|vbaProject\.bin/i.test(name)) throw new Error("Внешние ссылки и макросы XLSX не поддерживаются.");
    entries.push({ name, method, compressed, length, localOffset });
    offset += 46 + nameLength + extraLength + commentLength;
  }
  if (offset !== end) throw new Error("XLSX: повреждённая таблица файлов.");
  return { view, entries };
}

async function bytesFor(buffer, archive, entry) {
  const { view } = archive;
  const offset = entry.localOffset;
  if (view.getUint32(offset, true) !== 0x04034b50) throw new Error("XLSX: повреждённая запись.");
  const start = offset + 30 + view.getUint16(offset + 26, true) + view.getUint16(offset + 28, true);
  const compressed = new Uint8Array(buffer, start, entry.compressed);
  if (entry.method === 0) return compressed;
  if (entry.method !== 8 || typeof DecompressionStream !== "function") throw new Error("Браузер не поддерживает безопасное чтение этого XLSX.");
  const stream = new Blob([compressed]).stream().pipeThrough(new DecompressionStream("deflate-raw"));
  const reader = stream.getReader();
  const chunks = [];
  let total = 0;
  while (true) {
    const { value, done } = await reader.read();
    if (done) break;
    total += value.byteLength;
    if (total > Math.min(entry.length, MAX_EXPANDED)) { await reader.cancel(); throw new Error("XLSX превышает допустимый распакованный размер."); }
    chunks.push(value);
  }
  if (total !== entry.length) throw new Error("XLSX: размер распакованных данных не совпал.");
  const result = new Uint8Array(total);
  let writeOffset = 0;
  for (const chunk of chunks) { result.set(chunk, writeOffset); writeOffset += chunk.byteLength; }
  return result;
}

async function assertTextColumns(buffer, archive, firstSheet, hasSourceId = false) {
  const decoder = new TextDecoder("utf-8", { fatal: true });
  const sheets = archive.entries.filter((entry) => /^xl\/worksheets\/sheet\d+\.xml$/i.test(entry.name));
  if (!sheets.length) throw new Error("В книге не найден лист.");
  for (const sheet of sheets) {
    const xml = decoder.decode(await bytesFor(buffer, archive, sheet));
    const cells = xml.matchAll(/<c\b[^>]*\br=["'][A-Z]+\d+["'][^>]*>[\s\S]*?<\/(?:\w+:)?c\s*>/gi);
    for (const cell of cells) if (/<(?:\w+:)?f\b/i.test(cell[0])) {
      const row = cell[0].match(/\br=["'][A-Z]+(\d+)["']/i)?.[1] ?? "?";
      throw new Error(`XLSX содержит формулу в строке ${row}. Сначала замените её значением.`);
    }
  }
  const firstXml = decoder.decode(await bytesFor(buffer, archive, firstSheet));
  const rows = [...firstXml.matchAll(/<row\b[^>]*>([\s\S]*?)<\/row>/gi)];
  for (let index = 1; index < rows.length; index++) {
    const xmlRow = rows[index][1];
    if (!/<c\b/i.test(xmlRow)) continue;
    for (const column of ["A", "B", ...(hasSourceId ? ["F"] : [])]) {
      const cell = xmlRow.match(new RegExp(`<c\\b(?=[^>]*\\br="${column}\\d+")[^>]*>`,'i'))?.[0];
      if (!cell) throw new Error(`XLSX: артикул и код склада должны быть записаны текстом (строка ${index + 1}).`);
      const type = cell.match(/\bt="([^"]+)"/i)?.[1];
      if (!(["s", "inlineStr", "str"].includes(type))) throw new Error(`XLSX: артикул и код склада должны быть текстом (строка ${index + 1}).`);
    }
  }
}

async function readXlsx(file) {
  if (file.size > MAX_FILE) throw new Error("Выберите файл до 5 МБ.");
  const buffer = await file.arrayBuffer();
  const archive = directory(buffer);
  const firstSheet = archive.entries.filter((entry) => /^xl\/worksheets\/sheet\d+\.xml$/i.test(entry.name)).sort((a, b) => a.name.localeCompare(b.name, undefined, { numeric: true }))[0];
  if (!firstSheet) throw new Error("В книге не найден лист.");
  await assertTextColumns(buffer, archive, firstSheet);
  const script = document.createElement("script");
  script.src = new URL("./vendor/read-excel-file.min.js", import.meta.url).href;
  await new Promise((resolve, reject) => { script.onload = resolve; script.onerror = () => reject(new Error("Не удалось загрузить XLSX reader.")); document.head.append(script); });
  if (typeof window.readXlsxFile !== "function") throw new Error("XLSX reader unavailable.");
  const sheets = await window.readXlsxFile(buffer, { parseNumber: String });
  const matrix = sheets?.[0]?.data;
  if (!Array.isArray(matrix) || matrix.length > 10_001) throw new Error("В XLSX должно быть до 10 000 строк данных.");
  const header = matrix[0]?.map((cell) => typeof cell === "string" ? cell.trim() : "");
  if (!header || HEADERS.some((name, index) => header[index] !== name) || ![5, 6].includes(header.length) || (header[5] !== undefined && header[5] !== "source_id")) throw new Error(`Заголовки листа должны быть: ${HEADERS.join(", ")}, source_id (последняя колонка необязательна).`);
  if (header.length === 6) await assertTextColumns(buffer, archive, firstSheet, true);
  return matrix.slice(1).map((cells, index) => {
    if (!Array.isArray(cells) || cells.length > header.length) throw new Error(`В строке ${index + 2} лишние колонки.`);
    const row = { _row: index + 2 };
    for (let column = 0; column < header.length; column++) {
      const cell = cells[column];
      if (cell !== null && cell !== undefined && typeof cell !== "string") throw new Error(`Все ячейки строки ${index + 2} должны быть текстом.`);
      row[header[column]] = cell ?? "";
    }
    return row;
  }).filter((row) => Object.values(row).some((value) => value !== ""));
}

for (const form of document.querySelectorAll("[data-inventory-import]")) {
  form.addEventListener("submit", async (event) => {
    const file = form.querySelector("[data-inventory-file]")?.files?.[0];
    if (!file || !/\.xlsx$/i.test(file.name)) return;
    event.preventDefault();
    const submit = form.querySelector('[type="submit"]');
    const original = submit?.textContent;
    if (submit) { submit.disabled = true; submit.textContent = "Проверяем XLSX…"; }
    try {
      const rows = await readXlsx(file);
      form.querySelector("[data-xlsx-rows]").value = JSON.stringify(rows);
      form.submit();
    } catch (error) {
      let status = form.querySelector("[data-import-error]");
      if (!status) { status = document.createElement("div"); status.className = "alert alert-danger mt-3"; status.setAttribute("data-import-error", ""); form.append(status); }
      status.textContent = error instanceof Error ? error.message : "Не удалось прочитать XLSX.";
      if (submit) { submit.disabled = false; submit.textContent = original; }
    }
  });
}

for (const form of document.querySelectorAll("[data-transfer-form]")) {
  const target = form.querySelector("[data-transfer-target]");
  const warehouseVersion = form.querySelector("[data-target-version]");
  const balanceVersion = form.querySelector("[data-target-balance-version]");
  const update = () => {
    const option = target?.selectedOptions?.[0];
    if (option && warehouseVersion && balanceVersion) {
      warehouseVersion.value = option.dataset.version ?? "";
      balanceVersion.value = option.dataset.balanceVersion ?? "";
    }
  };
  target?.addEventListener("change", update);
  update();
}

const inventoryFeedback = document.querySelector("[data-inventory-focus]");
if (inventoryFeedback) {
  inventoryFeedback.focus({ preventScroll: true });
  inventoryFeedback.scrollIntoView({ block: "start" });
}
