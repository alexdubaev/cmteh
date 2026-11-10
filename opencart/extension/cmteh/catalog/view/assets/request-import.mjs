import { MAX_ARTICLE_LENGTH, MAX_QUANTITY, articleKey } from "./cart.mjs?v=20261110-8";

const MAX_BYTES = 5 * 1024 * 1024;
const MAX_EXPANDED_BYTES = 32 * 1024 * 1024;
const MAX_ROWS = 10_000;
const HEADERS = new Set(["артикул", "article", "sku", "part number", "part_number"]);
export const labels = {
  quantity: "Некорректное количество: использовано 1 шт.",
  clamped: "Количество ограничено до 100 000 шт.",
  extra: "Колонки после количества не использованы.",
  missing: "Артикул отсутствует: строка не добавлена.",
  article: "Введите артикул длиной до 128 символов: строка не добавлена.",
  quoted: "Проверьте кавычки: строка не добавлена.",
};

function inspect(rows) {
  const items = [];
  const problems = [];
  let count = 0;
  let truncated = false;
  function problem(row, article, reasons) {
    count++;
    if (problems.length < 20) problems.push({ row, article, reasons });
  }
  for (const { row, cells, invalid } of rows) {
    if (row > MAX_ROWS) {
      truncated = true;
      break;
    }
    if (invalid) {
      problem(row, "", ["quoted"]);
      continue;
    }
    const first = cells[0];
    const article = String(first ?? "").trim().replace(/^\uFEFF/, "");
    if (!article) {
      if (cells.some((cell) => String(cell ?? "").trim())) problem(row, "", ["missing"]);
      continue;
    }
    if (HEADERS.has(article.toLowerCase())) continue;
    if ((typeof first !== "number" && typeof first !== "string") || !articleKey(article) || [...article].length > MAX_ARTICLE_LENGTH) {
      problem(row, article.slice(0, MAX_ARTICLE_LENGTH), ["article"]);
      continue;
    }
    const quantity = cells[1] === undefined ? 1 : Number(String(cells[1] ?? "").trim().replace(",", "."));
    const reasons = [];
    const good = Number.isSafeInteger(quantity) && quantity >= 1;
    if (!good) reasons.push("quantity");
    else if (quantity > MAX_QUANTITY) reasons.push("clamped");
    if (cells.slice(2).some((cell) => String(cell ?? "").trim())) reasons.push("extra");
    if (reasons.length) problem(row, article, reasons);
    if (items.length >= 1000) { truncated = true; break; }
    items.push({ article, quantity: good ? Math.min(quantity, MAX_QUANTITY) : 1, title: "Позиция из списка", brand: "Не указан" });
  }
  return { items, problems, count, truncated };
}

function delimiterOf(line) {
  const delimiters = new Set();
  let quoted = false;
  for (const character of line) {
    if (character === '"') quoted = !quoted;
    else if (!quoted && "\t;,".includes(character)) delimiters.add(character);
  }
  return ["\t", ";", ","].find((character) => delimiters.has(character));
}

function parseLine(line) {
  const delimiter = delimiterOf(line);
  if (!delimiter) {
    const result = line.match(/^(.*?)(?:\s+(\d+))?$/u);
    const article = result?.[1]?.trim() ?? line;
    return { cells: [article.startsWith('"') && article.endsWith('"') ? article.slice(1, -1).replaceAll('""', '"') : article, result?.[2] ?? 1] };
  }
  const cells = [];
  let value = "";
  let quoted = false;
  let closed = false;
  for (let index = 0; index < line.length; index++) {
    const character = line[index];
    if (quoted) {
      if (character !== '"') value += character;
      else if (line[index + 1] === '"') {
        value += '"';
        index++;
      } else {
        quoted = false;
        closed = true;
      }
    } else if (character === delimiter) {
      cells.push(value.trim());
      value = "";
      closed = false;
    } else if (closed && character.trim()) {
      return { invalid: true };
    } else if (character === '"' && !value.trim()) {
      quoted = true;
      value = "";
    } else {
      value += character;
    }
  }
  return quoted ? { invalid: true } : { cells: [...cells, value.trim()] };
}

