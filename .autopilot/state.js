window.STATE =
{
  "version": 2,
  "slug": "v2-homepage-navigation",
  "dir": "2026-11-18-v2-homepage-navigation--wip",
  "title": "СМ ТЕХНО V2 — переходы с главной",
  "mode": "semi",
  "depth": "normal",
  "tier": "T0",
  "briefFile": null,
  "memoryFile": "AGENTS.md",
  "memoryOwner": "user",
  "skillDir": "C:/Users/Alexandr/.codex/skills/autopilot",
  "baseCommit": null,
  "startedAt": "2026-11-18T15:13:32+03:00",
  "updatedAt": "2026-11-18T15:17:35+03:00",
  "finishedAt": null,
  "stages": [
    {
      "id": "preflight",
      "status": "done",
      "startedAt": "2026-11-18T15:13:32+03:00",
      "finishedAt": "2026-11-18T15:15:11+03:00"
    },
    {
      "id": "manifest",
      "status": "done",
      "startedAt": "2026-11-18T15:15:11+03:00",
      "finishedAt": "2026-11-18T15:15:11+03:00"
    },
    {
      "id": "briefing",
      "status": "done",
      "startedAt": "2026-11-18T15:15:11+03:00",
      "finishedAt": "2026-11-18T15:15:59+03:00"
    },
    {
      "id": "spec",
      "status": "done",
      "startedAt": "2026-11-18T15:15:59+03:00",
      "finishedAt": "2026-11-18T15:17:12+03:00"
    },
    {
      "id": "plan",
      "status": "active",
      "startedAt": "2026-11-18T15:17:12+03:00"
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
    "total": 5,
    "done": 0,
    "inTicket": 5,
    "inSpec": 0,
    "placeholder": 0,
    "deferred": 0,
    "dropped": 0
  },
  "tickets": [
    {
      "id": "01",
      "title": "Раскрытие страниц с главной V2",
      "requirements": [
        "R01",
        "R02",
        "R03",
        "R04",
        "R05"
      ],
      "blockedBy": [],
      "wave": 1,
      "zone": [
        "opencart/designs/v2-search/",
        "opencart/tests/browser/"
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
    "found": 3,
    "fixed": 3,
    "deferred": 0,
    "items": []
  },
  "concerns": [],
  "blind": null,
  "beats": [
    "2026-11-18T15:13:32+03:00",
    "2026-11-18T15:15:11+03:00",
    "2026-11-18T15:15:59+03:00",
    "2026-11-18T15:17:11+03:00",
    "2026-11-18T15:17:12+03:00",
    "2026-11-18T15:17:13+03:00",
    "2026-11-18T15:17:14+03:00",
    "2026-11-18T15:17:15+03:00",
    "2026-11-18T15:17:34+03:00",
    "2026-11-18T15:17:35+03:00"
  ],
  "rev": 11,
  "waiting": null,
  "waits": [],
  "log": [
    {
      "t": "2026-11-18T15:13:32+03:00",
      "k": "init"
    },
    {
      "t": "2026-11-18T15:15:11+03:00",
      "k": "stage",
      "id": "briefing",
      "act": "start"
    },
    {
      "t": "2026-11-18T15:15:59+03:00",
      "k": "gate",
      "g": "G1",
      "ok": true,
      "open": 0,
      "total": 5
    },
    {
      "t": "2026-11-18T15:15:59+03:00",
      "k": "stage",
      "id": "spec",
      "act": "start"
    },
    {
      "t": "2026-11-18T15:17:11+03:00",
      "k": "gate",
      "g": "G2",
      "ok": true,
      "found": 3,
      "fixed": 3,
      "deferred": 0
    },
    {
      "t": "2026-11-18T15:17:12+03:00",
      "k": "stage",
      "id": "plan",
      "act": "start"
    },
    {
      "t": "2026-11-18T15:17:13+03:00",
      "k": "tier",
      "v": "T0"
    },
    {
      "t": "2026-11-18T15:17:14+03:00",
      "k": "tickets",
      "n": 1
    },
    {
      "t": "2026-11-18T15:17:15+03:00",
      "k": "gate",
      "g": "G3",
      "ok": false,
      "problems": 1
    },
    {
      "t": "2026-11-18T15:17:34+03:00",
      "k": "tickets",
      "n": 1
    },
    {
      "t": "2026-11-18T15:17:35+03:00",
      "k": "gate",
      "g": "G3",
      "ok": true,
      "problems": 0
    }
  ],
  "reqList": [
    {
      "id": "R01",
      "text": "«Проверь остальные ссылки и кнопки на главной V2»",
      "status": "in-ticket"
    },
    {
      "id": "R02",
      "text": "«Если где-то ещё открывается старый каталог вместо нужной страницы V2, исправь»",
      "status": "in-ticket"
    },
    {
      "id": "R03",
      "text": "«обратно нажму на логотип чтоб вернуться на главную, происходит дерганье, трактор на фоне, строка поиска, посмотри что это и исправь»",
      "status": "in-ticket"
    },
    {
      "id": "R04",
      "text": "«если на логтип бренда нажать, то он тоже не открывается плавным раскрытием»",
      "status": "in-ticket"
    },
    {
      "id": "R05",
      "text": "«проверь чтоб все по такому принципу работало»",
      "status": "in-ticket"
    }
  ],
  "gates": {
    "G1": {
      "ok": true,
      "at": "2026-11-18T15:15:59+03:00",
      "open": 0,
      "total": 5
    },
    "G2": {
      "ok": true,
      "at": "2026-11-18T15:17:11+03:00",
      "found": 3,
      "fixed": 3,
      "deferred": 0
    },
    "G3": {
      "ok": true,
      "at": "2026-11-18T15:17:35+03:00",
      "problems": 0
    }
  },
  "result": null
}
