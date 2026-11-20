window.STATE =
{
  "version": 2,
  "slug": "v2-catalog-migration",
  "dir": "2026-11-20-v2-catalog-migration--wip",
  "title": "Перенос каталога в СМ ТЕХНО V2",
  "mode": "semi",
  "depth": "strict",
  "tier": "T1",
  "briefFile": "2026-11-20-brief.md",
  "memoryFile": "AGENTS.md",
  "memoryOwner": "user",
  "skillDir": "C:/Users/Alexandr/.codex/skills/autopilot",
  "baseCommit": null,
  "startedAt": "2026-11-20T12:46:13+03:00",
  "updatedAt": "2026-11-20T13:00:46+03:00",
  "finishedAt": null,
  "stages": [
    {
      "id": "preflight",
      "status": "done",
      "startedAt": "2026-11-20T12:46:13+03:00",
      "finishedAt": "2026-11-20T12:46:48+03:00"
    },
    {
      "id": "manifest",
      "status": "done",
      "startedAt": "2026-11-20T12:46:48+03:00",
      "finishedAt": "2026-11-20T12:46:50+03:00"
    },
    {
      "id": "briefing",
      "status": "done",
      "startedAt": "2026-11-20T12:46:50+03:00",
      "finishedAt": "2026-11-20T12:58:43+03:00"
    },
    {
      "id": "spec",
      "status": "done",
      "startedAt": "2026-11-20T12:58:43+03:00",
      "finishedAt": "2026-11-20T12:59:38+03:00"
    },
    {
      "id": "plan",
      "status": "active",
      "startedAt": "2026-11-20T12:59:38+03:00"
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
    "total": 9,
    "done": 0,
    "inTicket": 9,
    "inSpec": 0,
    "placeholder": 0,
    "deferred": 0,
    "dropped": 0
  },
  "tickets": [
    {
      "id": "01",
      "title": "Хлебные крошки V2",
      "requirements": [
        "R05"
      ],
      "blockedBy": [],
      "wave": 1,
      "zone": [
        "opencart/designs/v2-search/templates/"
      ],
      "review": "нет",
      "model": "сильная",
      "status": "pending",
      "retries": 0,
      "repairs": 0
    },
    {
      "id": "02",
      "title": "Каталог и старые ссылки",
      "requirements": [
        "R01",
        "R02",
        "R03",
        "R04",
        "R06",
        "R07",
        "R08",
        "G01"
      ],
      "blockedBy": [
        "01"
      ],
      "wave": 2,
      "zone": [
        ".autopilot/2026-11-20-v2-catalog-migration--wip/evidence/"
      ],
      "review": "да — миграция каталога",
      "model": "сильная",
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
    "found": 4,
    "fixed": 4,
    "deferred": 0,
    "items": []
  },
  "concerns": [],
  "blind": null,
  "beats": [
    "2026-11-20T12:46:13+03:00",
    "2026-11-20T12:46:48+03:00",
    "2026-11-20T12:46:49+03:00",
    "2026-11-20T12:46:50+03:00",
    "2026-11-20T12:51:44+03:00",
    "2026-11-20T12:54:30+03:00",
    "2026-11-20T12:58:42+03:00",
    "2026-11-20T12:58:43+03:00",
    "2026-11-20T12:59:37+03:00",
    "2026-11-20T12:59:38+03:00",
    "2026-11-20T13:00:16+03:00",
    "2026-11-20T13:00:17+03:00",
    "2026-11-20T13:00:18+03:00",
    "2026-11-20T13:00:45+03:00",
    "2026-11-20T13:00:46+03:00"
  ],
  "rev": 16,
  "waiting": null,
  "waits": [
    {
      "kind": "briefing",
      "from": "2026-11-20T12:54:30+03:00",
      "to": "2026-11-20T12:58:42+03:00"
    }
  ],
  "log": [
    {
      "t": "2026-11-20T12:46:13+03:00",
      "k": "init"
    },
    {
      "t": "2026-11-20T12:46:48+03:00",
      "k": "stage",
      "id": "manifest",
      "act": "start"
    },
    {
      "t": "2026-11-20T12:46:50+03:00",
      "k": "stage",
      "id": "briefing",
      "act": "start"
    },
    {
      "t": "2026-11-20T12:51:44+03:00",
      "k": "add",
      "key": "report",
      "n": 1
    },
    {
      "t": "2026-11-20T12:54:30+03:00",
      "k": "ask",
      "kind": "briefing",
      "note": "Куда направлять 22 адреса старого сайта, которые остались в индексе Яндекса и уже отвечают 404"
    },
    {
      "t": "2026-11-20T12:58:42+03:00",
      "k": "answered",
      "kind": "briefing"
    },
    {
      "t": "2026-11-20T12:58:43+03:00",
      "k": "gate",
      "g": "G1",
      "ok": false,
      "open": 9,
      "total": 9
    },
    {
      "t": "2026-11-20T12:58:43+03:00",
      "k": "stage",
      "id": "spec",
      "act": "start"
    },
    {
      "t": "2026-11-20T12:59:37+03:00",
      "k": "gate",
      "g": "G2",
      "ok": true,
      "found": 4,
      "fixed": 4,
      "deferred": 0
    },
    {
      "t": "2026-11-20T12:59:38+03:00",
      "k": "stage",
      "id": "plan",
      "act": "start"
    },
    {
      "t": "2026-11-20T13:00:16+03:00",
      "k": "tier",
      "v": "T1"
    },
    {
      "t": "2026-11-20T13:00:17+03:00",
      "k": "tickets",
      "n": 2
    },
    {
      "t": "2026-11-20T13:00:18+03:00",
      "k": "gate",
      "g": "G3",
      "ok": false,
      "problems": 2
    },
    {
      "t": "2026-11-20T13:00:45+03:00",
      "k": "tickets",
      "n": 2
    },
    {
      "t": "2026-11-20T13:00:46+03:00",
      "k": "gate",
      "g": "G3",
      "ok": true,
      "problems": 0
    }
  ],
  "reqList": [
    {
      "id": "R01",
      "text": "«с этих проектов взял товары»",
      "status": "in-ticket"
    },
    {
      "id": "R02",
      "text": "«с ценами»",
      "status": "in-ticket"
    },
    {
      "id": "R03",
      "text": "«картинками»",
      "status": "in-ticket"
    },
    {
      "id": "R04",
      "text": "«описаниями»",
      "status": "in-ticket"
    },
    {
      "id": "R05",
      "text": "«хлебными крошками»",
      "status": "in-ticket"
    },
    {
      "id": "R06",
      "text": "«поместил их в нужные разделы на нашем сайте»",
      "status": "in-ticket"
    },
    {
      "id": "R07",
      "text": "«старый сайт cmteh.ru, проиндексированые страница которого должны быть на этом новом сайте»",
      "status": "in-ticket"
    },
    {
      "id": "R08",
      "text": "«если что то будет не хватать  или не будешь понимать не придумывай а спроси»",
      "status": "in-ticket"
    },
    {
      "id": "G01",
      "text": "«в deere-shopнапдо брать товары которые с картинками их там штук 280»",
      "status": "in-ticket"
    }
  ],
  "gates": {
    "G1": {
      "ok": false,
      "at": "2026-11-20T12:58:43+03:00",
      "open": 9,
      "total": 9
    },
    "G2": {
      "ok": true,
      "at": "2026-11-20T12:59:37+03:00",
      "found": 4,
      "fixed": 4,
      "deferred": 0
    },
    "G3": {
      "ok": true,
      "at": "2026-11-20T13:00:46+03:00",
      "problems": 0
    }
  },
  "result": null,
  "report": [
    "Уточнение владельца: в Deere Shop только товары с фотографиями. Из Яндекс Вебмастера получен точный список 191 URL cmteh.ru."
  ]
}
