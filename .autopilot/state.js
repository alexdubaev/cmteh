window.STATE =
{
  "version": 2,
  "slug": "admin-unification",
  "dir": "2026-11-11-admin-unification--wip",
  "title": "Единая админка СМ ТЕХНО без демоданных и неподключённых разделов",
  "mode": "semi",
  "depth": "normal",
  "tier": "T1",
  "briefFile": "2026-11-11-brief.md",
  "memoryFile": "AGENTS.md",
  "memoryOwner": "user",
  "skillDir": "C:/Users/Alexandr/.codex/skills/autopilot",
  "baseCommit": null,
  "startedAt": "2026-11-11T14:50:50+03:00",
  "updatedAt": "2026-11-11T15:05:12+03:00",
  "finishedAt": null,
  "stages": [
    {
      "id": "preflight",
      "status": "done",
      "startedAt": "2026-11-11T14:50:50+03:00",
      "finishedAt": "2026-11-11T15:01:14+03:00"
    },
    {
      "id": "manifest",
      "status": "done",
      "startedAt": "2026-11-11T15:01:14+03:00",
      "finishedAt": "2026-11-11T15:01:15+03:00"
    },
    {
      "id": "briefing",
      "status": "skipped",
      "startedAt": "2026-11-11T15:01:14+03:00",
      "finishedAt": "2026-11-11T15:01:14+03:00",
      "note": "Прямой запрос определяет адаптацию и отдельный отчёт о недостающих функциях. Старое наполнение/склад/production не включаются. Вопросов по продукту не потребовалось."
    },
    {
      "id": "spec",
      "status": "done",
      "startedAt": "2026-11-11T15:01:15+03:00",
      "finishedAt": "2026-11-11T15:05:09+03:00"
    },
    {
      "id": "plan",
      "status": "active",
      "startedAt": "2026-11-11T15:05:09+03:00"
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
    "total": 16,
    "done": 0,
    "inTicket": 16,
    "inSpec": 0,
    "placeholder": 0,
    "deferred": 0,
    "dropped": 0
  },
  "tickets": [
    {
      "id": "01",
      "title": "Единая админка и безопасная очистка установочного каталога",
      "requirements": [
        "R01",
        "R02",
        "R03",
        "R04",
        "R05",
        "R06",
        "R07",
        "R08",
        "R15"
      ],
      "blockedBy": [],
      "wave": 1,
      "zone": [
        "opencart/extension/cmteh/admin/controller/event/cmteh_admin.php",
        "opencart/extension/cmteh/admin/controller/module/cmteh.php",
        "opencart/extension/cmteh/admin/view/",
        "opencart/extension/cmteh/admin/language/",
        "opencart/extension/cmteh/system/",
        "opencart/bin/",
        "opencart/tests/",
        "opencart/dev/"
      ],
      "review": "нет",
      "model": "сильная",
      "status": "pending",
      "retries": 0,
      "repairs": 0
    },
    {
      "id": "02",
      "title": "Данные обращений, маркетинг и карта возможностей сайта",
      "requirements": [
        "R09",
        "R10",
        "R11",
        "R12",
        "R13",
        "R14",
        "R16",
        "R01",
        "R07",
        "R08",
        "R15"
      ],
      "blockedBy": [
        "01"
      ],
      "wave": 2,
      "zone": [
        "opencart/extension/cmteh/admin/",
        "opencart/extension/cmteh/system/",
        "opencart/tests/",
        "opencart/dev/",
        "opencart/admin-guide.md",
        "docs/"
      ],
      "review": "нет",
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
    "found": 0,
    "fixed": 0,
    "deferred": 0,
    "items": []
  },
  "concerns": [],
  "blind": null,
  "beats": [
    "2026-11-11T14:50:50+03:00",
    "2026-11-11T15:01:13+03:00",
    "2026-11-11T15:01:14+03:00",
    "2026-11-11T15:01:15+03:00",
    "2026-11-11T15:05:09+03:00",
    "2026-11-11T15:05:10+03:00",
    "2026-11-11T15:05:11+03:00",
    "2026-11-11T15:05:12+03:00"
  ],
  "rev": 12,
  "waiting": null,
  "waits": [],
  "log": [
    {
      "t": "2026-11-11T14:50:50+03:00",
      "k": "init"
    },
    {
      "t": "2026-11-11T15:01:14+03:00",
      "k": "stage",
      "id": "manifest",
      "act": "start"
    },
    {
      "t": "2026-11-11T15:01:14+03:00",
      "k": "stage",
      "id": "briefing",
      "act": "skip",
      "note": "Прямой запрос определяет адаптацию и отдельный отчёт о недостающих функциях. Старое наполнение/склад/production не включаются. Вопросов по продукту не потребовалось."
    },
    {
      "t": "2026-11-11T15:01:15+03:00",
      "k": "gate",
      "g": "G1",
      "ok": true,
      "open": 0,
      "total": 16
    },
    {
      "t": "2026-11-11T15:01:15+03:00",
      "k": "stage",
      "id": "spec",
      "act": "start"
    },
    {
      "t": "2026-11-11T15:05:09+03:00",
      "k": "gate",
      "g": "G2",
      "ok": true,
      "found": 0,
      "fixed": 0,
      "deferred": 0
    },
    {
      "t": "2026-11-11T15:05:09+03:00",
      "k": "stage",
      "id": "plan",
      "act": "start"
    },
    {
      "t": "2026-11-11T15:05:10+03:00",
      "k": "tier",
      "v": "T1"
    },
    {
      "t": "2026-11-11T15:05:11+03:00",
      "k": "tickets",
      "n": 2
    },
    {
      "t": "2026-11-11T15:05:12+03:00",
      "k": "gate",
      "g": "G3",
      "ok": true,
      "problems": 0
    }
  ],
  "reqList": [
    {
      "id": "R01",
      "text": "«какая то путаница»",
      "status": "in-ticket"
    },
    {
      "id": "R02",
      "text": "«старые тестовые записи opencart»",
      "status": "in-ticket"
    },
    {
      "id": "R03",
      "text": "«отдельно есть раздел СМ ТЕХНО»",
      "status": "in-ticket"
    },
    {
      "id": "R04",
      "text": "«товары opencart»",
      "status": "in-ticket"
    },
    {
      "id": "R05",
      "text": "«категории opencart»",
      "status": "in-ticket"
    },
    {
      "id": "R06",
      "text": "«бренды opencart»",
      "status": "in-ticket"
    },
    {
      "id": "R07",
      "text": "«Убери все что не относится к моему сайту»",
      "status": "in-ticket"
    },
    {
      "id": "R08",
      "text": "«все что там может быть полезного, надо оставить»",
      "status": "in-ticket"
    },
    {
      "id": "R09",
      "text": "«но тогда перенастроить для нашего сайта»",
      "status": "in-ticket"
    },
    {
      "id": "R10",
      "text": "«раздел маркетинг»",
      "status": "in-ticket"
    },
    {
      "id": "R11",
      "text": "«наши данные, покупатели»",
      "status": "in-ticket"
    },
    {
      "id": "R12",
      "text": "«продажи»",
      "status": "in-ticket"
    },
    {
      "id": "R13",
      "text": "«все остальное если это можно подружить с нашим сайтом. надо сделать»",
      "status": "in-ticket"
    },
    {
      "id": "R14",
      "text": "«если чего не хватает на нашем сайте»",
      "status": "in-ticket"
    },
    {
      "id": "R15",
      "text": "«например пользователей и разных настроек доступа»",
      "status": "in-ticket"
    },
    {
      "id": "R16",
      "text": "«выдели в отдельный отчет, что для реализации такой функции надо разработать вот такое»",
      "status": "in-ticket"
    }
  ],
  "gates": {
    "G1": {
      "ok": true,
      "at": "2026-11-11T15:01:15+03:00",
      "open": 0,
      "total": 16
    },
    "G2": {
      "ok": true,
      "at": "2026-11-11T15:05:09+03:00",
      "found": 0,
      "fixed": 0,
      "deferred": 0
    },
    "G3": {
      "ok": true,
      "at": "2026-11-11T15:05:12+03:00",
      "problems": 0
    }
  },
  "result": null,
  "requestStartedAt": "2026-11-11T14:48:28+03:00"
}
