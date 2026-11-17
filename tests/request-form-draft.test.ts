import assert from "node:assert/strict";
import { describe, it } from "node:test";
import { clearFormDraft, readFormDraft, sameFormValues, writeFormDraft } from "../hooks/useFormDraft";

function tabStorage() {
  const values = new Map<string, string>();
  return {
    getItem: (key: string) => values.get(key) ?? null,
    setItem: (key: string, value: string) => { values.set(key, value); },
    removeItem: (key: string) => { values.delete(key); },
  };
}

describe("contact form draft persistence", () => {
  it("resets a form only when its current fields still match the submitted snapshot", () => {
    const submitted = { name: "Анна", email: "a@example.com" };
    assert.equal(sameFormValues(submitted, { ...submitted }), true);
    assert.equal(sameFormValues(submitted, { ...submitted, name: "Мария" }), false);
  });

  it("restores contact fields independently for tabs and forms, clearing only the submitted form", () => {
    const firstTab = tabStorage();
    const secondTab = tabStorage();
    const draft = { name: "Анна", phone: "+7 900 1234567", email: "a@example.com", company: "Компания", message: "Нужен аналог" };
    writeFormDraft(firstTab, "request", draft);
    writeFormDraft(firstTab, "contact", { name: "Другой контакт" });
    writeFormDraft(secondTab, "request", { name: "Вторая вкладка" });
    assert.deepEqual(readFormDraft(firstTab, "request"), draft);
    clearFormDraft(firstTab, "request", firstTab.getItem("request"));
    assert.deepEqual(readFormDraft(firstTab, "request"), {});
    assert.deepEqual(readFormDraft(firstTab, "contact"), { name: "Другой контакт" });
    assert.deepEqual(readFormDraft(secondTab, "request"), { name: "Вторая вкладка" });
  });

  it("preserves a newer edit after a late acknowledgement even when its values match the submitted draft", () => {
    const storage = tabStorage();
    const submitted = { name: "Анна", message: "Отправленный комментарий" };
    writeFormDraft(storage, "request", submitted);
    const submittedVersion = storage.getItem("request");
    writeFormDraft(storage, "request", { name: "Анна", message: "Новый комментарий" });
    writeFormDraft(storage, "request", submitted);
    clearFormDraft(storage, "request", submittedVersion);
    assert.deepEqual(readFormDraft(storage, "request"), submitted);
    clearFormDraft(storage, "request", storage.getItem("request"));
    assert.deepEqual(readFormDraft(storage, "request"), {});
  });

  it("ignores malformed storage and unsupported fields", () => {
    const storage = tabStorage();
    storage.setItem("broken", "{no json");
    storage.setItem("unexpected", JSON.stringify({ name: "Имя", phone: 123, request_items: [{ article: "SKU" }] }));
    assert.deepEqual(readFormDraft(storage, "broken"), {});
    assert.deepEqual(readFormDraft(storage, "unexpected"), { name: "Имя" });
  });

  it("allows the form to work when browser storage is denied", () => {
    const denied = () => { throw new Error("Access denied"); };
    const storage = { getItem: denied, setItem: denied, removeItem: denied };
    assert.deepEqual(readFormDraft(storage, "request"), {});
    assert.doesNotThrow(() => writeFormDraft(storage, "request", { name: "Имя" }));
    assert.doesNotThrow(() => clearFormDraft(storage, "request", null));
  });
});
