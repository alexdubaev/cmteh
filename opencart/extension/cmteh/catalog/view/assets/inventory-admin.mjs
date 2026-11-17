const MAX_FILE = 5 * 1024 * 1024;
const MAX_EXPANDED = 32 * 1024 * 1024;
const HEADERS = ["article", "warehouse_code", "quantity", "price", "delivery_term"];
const RELATIONSHIPS = [
  "http://schemas.openxmlformats.org/officeDocument/2006/relationships",
  "http://purl.oclc.org/ooxml/officeDocument/relationships",
];

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
  const entries = new Map();
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
    if (entries.has(name)) throw new Error("XLSX содержит повторную запись.");
    entries.set(name, { method, compressed, length, localOffset });
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

async function xmlDocument(buffer, archive, path) {
  const entry = archive.entries.get(path);
  if (!entry) throw new Error("В XLSX не найдена необходимая XML-запись.");
  const xml = new TextDecoder("utf-8", { fatal: true }).decode(await bytesFor(buffer, archive, entry));
  if (/<!DOCTYPE|<!ENTITY/i.test(xml)) throw new Error("XLSX содержит неподдерживаемую XML-структуру.");
  const parsed = new DOMParser().parseFromString(xml, "text/xml");
  if (parsed.getElementsByTagNameNS("*", "parsererror").length) throw new Error("Не удалось прочитать структуру XLSX.");
  return parsed;
}

async function validateWorkbook(buffer, archive) {
  const workbook = await xmlDocument(buffer, archive, "xl/workbook.xml");
  const relationships = await xmlDocument(buffer, archive, "xl/_rels/workbook.xml.rels");
  const worksheetTypes = RELATIONSHIPS.map(namespace => namespace + "/worksheet");
  const targets = new Map();
  for (const relation of relationships.getElementsByTagNameNS("*", "Relationship")) {
    if (!worksheetTypes.includes(relation.getAttribute("Type"))) continue;
    const id = relation.getAttribute("Id");
    const target = relation.getAttribute("Target") ?? "";
    const path = target.startsWith("/") ? target.slice(1) : "xl/" + target;
    if (!id || targets.has(id) || relation.getAttribute("TargetMode") === "External" || !/^xl\/[^:\\]+\.xml$/i.test(path) || path.includes("..")) {
      throw new Error("В XLSX обнаружена недопустимая ссылка на лист.");
    }
    targets.set(id, path);
  }
  const worksheetPaths = new Set();
  for (const sheet of workbook.getElementsByTagNameNS("*", "sheet")) {
    const id = sheet.getAttributeNS(RELATIONSHIPS[0], "id") || sheet.getAttributeNS(RELATIONSHIPS[1], "id");
    if (!targets.has(id)) throw new Error("В XLSX не найден обычный лист.");
    worksheetPaths.add(targets.get(id));
  }
  if (!worksheetPaths.size) throw new Error("В книге не найден лист.");
  for (const path of archive.entries.keys()) {
    if (/^xl\/worksheets\/sheet\d+\.xml$/i.test(path)) worksheetPaths.add(path);
  }
  let firstSheet;
  for (const path of worksheetPaths) {
    const sheet = await xmlDocument(buffer, archive, path);
    if (sheet.documentElement.localName !== "worksheet") throw new Error("В XLSX не найден обычный лист.");
    const formula = sheet.getElementsByTagNameNS("*", "f")[0];
    if (formula) {
      const row = formula.parentElement?.getAttribute("r")?.match(/\d+$/)?.[0] ?? "?";
      throw new Error(`XLSX содержит формулу в строке ${row}. Сначала замените её значением.`);
    }
    firstSheet ??= sheet;
  }
  return firstSheet;
}

function assertTextColumns(sheet, hasSourceId) {
  const rows = Array.from(sheet.getElementsByTagNameNS("*", "row"));
  const isText = cell => ["s", "inlineStr", "str"].includes(cell?.getAttribute("t"));
  for (const row of rows.slice(1)) {
    const cells = Array.from(row.getElementsByTagNameNS("*", "c"));
    if (!cells.length) continue;
    const column = name => cells.find(cell => cell.getAttribute("r")?.replace(/\d+$/, "") === name);
    const source = hasSourceId ? column("F") : null;
    const hasSourceValue = source && Array.from(source.children).some(node =>
      ["v", "is"].includes(node.localName) && node.textContent.trim());
    if (!isText(column("A")) || !isText(column("B")) || (hasSourceValue && !isText(source))) {
      throw new Error(`XLSX: артикул, код склада и указанный source_id должны быть текстом (строка ${row.getAttribute("r") ?? "?"}).`);
    }
  }
}

async function readXlsx(file) {
  if (file.size > MAX_FILE) throw new Error("Выберите файл до 5 МБ.");
  const buffer = await file.arrayBuffer();
  const archive = directory(buffer);
  const firstSheet = await validateWorkbook(buffer, archive);
  const script = document.createElement("script");
  script.src = new URL("./vendor/read-excel-file.min.js", import.meta.url).href;
  await new Promise((resolve, reject) => { script.onload = resolve; script.onerror = () => reject(new Error("Не удалось загрузить XLSX reader.")); document.head.append(script); });
  if (typeof window.readXlsxFile !== "function") throw new Error("XLSX reader unavailable.");
  const sheets = await window.readXlsxFile(buffer, { parseNumber: String });
  const matrix = sheets?.[0]?.data;
  if (!Array.isArray(matrix) || matrix.length > 10_001) throw new Error("В XLSX должно быть до 10 000 строк данных.");
  const header = matrix[0]?.map((cell) => typeof cell === "string" ? cell.trim() : "");
  if (!header || HEADERS.some((name, index) => header[index] !== name) || ![5, 6].includes(header.length) || (header[5] !== undefined && header[5] !== "source_id")) throw new Error(`Заголовки листа должны быть: ${HEADERS.join(", ")}, source_id (последняя колонка необязательна).`);
  assertTextColumns(firstSheet, header.length === 6);
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
    const fileInput = form.querySelector("[data-inventory-file]");
    const file = fileInput?.files?.[0];
    if (!file || !/\.xlsx$/i.test(file.name)) return;
    event.preventDefault();
    const submit = form.querySelector('[type="submit"]');
    const original = submit?.textContent;
    if (submit) { submit.disabled = true; submit.textContent = "Проверяем XLSX…"; }
    try {
      const rows = await readXlsx(file);
      if (fileInput.files?.[0] !== file) throw new Error("Файл изменился во время чтения. Проверьте выбранный файл снова.");
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
