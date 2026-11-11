window.STATE =
{
  "version": 2,
  "slug": "site-capabilities",
  "dir": "2026-11-11-site-capabilities",
  "title": "СМ ТЕХНО — склады и раздельные остатки",
  "mode": "semi",
  "depth": "normal",
  "tier": "T2",
  "briefFile": "2026-11-11-brief.md",
  "memoryFile": "AGENTS.md",
  "memoryOwner": "user",
  "skillDir": "C:/Users/Alexandr/.codex/skills/autopilot",
  "baseCommit": "060c56b",
  "startedAt": "2026-11-11T18:21:24+03:00",
  "updatedAt": "2026-11-11T22:10:39+03:00",
  "finishedAt": "2026-11-11T22:10:39+03:00",
  "stages": [
    {
      "id": "preflight",
      "status": "done",
      "startedAt": "2026-11-11T18:21:24+03:00",
      "finishedAt": "2026-11-11T18:22:39+03:00"
    },
    {
      "id": "manifest",
      "status": "done",
      "startedAt": "2026-11-11T18:22:39+03:00",
      "finishedAt": "2026-11-11T18:25:02+03:00"
    },
    {
      "id": "briefing",
      "status": "done",
      "startedAt": "2026-11-11T18:25:02+03:00",
      "finishedAt": "2026-11-11T18:51:36+03:00"
    },
    {
      "id": "spec",
      "status": "done",
      "startedAt": "2026-11-11T18:51:36+03:00",
      "finishedAt": "2026-11-11T19:00:31+03:00"
    },
    {
      "id": "plan",
      "status": "done",
      "startedAt": "2026-11-11T19:00:31+03:00",
      "finishedAt": "2026-11-11T19:04:15+03:00"
    },
    {
      "id": "build",
      "status": "done",
      "startedAt": "2026-11-11T19:04:15+03:00",
      "finishedAt": "2026-11-11T21:43:32+03:00"
    },
    {
      "id": "review",
      "status": "done",
      "startedAt": "2026-11-11T21:43:32+03:00",
      "finishedAt": "2026-11-11T22:01:13+03:00"
    },
    {
      "id": "final",
      "status": "done",
      "startedAt": "2026-11-11T22:01:13+03:00",
      "finishedAt": "2026-11-11T22:10:39+03:00"
    }
  ],
  "requirements": {
    "total": 51,
    "done": 9,
    "inTicket": 0,
    "inSpec": 0,
    "placeholder": 0,
    "deferred": 42,
    "dropped": 0
  },
  "tickets": [
    {
      "id": "01",
      "title": "Складские остатки и ручные резервы",
      "requirements": [
        "G01",
        "G02",
        "G06",
        "G08",
        "G09"
      ],
      "blockedBy": [],
      "wave": 1,
      "zone": [
        "opencart/extension/cmteh/system/InventorySchema.php",
        "opencart/extension/cmteh/system/Inventory.php",
        "opencart/extension/cmteh/system/InventoryInput.php",
        "opencart/extension/cmteh/system/InventoryException.php",
        "opencart/tests/inventory-integration.php"
      ],
      "review": "нет — одна независимая проверка всей реализации по политике владельца",
      "model": "сильная",
      "status": "done",
      "startedAt": "2026-11-11T19:04:16+03:00",
      "finishedAt": "2026-11-11T19:21:51+03:00",
      "retries": 0,
      "repairs": 0,
      "tests": {
        "passed": 90,
        "failed": 0
      },
      "commit": "809b65b"
    },
    {
      "id": "02",
      "title": "Защита остатков при штатном редактировании",
      "requirements": [
        "G01",
        "G02"
      ],
      "blockedBy": [
        "01"
      ],
      "wave": 2,
      "zone": [
        "opencart/extension/cmteh/catalog/view/assets/inventory-native-admin.js",
        "opencart/tests/browser/native-inventory-form.mjs",
        "opencart/extension/cmteh/catalog/controller/event/cmteh_inventory.php",
        "opencart/extension/cmteh/catalog/model/checkout/inventory_guard.php",
        "opencart/extension/cmteh/system/InventoryNativeGuard.php",
        "opencart/extension/cmteh/admin/view/javascript/cmteh_inventory_guard.js",
        "opencart/extension/cmteh/admin/model/catalog/",
        "opencart/extension/cmteh/catalog/model/catalog/",
        "opencart/extension/cmteh/admin/controller/event/cmteh_catalog.php",
        "opencart/extension/cmteh/admin/controller/module/cmteh.php",
        "opencart/extension/cmteh/system/CatalogAdmin.php",
        "opencart/extension/cmteh/system/CatalogWrites.php",
        "opencart/extension/cmteh/system/NativeCatalog.php",
        "opencart/tests/native-inventory-integration.php"
      ],
      "review": "нет — одна независимая проверка всей реализации по политике владельца",
      "model": "сильная",
      "status": "done",
      "startedAt": "2026-11-11T19:22:06+03:00",
      "finishedAt": "2026-11-11T20:24:36+03:00",
      "retries": 0,
      "repairs": 2,
      "repairFindings": [
        "Native form summary link must use existing cmteh_catalog.inventory base route, not standalone cmteh_inventory (native permission denies it)",
        "D02 actualHTTP404 blocks native readonly script; move genericJS to existing allowedpublicassets and verify realformbrowser"
      ],
      "tests": {
        "passed": 55,
        "failed": 0
      },
      "commit": "02e5d88"
    },
    {
      "id": "03",
      "title": "Складская админка и загрузка Excel/CSV",
      "requirements": [
        "G01",
        "G02",
        "G06",
        "G07",
        "G08",
        "G09",
        "A01",
        "A02"
      ],
      "blockedBy": [
        "01"
      ],
      "wave": 2,
      "zone": [
        "opencart/tests/browser/inventory-import-happy.mjs",
        "opencart/tests/browser/inventory-import-fixture.php",
        "opencart/extension/cmteh/catalog/view/assets/inventory-admin.mjs",
        "opencart/extension/cmteh/admin/view/template/module/cmteh_catalog.twig",
        "opencart/tests/browser/inventory-admin.mjs",
        "opencart/extension/cmteh/admin/controller/module/cmteh_catalog.php",
        "opencart/extension/cmteh/admin/view/template/inventory/",
        "opencart/extension/cmteh/admin/view/assets/inventory/",
        "opencart/extension/cmteh/admin/controller/event/cmteh_admin.php",
        "opencart/extension/cmteh/system/InventoryImport.php",
        "opencart/tests/inventory-import-integration.php"
      ],
      "review": "нет — одна независимая проверка всей реализации по политике владельца",
      "model": "обычная",
      "status": "done",
      "startedAt": "2026-11-11T19:22:06+03:00",
      "finishedAt": "2026-11-11T20:44:51+03:00",
      "retries": 0,
      "repairs": 1,
      "repairFindings": [
        "Critical import browser happy path missing: valid XLSX→preview→apply→updated stock/price must work through actual authenticated HTTP, not only direct domain tests."
      ],
      "tests": {
        "passed": 61,
        "failed": 0
      },
      "commit": "53cc766"
    },
    {
      "id": "04",
      "title": "Предложения складов на сайте и в заявке",
      "requirements": [
        "G01",
        "G03",
        "G04",
        "G05",
        "G06",
        "G08",
        "G09"
      ],
      "blockedBy": [
        "01"
      ],
      "wave": 2,
      "zone": [
        "opencart/tests/browser/storefront.mjs",
        "opencart/extension/cmteh/system/Catalog.php",
        "opencart/extension/cmteh/system/Storefront.php",
        "opencart/extension/cmteh/system/StorefrontPages.php",
        "opencart/extension/cmteh/system/Lead.php",
        "opencart/extension/cmteh/system/LeadInput.php",
        "opencart/extension/cmteh/catalog/view/assets/cart.mjs",
        "opencart/extension/cmteh/catalog/view/assets/request.mjs",
        "opencart/extension/cmteh/catalog/view/assets/storefront.mjs",
        "opencart/extension/cmteh/catalog/view/assets/parts-list.mjs",
        "opencart/extension/cmteh/catalog/view/assets/request-import.mjs",
        "opencart/extension/cmteh/catalog/view/assets/storefront.css",
        "opencart/extension/cmteh/catalog/view/template/storefront/",
        "opencart/extension/cmteh/admin/view/template/module/cmteh_leads.twig",
        "opencart/tests/warehouse-offers-integration.php",
        "opencart/tests/browser/warehouse-offers.mjs"
      ],
      "review": "нет — одна независимая проверка всей реализации по политике владельца",
      "model": "сильная",
      "status": "done",
      "startedAt": "2026-11-11T19:22:06+03:00",
      "finishedAt": "2026-11-11T20:01:05+03:00",
      "retries": 0,
      "repairs": 1,
      "repairFindings": [
        "Visual acceptance390: warehouse choice is below generic product metadata; quantity1 repeats same price twice. Move managed-offer choice earlier, remove redundant managed delivery/price text."
      ],
      "tests": {
        "passed": 44,
        "failed": 0
      },
      "commit": "95a7b77"
    },
    {
      "id": "05",
      "title": "Проверка установленной сборки и руководство",
      "requirements": [
        "G01",
        "G02",
        "G03",
        "G04",
        "G05",
        "G06",
        "G07",
        "G08",
        "G09"
      ],
      "blockedBy": [
        "02",
        "03",
        "04"
      ],
      "wave": 3,
      "zone": [
        "opencart/tests/inventory-recovery-integration.php",
        "opencart/tests/browser/inventory-import-happy.mjs",
        "opencart/tests/browser/warehouse-offers.mjs",
        "opencart/extension/cmteh/admin/controller/module/cmteh.php",
        "opencart/dev/release_checks.py",
        "opencart/bin/verify-local",
        "opencart/tests/inventory-recovery.php",
        "opencart/admin-guide.md",
        "docs/warehouses.md",
        "docs/admin-capabilities-2026-11-11.md"
      ],
      "review": "нет — одна независимая проверка всей реализации по политике владельца",
      "model": "обычная",
      "status": "done",
      "startedAt": "2026-11-11T20:44:56+03:00",
      "finishedAt": "2026-11-11T21:43:19+03:00",
      "retries": 0,
      "repairs": 0,
      "tests": {
        "passed": 154,
        "failed": 0
      },
      "commit": "0da3d92"
    },
    {
      "id": "06",
      "title": "Подготовка безопасной проверки и выкладки на хостинг",
      "requirements": [
        "G01",
        "G05",
        "G07",
        "G09"
      ],
      "blockedBy": [
        "02",
        "03",
        "04"
      ],
      "wave": 3,
      "zone": [
        "D:/codex/temp/cmteh-warehouse-ops-20261111/"
      ],
      "review": "нет — единая независимая проверка product+ops перед исполнением",
      "model": "сильная",
      "status": "done",
      "startedAt": "2026-11-11T20:48:07+03:00",
      "finishedAt": "2026-11-11T21:43:31+03:00",
      "retries": 0,
      "repairs": 0,
      "tests": {
        "passed": 33,
        "failed": 0
      },
      "commit": "94d2c5e"
    },
    {
      "id": "F1",
      "title": "Исправления по итоговому ревью",
      "requirements": [
        "G01",
        "G04",
        "G07",
        "G08"
      ],
      "blockedBy": [],
      "wave": 4,
      "zone": [
        "opencart/extension/cmteh/admin/controller/module/cmteh_catalog.php",
        "opencart/tests/browser/inventory-import-happy.mjs",
        "D:/codex/temp/cmteh-warehouse-ops-20261111/proof-ops.py",
        "D:/codex/temp/cmteh-warehouse-ops-20261111/test-ops.py",
        "D:/codex/temp/cmteh-warehouse-ops-20261111/ops-prepared-index.json"
      ],
      "review": "да — ремонт ветки",
      "model": "сильная",
      "status": "done",
      "retries": 0,
      "repairs": 0,
      "startedAt": "2026-11-11T21:54:20+03:00",
      "tests": {
        "passed": 18,
        "failed": 0
      },
      "commit": "62eac91",
      "finishedAt": "2026-11-11T22:01:12+03:00"
    }
  ],
  "tests": {
    "passed": 1,
    "failed": 0
  },
  "debt": {
    "placeholders": [],
    "assumptions": [],
    "emptyEnv": []
  },
  "additions": [
    "A01→G01: фиксация подтверждённого завершённого перемещения парным движением, без логистического транзита",
    "A02→G09: полная отгрузка ручного резерва, без частичных отгрузок/коммерческого заказа"
  ],
  "coverage": {
    "found": 2,
    "fixed": 2,
    "deferred": 0,
    "items": [
      "G2: исключён полный dispatch/transit workflow; оставлено подтверждённое парное перемещение A01→G01",
      "G2: исключены частичные отгрузки; полная отгрузка резерва A02→G09"
    ]
  },
  "concerns": [
    "05: explicit initially-blocked InventorySchema fault injection NOTRUN; early12hooks-before-migrate source ordering and normal/repeat upgrade checked; no PASS claimed for this fault scenario.",
    "Recovery: old runtime binary manual overwrite is outside app guarantee. Reviewed06 artifact guard denies oldbaseline after ANY inventory table; compatible code recovery preserves DB."
  ],
  "blind": {
    "checked": 9,
    "matched": 9,
    "mismatches": []
  },
  "beats": [
    "2026-11-11T18:21:24+03:00",
    "2026-11-11T18:22:39+03:00",
    "2026-11-11T18:25:01+03:00",
    "2026-11-11T18:25:02+03:00",
    "2026-11-11T18:25:03+03:00",
    "2026-11-11T18:27:28+03:00",
    "2026-11-11T18:35:36+03:00",
    "2026-11-11T18:43:30+03:00",
    "2026-11-11T18:43:31+03:00",
    "2026-11-11T18:43:32+03:00",
    "2026-11-11T18:45:27+03:00",
    "2026-11-11T18:48:36+03:00",
    "2026-11-11T18:48:37+03:00",
    "2026-11-11T18:49:50+03:00",
    "2026-11-11T18:51:35+03:00",
    "2026-11-11T18:51:36+03:00",
    "2026-11-11T18:58:27+03:00",
    "2026-11-11T19:00:30+03:00",
    "2026-11-11T19:00:31+03:00",
    "2026-11-11T19:03:04+03:00",
    "2026-11-11T19:03:05+03:00",
    "2026-11-11T19:03:35+03:00",
    "2026-11-11T19:04:14+03:00",
    "2026-11-11T19:04:15+03:00",
    "2026-11-11T19:04:16+03:00",
    "2026-11-11T19:06:39+03:00",
    "2026-11-11T19:08:18+03:00",
    "2026-11-11T19:12:54+03:00",
    "2026-11-11T19:21:51+03:00",
    "2026-11-11T19:21:52+03:00",
    "2026-11-11T19:22:06+03:00",
    "2026-11-11T19:25:10+03:00",
    "2026-11-11T19:25:11+03:00",
    "2026-11-11T19:40:17+03:00",
    "2026-11-11T19:44:10+03:00",
    "2026-11-11T19:53:25+03:00",
    "2026-11-11T19:53:54+03:00",
    "2026-11-11T19:53:55+03:00",
    "2026-11-11T19:53:56+03:00",
    "2026-11-11T19:55:27+03:00",
    "2026-11-11T20:01:05+03:00",
    "2026-11-11T20:11:28+03:00",
    "2026-11-11T20:11:29+03:00",
    "2026-11-11T20:11:30+03:00",
    "2026-11-11T20:22:06+03:00",
    "2026-11-11T20:24:36+03:00",
    "2026-11-11T20:44:51+03:00",
    "2026-11-11T20:44:52+03:00",
    "2026-11-11T20:44:53+03:00",
    "2026-11-11T20:44:54+03:00",
    "2026-11-11T20:44:55+03:00",
    "2026-11-11T20:44:56+03:00",
    "2026-11-11T20:48:05+03:00",
    "2026-11-11T20:48:06+03:00",
    "2026-11-11T20:48:07+03:00",
    "2026-11-11T20:52:52+03:00",
    "2026-11-11T20:52:53+03:00",
    "2026-11-11T21:27:50+03:00",
    "2026-11-11T21:27:51+03:00",
    "2026-11-11T21:43:19+03:00",
    "2026-11-11T21:43:31+03:00",
    "2026-11-11T21:43:32+03:00",
    "2026-11-11T21:54:18+03:00",
    "2026-11-11T21:54:20+03:00",
    "2026-11-11T21:54:21+03:00",
    "2026-11-11T22:00:03+03:00",
    "2026-11-11T22:01:12+03:00",
    "2026-11-11T22:01:13+03:00",
    "2026-11-11T22:06:55+03:00",
    "2026-11-11T22:08:22+03:00",
    "2026-11-11T22:10:39+03:00"
  ],
  "rev": 78,
  "waiting": null,
  "waits": [
    {
      "kind": "briefing",
      "from": "2026-11-11T18:25:03+03:00",
      "to": "2026-11-11T18:43:30+03:00"
    },
    {
      "kind": "briefing",
      "from": "2026-11-11T18:43:32+03:00",
      "to": "2026-11-11T18:48:36+03:00"
    },
    {
      "kind": "briefing",
      "from": "2026-11-11T18:48:37+03:00",
      "to": "2026-11-11T18:51:35+03:00"
    }
  ],
  "log": [
    {
      "t": "2026-11-11T18:21:24+03:00",
      "k": "init"
    },
    {
      "t": "2026-11-11T18:22:39+03:00",
      "k": "stage",
      "id": "manifest",
      "act": "start"
    },
    {
      "t": "2026-11-11T18:25:02+03:00",
      "k": "stage",
      "id": "briefing",
      "act": "start"
    },
    {
      "t": "2026-11-11T18:25:03+03:00",
      "k": "ask",
      "kind": "briefing",
      "note": "Применимость условных возможностей отчёта, путь заказа/оплаты и видимость обращений менеджерам"
    },
    {
      "t": "2026-11-11T18:35:36+03:00",
      "k": "add",
      "key": "report",
      "n": 1
    },
    {
      "t": "2026-11-11T18:43:30+03:00",
      "k": "answered",
      "kind": "briefing"
    },
    {
      "t": "2026-11-11T18:43:32+03:00",
      "k": "ask",
      "kind": "briefing",
      "note": "Склады: источник остатков, публичный показ и ручной резерв. Остальные функции отложены пользователем."
    },
    {
      "t": "2026-11-11T18:45:27+03:00",
      "k": "add",
      "key": "report",
      "n": 1
    },
    {
      "t": "2026-11-11T18:48:36+03:00",
      "k": "answered",
      "kind": "briefing"
    },
    {
      "t": "2026-11-11T18:48:37+03:00",
      "k": "ask",
      "kind": "briefing",
      "note": "Подтверждены публичные предложения складов с отдельными ценами и сроками. Осталось: источник данных, способ задания срока, ручные резервы."
    },
    {
      "t": "2026-11-11T18:49:50+03:00",
      "k": "add",
      "key": "report",
      "n": 1
    },
    {
      "t": "2026-11-11T18:51:35+03:00",
      "k": "answered",
      "kind": "briefing"
    },
    {
      "t": "2026-11-11T18:51:36+03:00",
      "k": "gate",
      "g": "G1",
      "ok": false,
      "open": 8,
      "total": 51
    },
    {
      "t": "2026-11-11T18:51:36+03:00",
      "k": "stage",
      "id": "spec",
      "act": "start"
    },
    {
      "t": "2026-11-11T18:58:27+03:00",
      "k": "tier",
      "v": "T2"
    },
    {
      "t": "2026-11-11T19:00:30+03:00",
      "k": "gate",
      "g": "G2",
      "ok": true,
      "found": 2,
      "fixed": 2,
      "deferred": 0
    },
    {
      "t": "2026-11-11T19:00:31+03:00",
      "k": "stage",
      "id": "plan",
      "act": "start"
    },
    {
      "t": "2026-11-11T19:03:04+03:00",
      "k": "tickets",
      "n": 5
    },
    {
      "t": "2026-11-11T19:03:05+03:00",
      "k": "gate",
      "g": "G3",
      "ok": false,
      "problems": 5
    },
    {
      "t": "2026-11-11T19:03:35+03:00",
      "k": "tickets",
      "n": 5
    },
    {
      "t": "2026-11-11T19:03:35+03:00",
      "k": "gate",
      "g": "G3",
      "ok": true,
      "problems": 0
    },
    {
      "t": "2026-11-11T19:04:15+03:00",
      "k": "stage",
      "id": "build",
      "act": "start"
    },
    {
      "t": "2026-11-11T19:04:16+03:00",
      "k": "ticket",
      "ids": [
        "01"
      ],
      "act": "start"
    },
    {
      "t": "2026-11-11T19:06:39+03:00",
      "k": "add",
      "key": "report",
      "n": 1
    },
    {
      "t": "2026-11-11T19:06:39+03:00",
      "k": "gate",
      "g": "G3",
      "ok": true,
      "problems": 0
    },
    {
      "t": "2026-11-11T19:08:18+03:00",
      "k": "add",
      "key": "report",
      "n": 1
    },
    {
      "t": "2026-11-11T19:12:54+03:00",
      "k": "add",
      "key": "report",
      "n": 1
    },
    {
      "t": "2026-11-11T19:21:51+03:00",
      "k": "ticket",
      "ids": [
        "01"
      ],
      "act": "done",
      "tests": {
        "passed": 90,
        "failed": 0
      }
    },
    {
      "t": "2026-11-11T19:21:52+03:00",
      "k": "tickets",
      "n": 5
    },
    {
      "t": "2026-11-11T19:21:52+03:00",
      "k": "gate",
      "g": "G3",
      "ok": true,
      "problems": 0
    },
    {
      "t": "2026-11-11T19:22:06+03:00",
      "k": "ticket",
      "ids": [
        "02",
        "03",
        "04"
      ],
      "act": "start"
    },
    {
      "t": "2026-11-11T19:25:10+03:00",
      "k": "tickets",
      "n": 5
    },
    {
      "t": "2026-11-11T19:25:11+03:00",
      "k": "gate",
      "g": "G3",
      "ok": true,
      "problems": 0
    },
    {
      "t": "2026-11-11T19:40:17+03:00",
      "k": "add",
      "key": "report",
      "n": 1
    },
    {
      "t": "2026-11-11T19:44:10+03:00",
      "k": "ticket",
      "ids": [
        "02"
      ],
      "act": "repair",
      "note": "Native form summary link must use existing cmteh_catalog.inventory base route, not standalone cmteh_inventory (native permission denies it)"
    },
    {
      "t": "2026-11-11T19:53:25+03:00",
      "k": "ticket",
      "ids": [
        "02"
      ],
      "act": "done",
      "tests": {
        "passed": 51,
        "failed": 0
      }
    },
    {
      "t": "2026-11-11T19:53:54+03:00",
      "k": "ticket",
      "ids": [
        "04"
      ],
      "act": "done",
      "tests": {
        "passed": 43,
        "failed": 0
      }
    },
    {
      "t": "2026-11-11T19:53:55+03:00",
      "k": "tickets",
      "n": 5
    },
    {
      "t": "2026-11-11T19:53:56+03:00",
      "k": "gate",
      "g": "G3",
      "ok": true,
      "problems": 0
    },
    {
      "t": "2026-11-11T19:55:27+03:00",
      "k": "ticket",
      "ids": [
        "04"
      ],
      "act": "repair",
      "note": "Visual acceptance390: warehouse choice is below generic product metadata; quantity1 repeats same price twice. Move managed-offer choice earlier, remove redundant managed delivery/price text."
    },
    {
      "t": "2026-11-11T20:01:05+03:00",
      "k": "ticket",
      "ids": [
        "04"
      ],
      "act": "done",
      "tests": {
        "passed": 44,
        "failed": 0
      }
    },
    {
      "t": "2026-11-11T20:11:28+03:00",
      "k": "ticket",
      "ids": [
        "02"
      ],
      "act": "repair",
      "note": "D02 actualHTTP404 blocks native readonly script; move genericJS to existing allowedpublicassets and verify realformbrowser"
    },
    {
      "t": "2026-11-11T20:11:29+03:00",
      "k": "tickets",
      "n": 5
    },
    {
      "t": "2026-11-11T20:11:30+03:00",
      "k": "gate",
      "g": "G3",
      "ok": true,
      "problems": 0
    },
    {
      "t": "2026-11-11T20:22:06+03:00",
      "k": "ticket",
      "ids": [
        "03"
      ],
      "act": "repair",
      "note": "Critical import browser happy path missing: valid XLSX→preview→apply→updated stock/price must work through actual authenticated HTTP, not only direct domain tests."
    },
    {
      "t": "2026-11-11T20:24:36+03:00",
      "k": "ticket",
      "ids": [
        "02"
      ],
      "act": "done",
      "tests": {
        "passed": 55,
        "failed": 0
      }
    },
    {
      "t": "2026-11-11T20:44:51+03:00",
      "k": "ticket",
      "ids": [
        "03"
      ],
      "act": "done",
      "tests": {
        "passed": 61,
        "failed": 0
      }
    },
    {
      "t": "2026-11-11T20:44:52+03:00",
      "k": "add",
      "key": "additions",
      "n": 1
    },
    {
      "t": "2026-11-11T20:44:53+03:00",
      "k": "add",
      "key": "additions",
      "n": 1
    },
    {
      "t": "2026-11-11T20:44:54+03:00",
      "k": "tickets",
      "n": 5
    },
    {
      "t": "2026-11-11T20:44:55+03:00",
      "k": "gate",
      "g": "G3",
      "ok": true,
      "problems": 0
    },
    {
      "t": "2026-11-11T20:44:56+03:00",
      "k": "ticket",
      "ids": [
        "05"
      ],
      "act": "start"
    },
    {
      "t": "2026-11-11T20:48:05+03:00",
      "k": "tickets",
      "n": 6
    },
    {
      "t": "2026-11-11T20:48:06+03:00",
      "k": "gate",
      "g": "G3",
      "ok": true,
      "problems": 0
    },
    {
      "t": "2026-11-11T20:48:07+03:00",
      "k": "ticket",
      "ids": [
        "06"
      ],
      "act": "start"
    },
    {
      "t": "2026-11-11T20:52:52+03:00",
      "k": "tickets",
      "n": 6
    },
    {
      "t": "2026-11-11T20:52:53+03:00",
      "k": "gate",
      "g": "G3",
      "ok": true,
      "problems": 0
    },
    {
      "t": "2026-11-11T21:27:50+03:00",
      "k": "add",
      "key": "concerns",
      "n": 1
    },
    {
      "t": "2026-11-11T21:27:51+03:00",
      "k": "add",
      "key": "concerns",
      "n": 1
    },
    {
      "t": "2026-11-11T21:43:19+03:00",
      "k": "ticket",
      "ids": [
        "05"
      ],
      "act": "done",
      "tests": {
        "passed": 154,
        "failed": 0
      }
    },
    {
      "t": "2026-11-11T21:43:31+03:00",
      "k": "ticket",
      "ids": [
        "06"
      ],
      "act": "done",
      "tests": {
        "passed": 33,
        "failed": 0
      }
    },
    {
      "t": "2026-11-11T21:43:32+03:00",
      "k": "stage",
      "id": "review",
      "act": "start"
    },
    {
      "t": "2026-11-11T21:54:18+03:00",
      "k": "tickets",
      "n": 7
    },
    {
      "t": "2026-11-11T21:54:20+03:00",
      "k": "ticket",
      "ids": [
        "F1"
      ],
      "act": "start"
    },
    {
      "t": "2026-11-11T21:54:21+03:00",
      "k": "add",
      "key": "report",
      "n": 3
    },
    {
      "t": "2026-11-11T22:00:03+03:00",
      "k": "ticket",
      "ids": [
        "F1"
      ],
      "act": "review",
      "tests": {
        "passed": 18,
        "failed": 0
      }
    },
    {
      "t": "2026-11-11T22:01:12+03:00",
      "k": "ticket",
      "ids": [
        "F1"
      ],
      "act": "done",
      "tests": {
        "passed": 18,
        "failed": 0
      }
    },
    {
      "t": "2026-11-11T22:01:13+03:00",
      "k": "stage",
      "id": "final",
      "act": "start"
    },
    {
      "t": "2026-11-11T22:06:55+03:00",
      "k": "tests",
      "passed": 1,
      "failed": 0
    },
    {
      "t": "2026-11-11T22:08:22+03:00",
      "k": "gate",
      "g": "G4",
      "ok": true,
      "checked": 9,
      "matched": 9
    },
    {
      "t": "2026-11-11T22:10:39+03:00",
      "k": "finish",
      "result": "Склады приняты локально: strict PASS и слепая приёмка 9/9; фактическая выкладка и время — во внешнем handoff"
    }
  ],
  "reqList": [
    {
      "id": "R01",
      "text": "«регистрация, подтверждение контакта, вход, восстановление»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R02",
      "text": "«безопасный доступ к истории»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R03",
      "text": "«согласованная стоимость»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R04",
      "text": "«оплата»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R05",
      "text": "«доставка»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R06",
      "text": "«налоги»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R07",
      "text": "«статусы и возвраты»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R08",
      "text": "«ответственные»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R09",
      "text": "«этапы»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R10",
      "text": "«история изменений»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R11",
      "text": "«напоминания»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R12",
      "text": "«отдельное согласие, подтверждение подписки»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R13",
      "text": "«отписка и исключение адресов»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R14",
      "text": "«правила применения»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R15",
      "text": "«подтверждённые начисления и выплаты»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R16",
      "text": "«что считать просмотром, посетителем и результатом кампании»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R17",
      "text": "«как учитывать повторы и хранить данные»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R18",
      "text": "«Нужна модель фасетов, сопоставление полей, интерфейс выбора и правила сохранения URL»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R19",
      "text": "«варианты SKU, доступность, влияние на стоимость и snapshot выбранных значений»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R20",
      "text": "«Для новых типов нужны правила поиска, показа и импорта»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R21",
      "text": "«периоды, оплата, повторные списания и отмена»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R22",
      "text": "«Для продаваемых файлов нужны покупка, entitlement и защищённая выдача»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R23",
      "text": "«приёма, модерации, показа, защиты от спама»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R24",
      "text": "«при необходимости тематик надо спроектировать сопоставление и перенос с сохранением URL»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R25",
      "text": "«приём и модерация комментариев»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R26",
      "text": "«Новый редактор требует точного сопоставления полей с используемым шаблоном»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R27",
      "text": "«Для мультиязычия нужно определить языки, переводы содержимого, маршруты и fallback»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R28",
      "text": "«новый тип баннера требует field mapping»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R29",
      "text": "«группы, approval, обязательные поля, согласия и их потребителей»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R30",
      "text": "«сроки хранения, идентификацию заявителя, исключения, удаление private files и аудит»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R31",
      "text": "«правила адресации и отметки прочтения»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R32",
      "text": "«вступление/проверка партнёра, договорённые ставки, подтверждённый заказ»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R33",
      "text": "«first/last touch, согласия/сроки хранения, защита подмены и связь с отправкой»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R34",
      "text": "«Новый antifraud требует модели угроз, данных и явного включения»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R35",
      "text": "«Новая интеграция требует конкретного потребителя, аутентификации, прав и аудита»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R36",
      "text": "«Много филиалов требует сопоставления адресов и публичных страниц»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R37",
      "text": "«Для нового публичного поведения нужны переводы/цены/явное отображение и проверка импорта»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R38",
      "text": "«адреса, зоны обслуживания, доставка и форматирование»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R39",
      "text": "«Создать группу с access только нужных разделов; modify выдавать отдельно»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R40",
      "text": "«Штатные отчёты читают native activity/customer/coupon и их историю»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R41",
      "text": "«Просмотры и поиск требуют реального instrumentation»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "R42",
      "text": "«Нужна проверенная аналитика присутствия/просмотров»",
      "status": "deferred",
      "why": "По решению пользователя: «Да нам нужны склады, остальное оставим на будущее»"
    },
    {
      "id": "G01",
      "text": "«Да нам нужны склады»",
      "status": "done"
    },
    {
      "id": "G02",
      "text": "«остальное оставим на будущее»",
      "status": "done"
    },
    {
      "id": "G03",
      "text": "«клиентам тоже показывать склады»",
      "status": "done"
    },
    {
      "id": "G04",
      "text": "«от этого будет зависеть срок поставки»",
      "status": "done"
    },
    {
      "id": "G05",
      "text": "«одна и та же запчасть»",
      "status": "done"
    },
    {
      "id": "G06",
      "text": "«в наличии в Питере, цена 1000 рублей, наличие в Благовещенске цена 850»",
      "status": "done"
    },
    {
      "id": "G07",
      "text": "«Нужна загрузка из Excel/CSV»",
      "status": "done"
    },
    {
      "id": "G08",
      "text": "«Вручную для склада и при необходимости для товара (рекомендую)»",
      "status": "done"
    },
    {
      "id": "G09",
      "text": "«Да, ручные резервы (рекомендую)»",
      "status": "done"
    }
  ],
  "gates": {
    "G1": {
      "ok": false,
      "at": "2026-11-11T18:51:36+03:00",
      "open": 8,
      "total": 51
    },
    "G2": {
      "ok": true,
      "at": "2026-11-11T19:00:30+03:00",
      "found": 2,
      "fixed": 2,
      "deferred": 0
    },
    "G3": {
      "ok": true,
      "at": "2026-11-11T20:52:53+03:00",
      "problems": 0
    },
    "G4": {
      "ok": true,
      "at": "2026-11-11T22:08:22+03:00",
      "checked": 9,
      "matched": 9
    }
  },
  "result": "Склады приняты локально: strict PASS и слепая приёмка 9/9; фактическая выкладка и время — во внешнем handoff",
  "requestStartedAt": "2026-11-11T18:18:06+03:00",
  "report": [
    "Read-only разбор завершён: auth-scout.md, orders-crm-scout.md, marketing-scout.md и discovery-summary.md. Продуктовый код и хостинг не менялись; ожидаются ответы на три бизнес-вопроса.",
    "Текущий объём: только склады. Прежние функции отложены владельцем. Warehouse discovery выявил защиту агрегата от старых native форм; ожидаются источник остатков, публичный показ и ручные резервы.",
    "Склады видны покупателю: отдельные предложения одного товара с ценой и сроком. Выбор склада сохраняется в корзине/RFQ; старые позиции совместимы. Города и суммы пользователя — пример, не данные для публикации.",
    "Разработка01: локальный runtime3190 готов, initial realMySQL RED зафиксирован до кода. Реальный сайт и почта не менялись.",
    "Read-only hostpreflight: PHP8.2.35; пять nativecorefiles rawSHA совпали с pinnedZIP. Без DB/config/mail/deploy действий; это не тест склада.",
    "Дополнительный isolated runtime3191 готов: SELECT VERSION MySQL5.7.44/PHP8.3.36. Без изменений source compose/verifier/хостинга.",
    "04 промежуточно: realbrowser A+B→cart→stale409→lostack/replay PASS; 02 nativeguard checks идут наinstalledruntime. Финальная проверка установленного пакета и хостинга ещё не выполнена.",
    "При более чем 50 складах список и закрытие их резервов требуют доработки пагинации.",
    "Сортировка по цене пока учитывает также отсутствующие предложения; контракт visible/available требует отдельного исправления.",
    "Формула в текстовом сроке CSV сохраняется как обычный текст; автоматического исполнения нет, обещанная проверка формул охватывает не все поля."
  ]
}
