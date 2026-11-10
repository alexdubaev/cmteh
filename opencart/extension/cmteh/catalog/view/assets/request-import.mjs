import { MAX_ARTICLE_LENGTH, MAX_QUANTITY, articleKey, mergeCart } from "./cart.mjs?v=20261110-4";

const MAX_BYTES = 5 * 1024 * 1024;
const MAX_EXPANDED_BYTES = 32 * 1024 * 1024;
const MAX_ROWS = 10_000;
const HEADERS = new Set(["артикул", "article", "sku", "part number", "part_number"]);
const labels = {
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

export function setupImport() {
  const details = document.querySelector("#request-import");
  if (!details) return;
  const manual = details.querySelector("#bulk-parts");
  const button = details.querySelector("[data-import-text]");
  const upload = details.querySelector("[data-import-file]");
  const message = details.querySelector("[data-import-message]");
  const problems = details.querySelector("[data-import-problems]");
  const omitted = details.querySelector("[data-import-omitted]");
  const list = problems.querySelector("ul");

  function feedback(text, error = false) {
    message.hidden = !text;
    message.textContent = text;
    message.setAttribute("role", error ? "alert" : "status");
    message.classList.toggle("error", error);
  }
  function showProblems(result = { count: 0, problems: [] }) {
    problems.hidden = !result.count;
    problems.querySelector("h3").textContent = "Проверьте строки: " + result.count;
    omitted.hidden = result.count <= result.problems.length;
    omitted.textContent = "Показаны первые " + result.problems.length + " строк из " + result.count + ". Проверьте остальные строки в исходном файле.";
    list.replaceChildren(...result.problems.map(({ row, article, reasons }) => {
      const entry = document.createElement("li");
      const heading = document.createElement("strong");
      const description = document.createElement("span");
      heading.textContent = "Строка " + row + (article ? " · " + article : "");
      description.textContent = reasons.map((reason) => labels[reason]).join(" ");
      entry.append(heading, description);
      return entry;
    }));
  }
  function append(result, source) {
    showProblems(result);
    if (!result.items.length) {
      feedback("Не удалось найти артикулы. Используйте артикул и количество, по одной позиции в строке.", true);
      return false;
    }
    const changes = mergeCart(result.items);
    const overflow = changes.overflow ? " Достигнут лимит 100 позиций." : "";
    const truncate = result.truncated ? " Обработано не более 10 000 строк." : "";
    const warnings = result.count ? " Проверьте строки: " + result.count + "." : "";
    feedback(source + ": позиций в списке " + result.items.length + ", добавлено " + changes.added + ", обновлено " + changes.updated + ", всего " + changes.total + "." + overflow + truncate + warnings);
    return true;
  }

  button.addEventListener("click", () => {
    if (new TextEncoder().encode(manual.value).byteLength > MAX_BYTES) {
      showProblems();
      feedback("Список слишком большой. Используйте список до 5 МБ.", true);
      return;
    }
    if (append(inspect(lines(manual.value)), "Список")) manual.value = "";
  });
  upload.addEventListener("change", async () => {
    const file = upload.files?.[0];
    upload.value = "";
    if (!file) return;
    showProblems();
    if (file.size > MAX_BYTES) {
      feedback("Файл слишком большой. Выберите файл до 5 МБ.", true);
      return;
    }
    if (!/\.(xlsx|csv|txt)$/i.test(file.name)) {
      feedback("Поддерживаются XLSX, CSV и TXT.", true);
      return;
    }
    upload.disabled = true;
    feedback("Читаем файл…");
    try {
      if (/\.xlsx$/i.test(file.name)) append(await inspectXlsx(file), "XLSX");
      else append(inspect(lines(await file.text())), "CSV/TXT");
    } catch {
      feedback("Не удалось прочитать файл. Проверьте содержимое XLSX, CSV или TXT.", true);
    } finally {
      upload.disabled = false;
    }
  });
  document.querySelector("[data-open-import]")?.addEventListener("click", (event) => {
    event.preventDefault();
    details.open = true;
    manual.focus();
  });
  button.disabled = false;
  upload.disabled = false;
  details.querySelector("[data-import-unavailable]").hidden = true;
}
