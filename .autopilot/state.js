window.STATE =
{
  "version": 2,
  "slug": "site-analytics",
  "dir": "2026-11-12-site-analytics--wip",
  "title": "Аналитика и подтверждение прав",
  "mode": "semi",
  "depth": "normal",
  "tier": "T0",
  "briefFile": "2026-11-12-brief.md",
  "memoryFile": "AGENTS.md",
  "memoryOwner": "user",
  "skillDir": "C:/Users/Alexandr/.codex/skills/autopilot",
  "baseCommit": null,
  "startedAt": "2026-11-12T12:38:18+03:00",
  "updatedAt": "2026-11-12T12:40:44+03:00",
  "finishedAt": null,
  "stages": [
    {
      "id": "preflight",
      "status": "done",
      "startedAt": "2026-11-12T12:38:18+03:00",
      "finishedAt": "2026-11-12T12:39:29+03:00"
    },
    {
      "id": "manifest",
      "status": "done",
      "startedAt": "2026-11-12T12:39:29+03:00",
      "finishedAt": "2026-11-12T12:39:31+03:00"
    },
    {
      "id": "briefing",
      "status": "skipped",
      "startedAt": "2026-11-12T12:39:30+03:00",
      "finishedAt": "2026-11-12T12:39:30+03:00",
      "note": "Четыре поля определены; прежние отдельные задачи не включены"
    },
    {
      "id": "spec",
      "status": "done",
      "startedAt": "2026-11-12T12:39:31+03:00",
      "finishedAt": "2026-11-12T12:40:31+03:00"
    },
    {
      "id": "plan",
      "status": "active",
      "startedAt": "2026-11-12T12:40:31+03:00"
    },
    {
      "id": "build",
      "status": "pending"
    },
    {
      "id": "review",
      "status": "pending"
    },
    {
      "id": "final",
      "status": "pending"
    }
  ],
  "requirements": {
    "total": 6,
    "done": 0,
    "inTicket": 6,
    "inSpec": 0,
    "placeholder": 0,
    "deferred": 0,
    "dropped": 0
  },
  "tickets": [
    {
      "id": "01",
      "title": "Аналитика и подтверждение прав",
      "requirements": [
        "R01",
        "R02",
        "R03",
        "R04",
        "R05",
        "R06"
      ],
      "blockedBy": [],
      "wave": 1,
      "zone": [
        "opencart/extension/cmteh/",
        "opencart/tests/",
        "opencart/admin-guide.md"
      ],
      "review": "нет",
      "model": "обычная",
      "status": "pending",
      "retries": 0,
      "repairs": 0
    }
  ],
  "tests": null,
  "debt": {
    "placeholders": [],
    "assumptions": [],
    "emptyEnv": []
  },
  "additions": [],
  "coverage": {
    "found": 1,
    "fixed": 1,
    "deferred": 0,
    "items": []
  },
  "concerns": [],
  "blind": null,
  "beats": [
    "2026-11-12T12:38:18+03:00",
    "2026-11-12T12:39:29+03:00",
    "2026-11-12T12:39:30+03:00",
    "2026-11-12T12:39:31+03:00",
    "2026-11-12T12:40:30+03:00",
    "2026-11-12T12:40:31+03:00",
    "2026-11-12T12:40:32+03:00",
    "2026-11-12T12:40:33+03:00",
    "2026-11-12T12:40:34+03:00",
    "2026-11-12T12:40:44+03:00"
  ],
  "rev": 13,
  "waiting": null,
  "waits": [],
  "log": [
    {
      "t": "2026-11-12T12:38:18+03:00",
      "k": "init"
    },
    {
      "t": "2026-11-12T12:39:29+03:00",
      "k": "stage",
      "id": "manifest",
      "act": "start"
    },
    {
      "t": "2026-11-12T12:39:30+03:00",
      "k": "stage",
      "id": "briefing",
      "act": "skip",
      "note": "Четыре поля определены; прежние отдельные задачи не включены"
    },
    {
      "t": "2026-11-12T12:39:31+03:00",
      "k": "gate",
      "g": "G1",
      "ok": true,
      "open": 0,
      "total": 6
    },
    {
      "t": "2026-11-12T12:39:31+03:00",
      "k": "stage",
      "id": "spec",
      "act": "start"
    },
    {
      "t": "2026-11-12T12:40:30+03:00",
      "k": "gate",
      "g": "G2",
      "ok": true,
      "found": 1,
      "fixed": 1,
      "deferred": 0
    },
    {
      "t": "2026-11-12T12:40:31+03:00",
      "k": "stage",
      "id": "plan",
      "act": "start"
    },
    {
      "t": "2026-11-12T12:40:32+03:00",
      "k": "tier",
      "v": "T0"
    },
    {
      "t": "2026-11-12T12:40:33+03:00",
      "k": "tickets",
      "n": 1
    },
    {
      "t": "2026-11-12T12:40:34+03:00",
      "k": "gate",
      "g": "G3",
      "ok": false,
      "problems": 1
    },
    {
      "t": "2026-11-12T12:40:44+03:00",
      "k": "tickets",
      "n": 1
    },
    {
      "t": "2026-11-12T12:40:44+03:00",
      "k": "gate",
      "g": "G3",
      "ok": true,
      "problems": 0
    }
  ],
  "reqList": [
    {
      "id": "R01",
      "text": "«Настройки сайта → Аналитика и подтверждение прав»",
      "status": "in-ticket"
    },
    {
      "id": "R02",
      "text": "«Яндекс Метрика — номер счётчика»",
      "status": "in-ticket"
    },
    {
      "id": "R03",
      "text": "«Google Analytics 4 — идентификатор измерения»",
      "status": "in-ticket"
    },
    {
      "id": "R04",
      "text": "«Яндекс Вебмастер — код подтверждения»",
      "status": "in-ticket"
    },
    {
      "id": "R05",
      "text": "«Google Search Console — код подтверждения»",
      "status": "in-ticket"
    },
    {
      "id": "R06",
      "text": "«одна админка для v1 v2 ... разница будет только визуальная»",
      "status": "in-ticket"
    }
  ],
  "gates": {
    "G1": {
      "ok": true,
      "at": "2026-11-12T12:39:31+03:00",
      "open": 0,
      "total": 6
    },
    "G2": {
      "ok": true,
      "at": "2026-11-12T12:40:30+03:00",
      "found": 1,
      "fixed": 1,
      "deferred": 0
    },
    "G3": {
      "ok": true,
      "at": "2026-11-12T12:40:44+03:00",
      "problems": 0
    }
  },
  "result": null
}
