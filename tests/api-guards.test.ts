import assert from "node:assert/strict";
import { describe, it } from "node:test";
import { POST } from "../app/api/lead/route";

describe("lead request body boundary", () => {
  it("cancels an oversized stream even when Content-Length understates its bytes", async () => {
    let cancelled = false;
    const body = new ReadableStream<Uint8Array>({
      start(controller) { controller.enqueue(new Uint8Array(512_001)); },
      cancel() { cancelled = true; },
    }, { highWaterMark: 0 });
    const request = new Request("http://localhost/api/lead", {
      method: "POST", body, duplex: "half", headers: { "content-length": "1" },
    } as RequestInit);
    const response = await POST(request as never);
    assert.equal(response.status, 413);
    assert.equal(cancelled, true);
    assert.deepEqual(await response.json(), { error: "Заявка слишком большая." });
  });

  it("returns a controlled client error for malformed JSON", async () => {
    const response = await POST(new Request("http://localhost/api/lead", { method: "POST", body: "{" }) as never);
    assert.equal(response.status, 400);
    assert.deepEqual(await response.json(), { error: "Проверьте данные заявки." });
  });
});
