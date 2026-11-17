import assert from "node:assert/strict";
import { describe, it } from "node:test";
import { clearPendingSubmission, ensurePendingSubmission, fingerprintPayload, requireLeadAcknowledgement, validateLeadSubmission } from "../lib/idempotency";

function storage() {
  const data = new Map<string, string>();
  return { getItem: (key: string) => data.get(key) ?? null, setItem: (key: string, value: string) => data.set(key, value), removeItem: (key: string) => data.delete(key) };
}

describe("reload-safe pending lead keys", () => {
  it("blocks an empty parts RFQ while allowing quick contact without items", () => {
    assert.equal(validateLeadSubmission("request", []), "Добавьте позиции в корзину перед отправкой заявки.");
    assert.equal(validateLeadSubmission("contact", []), null);
    assert.equal(validateLeadSubmission("request", [{ article: "SKU", quantity: 1 }]), null);
  });

  it("rejects malformed acknowledgements even when HTTP succeeded", () => {
    assert.equal(requireLeadAcknowledgement({ id: "lead-42" }), "lead-42");
    for (const payload of [null, {}, { id: "" }, { id: 42 }]) {
      assert.throws(() => requireLeadAcknowledgement(payload));
    }
  });

  it("reuses a key for a matching payload after remount and replaces it when edits change the payload", async () => {
    const firstTabMount = storage();
    const lead = { name: "Анна", phone: "+7 900 1234567", request_items: [{ article: "SKU", quantity: 2 }] };
    const key1 = await ensurePendingSubmission(firstTabMount, "request", lead, () => "key-1");
    const remounted = await ensurePendingSubmission(firstTabMount, "request", lead, () => "key-2");
    const edited = await ensurePendingSubmission(firstTabMount, "request", { ...lead, message: "Изменение" }, () => "key-3");
    assert.equal(remounted.key, key1.key);
    assert.equal(edited.key, "key-3");
  });

  it("scopes keys by form and stores only fingerprints and keys, then clears acknowledged state", async () => {
    const saved = storage();
    const payload = { email: "private@example.com" };
    const request = await ensurePendingSubmission(saved, "request", payload, () => "request-key");
    const contact = await ensurePendingSubmission(saved, "contact", payload, () => "contact-key");
    assert.notEqual(contact.key, request.key);
    const serialized = saved.getItem("smtechno-pending-lead:request") ?? "";
    assert.ok(!serialized.includes(payload.email));
    assert.ok(serialized.includes(await fingerprintPayload(payload)));
    clearPendingSubmission(saved, "request", request.fingerprint);
    assert.equal(saved.getItem("smtechno-pending-lead:request"), null);
    assert.notEqual(saved.getItem("smtechno-pending-lead:contact"), null);
  });
});