function* lines(text) {
  let start = 0;
  let row = 1;
  for (const match of text.matchAll(/\r\n|\n|\r/g)) {
    yield { row: row++, ...parseLine(text.slice(start, match.index).trim()) };
    start = match.index + match[0].length;
  }
  yield { row, ...parseLine(text.slice(start).trim()) };
}

// Check archive size before the spreadsheet reader expands XML.
function checkArchive(buffer) {
  const bytes = new DataView(buffer);
  let end = bytes.byteLength - 22;
  const minimum = Math.max(0, end - 65_535);
  while (end >= minimum && (bytes.getUint32(end, true) !== 0x06054b50 ||
    end + 22 + bytes.getUint16(end + 20, true) !== bytes.byteLength)) end--;
  if (end < minimum || bytes.getUint16(end + 4, true) || bytes.getUint16(end + 6, true)) throw new Error("Некорректный архив XLSX.");
  const entries = bytes.getUint16(end + 10, true);
  let offset = bytes.getUint32(end + 16, true);
  const size = bytes.getUint32(end + 12, true);
  if (entries > 1024 || offset + size !== end) throw new Error("Архив XLSX превышает допустимый размер.");
  let expanded = 0;
  for (let index = 0; index < entries; index++) {
    if (offset + 46 > end || bytes.getUint32(offset, true) !== 0x02014b50) throw new Error("Некорректный архив XLSX.");
    expanded += bytes.getUint32(offset + 24, true);
    offset += 46 + bytes.getUint16(offset + 28, true) + bytes.getUint16(offset + 30, true) + bytes.getUint16(offset + 32, true);
    if (expanded > MAX_EXPANDED_BYTES || offset > end) throw new Error("Архив XLSX превышает допустимый размер.");
  }
  if (offset !== end) throw new Error("Некорректный архив XLSX.");
}

let library;
function loadExcelReader() {
  if (!library) {
    library = new Promise((resolve, reject) => {
      const script = document.createElement("script");
      const source = new URL("./vendor/read-excel-file.min.js", import.meta.url);
      source.search = new URL(import.meta.url).search;
      script.src = source.href;
      script.onload = () => {
        if (typeof window.readXlsxFile === "function") resolve(window.readXlsxFile);
        else reject(new Error("XLSX reader unavailable"));
      };
      script.onerror = () => reject(new Error("XLSX reader unavailable"));
      document.head.append(script);
    }).catch((error) => {
      library = undefined;
      throw error;
    });
  }
  return library;
}

async function inspectXlsx(file) {
  const buffer = await file.arrayBuffer();
  checkArchive(buffer);
  const read = await loadExcelReader();
  const sheets = await read(buffer, { parseNumber: String });
  const rows = sheets[0]?.data;
  if (!Array.isArray(rows)) throw new Error("XLSX sheet unavailable");
  function* indexedRows() {
    for (const [index, cells] of rows.entries()) yield { row: index + 1, cells };
  }
  return inspect(indexedRows());
}

export function inspectText(text) {
  if (new TextEncoder().encode(text).byteLength > 1048576) throw new Error('Список для проверки должен быть до 1 МБ. Исходный файл можно отправить на расценку.');
  return inspect(lines(text));
}
export async function inspectFile(file) {
  if (file.size > MAX_BYTES) throw new Error('Выберите файл до 5 МБ.');
  if (/\.xlsx$/i.test(file.name)) return inspectXlsx(file);
  if (!/\.(csv|txt)$/i.test(file.name)) throw new Error('PDF и фотографии можно отправить на расценку. Проверка доступна для XLSX, CSV и TXT, без распознавания изображений.');
  const buffer = await file.arrayBuffer();
  let text;
  try { text = new TextDecoder('utf-8', { fatal: true }).decode(buffer); }
  catch { text = new TextDecoder('windows-1251', { fatal: true }).decode(buffer); }
  if (/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/.test(text)) throw new Error('Файл содержит нетекстовые данные. Используйте CSV/TXT в UTF-8 или Windows-1251.');
  return inspectText(text);
}
