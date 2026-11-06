"""Acceptance checks for a disposable, loopback-only OpenCart runtime."""

import hashlib
import html
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import subprocess
import sys
from urllib.parse import parse_qs, urlencode, urlsplit
from urllib.request import Request
from uuid import uuid4

from runtime import LocalOpenCart

ROOT = Path(__file__).resolve().parents[1]
CONFIG = "/var/www/html/config.php"
CATALOG = "extension/cmteh/module/cmteh_catalog"
LEADS = "extension/cmteh/module/cmteh_leads"
MODULE = "extension/cmteh/module/cmteh"
FORM = "application/x-www-form-urlencoded"
PAGES = ("home", "catalog", "product", "request", "brands", "articles")
WIDTHS = (1440, 1024, 390)


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def run_php_suite(runtime, name):
    runtime.docker("exec", "-T", "web", "php", "/workspace/tests/" + name)


def lint(runtime):
    runtime.docker(
        "exec", "-T", "web", "bash", "-o", "pipefail", "-ec",
        "find /workspace -name '*.php' -type f -print0 | xargs -0 -n1 php -l | "
        "awk '/^No syntax errors detected/ { good++; next } { print; bad++ } "
        "END { print good+0, \"PHP files linted\"; exit (bad > 0 || good == 0) }'",
    )


def import_fixture(runtime, source, expected_added, key):
    command = ["exec", "-T", "web", "php", "/workspace/bin/import-catalog.php",
               "--config=" + CONFIG, "--input=" + source]
    preview = json.loads(runtime.docker(*command, "--preview", capture=True).stdout)
    require(preview.get("errors") == 0 and preview.get("added") == expected_added,
            "The clean-catalog import preview did not match the expected input.")
    checksum = preview.get("sha256", "")
    require(isinstance(checksum, str) and re.fullmatch(r"[0-9a-f]{64}", checksum),
            "The import preview did not produce a SHA-256.")
    applied = json.loads(runtime.docker(
        *command, "--apply", "--confirm=" + checksum, "--run-key=" + key, capture=True,
    ).stdout)
    require(applied.get("errors") == 0 and applied.get("added") == expected_added,
            "The catalog import did not apply the entire confirmed fixture.")
    print(f"PASS: catalog preview and confirmed import ({expected_added} rows)", flush=True)
    return {"preview": preview, "applied": applied}


def admin_post(session, route, fields):
    return session.request_json(session.action(route), urlencode(fields, doseq=True).encode("ascii"), FORM)


def create_role(admin, label, access, modify):
    group = admin_post(admin, "user/user_permission.save", {
        "name": label + " gate " + uuid4().hex[:6],
        "permission[access][]": access,
        "permission[modify][]": modify,
    })
    require(group.get("success") and int(group.get("user_group_id", 0)) > 0,
            "OpenCart did not create the test permission group.")
    username = "gate-" + uuid4().hex[:12]
    password = secrets.token_hex(12)
    created = admin_post(admin, "user/user.save", {
        "username": username, "firstname": "Gate", "lastname": "Test",
        "email": username + "@example.test", "password": password, "confirm": password,
        "user_group_id": group["user_group_id"], "status": "1",
    })
    require(created.get("success"), "OpenCart did not create the test user.")

    session = LocalOpenCart(admin.directory, admin.port)
    code, page = session.request(session.action("common/login"))
    login = re.search(r'<form id="form-login" action="([^"]+)"', page)
    require(code == 200 and login, "OpenCart did not show the native login.")
    body = urlencode({"username": username, "password": password}).encode("ascii")
    result = session.request_json(login[1], body, FORM)
    tokens = parse_qs(urlsplit(html.unescape(result.get("redirect", ""))).query).get("user_token", [])
    require(len(tokens) == 1 and re.fullmatch(r"[A-Za-z0-9]+", tokens[0]),
            "OpenCart did not establish an authenticated test-role session.")
    session.token = tokens[0]
    return session


def submit_lead(runtime, label):
    body = json.dumps({
        "request_key": str(uuid4()), "name": "Release test " + label,
        "email": "release@example.test", "message": "Synthetic release acceptance",
        "page_url": runtime.origin + "/request",
        "request_items": [{"article": "0000550380", "quantity": 3}],
    }).encode("utf-8")
    request = Request(runtime.origin + "/api/lead", body, {
        "Content-Type": "application/json", "Origin": runtime.origin,
    })
    with runtime.http.open(request, timeout=15) as response:
        require(response.status == 200, "The application did not acknowledge the synthetic lead.")
        result = json.load(response)
    lead_id = result.get("id", "")
    require(isinstance(lead_id, str) and re.fullmatch(r"[0-9a-f-]{36}", lead_id)
            and result.get("replayed") is False, "Lead acknowledgement is invalid.")
    return lead_id


def lead_visible(manager, lead_id):
    code, page = manager.request(manager.action(LEADS, id=lead_id))
    require(code == 200 and lead_id in page and "0000550380" in page and "В очереди" in page,
            "The manager could not read the accepted lead, its items and notification status.")


def admin_roles(runtime, lead_id):
    runtime.login((runtime.directory / "admin-password").read_text(encoding="ascii").strip())
    sessions = []
    try:
        operator = create_role(runtime, "Catalog operator", [MODULE, CATALOG], [CATALOG])
        sessions.append(operator)
        manager = create_role(runtime, "Lead manager", [MODULE, LEADS], [])
        sessions.append(manager)
        readonly = create_role(runtime, "Catalog reader", [CATALOG], [])
        sessions.append(readonly)

        code, body = LocalOpenCart(runtime.directory, runtime.port).request(
            "/admin/index.php?route=" + LEADS + "&id=" + lead_id,
        )
        private_fields = ("0000550380", "Synthetic release acceptance")
        require(code == 200 and 'id="form-login"' in body and not any(field in body for field in private_fields),
                "An anonymous browser received a protected lead.")
        code, body = operator.request(operator.action(LEADS, id=lead_id))
        require(code in (401, 403) and not any(field in body for field in private_fields),
                "A catalog operator could open a lead.")
        require(manager.request(manager.action(CATALOG))[0] in (401, 403),
                "A lead manager could open the catalog editor.")
        require(operator.request(operator.action(MODULE + ".upgrade"), b"", FORM)[0] == 403,
                "An operator could execute the administrator's migration action.")
        code, page = manager.request(manager.action(LEADS))
        require(code == 200 and lead_id in page and "В очереди" in page,
                "The manager could not list an accepted lead.")
        lead_visible(manager, lead_id)
        print("PASS: native OpenCart sessions separate catalog, lead and migration permissions", flush=True)

        slug = "release-check-" + uuid4().hex[:8]
        title = "Проверочная публикация " + slug
        fields = {"id": slug, "slug": slug, "title": title, "h1": title, "content": "<p>Синтетика</p>",
                  "status": "draft", "is_indexable": "0", "details": "{}"}

        def save(session, status, **query):
            payload = urlencode(fields | {"status": status}).encode("ascii")
            return session.request(session.action(CATALOG, type="page", **query), payload, FORM)

        code, body = operator.request(operator.action(CATALOG, type="page", new="1"))
        require(code == 200 and 'name="details"' in body, "The operator could not open the catalog editor.")
        require(save(readonly, "published")[0] == 403 and runtime.request("/" + slug)[0] == 404,
                "Access without modify permission allowed a catalog mutation.")
        for state, expected in (("draft", 404), ("published", 200), ("archived", 404)):
            code, body = save(operator, state, **({"id": slug} if state != "draft" else {}))
            require(code == 200 and "Запись сохранена" in body, f"Operator could not save {state} via the admin form.")
            code, body = runtime.request("/" + slug)
            require(code == expected and (expected != 200 or title in body),
                    f"The public page does not reflect the {state} admin action.")
        print("PASS: native editor creates draft, publishes and archives; access alone cannot save", flush=True)
    finally:
        for session in sessions:
            session.logout()
        runtime.logout()


def verify_media(runtime):
    fixture = runtime.directory / "media-fixture.ndjson"
    media = (
        ("00000000-0000-4000-8000-000000000161", "gate-public.png", "published"),
        ("00000000-0000-4000-8000-000000000162", "gate-private.png", "draft"),
    )
    tiny_png = "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aKZ0AAAAASUVORK5CYII="
    runtime.docker("exec", "-T", "web", "sh", "-ec",
                   'install -d -m 0755 /var/www/cmteh-private/media; for target in gate-public gate-private; do '
                   'printf %s "$1" | base64 -d > "/var/www/cmteh-private/media/$target.png"; done', "media", tiny_png)
    fixture.write_text("".join(json.dumps({
        "type": "media", "data": {"uuid": uuid, "path": name, "filename": name, "mime": "image/png",
                                    "status": status, "owners": [{"type": "product", "id": "mock-11"}]},
    }) + "\n" for uuid, name, status in media), encoding="utf-8")
    reports = import_fixture(runtime, "/runtime/media-fixture.ndjson", 2, "release-media")
    public = "/api/assets/" + media[0][0]
    with runtime.http.open(runtime.origin + public, timeout=15) as response:
        require(response.status == 200 and response.headers.get_content_type() == "image/png"
                and response.read().startswith(b"\x89PNG\r\n\x1a\n"), "The approved raster file did not stream.")
    denied = ("/api/assets/" + media[1][0], "/api/assets/00000000-0000-4000-8000-000000000163",
              "/cmteh-private/media/gate-private.png", "/media/gate-private.png")
    for path in denied:
        require(runtime.request(path)[0] == 404, "A private or unknown media route did not return 404.")
    print("PASS: approved media streams; draft, unknown and direct-file paths return 404", flush=True)
    return reports


def check_recovery(runtime, upstream, lead_id):
    runtime.login((runtime.directory / "admin-password").read_text(encoding="ascii").strip())
    try:
        manager = create_role(runtime, "Recovery manager", [LEADS], [])
    finally:
        runtime.logout()
    archive = "export MYSQL_PWD=\"$(cat /runtime/db-root-password)\"; umask 077; "
    dump = "mysqldump -uroot --single-transaction --no-tablespaces --set-gtid-purged=OFF cmteh_dev"
    try:
        lead_visible(manager, lead_id)
        runtime.docker("exec", "-T", "db", "sh", "-ec",
                       archive + dump + " >/tmp/cmteh-recovery-earlier.sql")
        late = submit_lead(runtime, "после снимка")
        runtime.start(upstream, refresh=True)
        lead_visible(manager, lead_id)
        lead_visible(manager, late)
    finally:
        manager.logout()

    runtime.docker("exec", "-T", "db", "sh", "-ec", archive + dump + " >/tmp/cmteh-recovery-latest.sql\n" + """
        old=cmteh_verify_earlier_$3
        new=cmteh_verify_latest_$3
        mysql -uroot -e "CREATE DATABASE $old CHARACTER SET utf8mb4; CREATE DATABASE $new CHARACTER SET utf8mb4"
        trap 'mysql -uroot -e "DROP DATABASE $old; DROP DATABASE $new"; rm -f /tmp/cmteh-recovery-earlier.sql /tmp/cmteh-recovery-latest.sql' EXIT
        mysql -uroot "$old" </tmp/cmteh-recovery-earlier.sql
        mysql -uroot "$new" </tmp/cmteh-recovery-latest.sql
        query="SELECT COUNT(*) FROM oc_cmteh_lead l JOIN oc_cmteh_lead_item i USING (lead_id) JOIN oc_cmteh_lead_outbox o USING (lead_id) WHERE i.article='0000550380' AND i.quantity=3 AND l.public_id="
        test "$(mysql -uroot -NB "$old" -e "$query '$1'")" = 1
        test "$(mysql -uroot -NB "$old" -e "$query '$2'")" = 0
        test "$(mysql -uroot -NB "$new" -e "$query '$1'")" = 1
        test "$(mysql -uroot -NB "$new" -e "$query '$2'")" = 1
        test "$(mysql -uroot -NB cmteh_dev -e "$query '$2'")" = 1
    """, "recovery", lead_id, late, uuid4().hex[:12])
    runtime.docker("exec", "-T", "web", "sh", "-ec", """
        scratch=$(mktemp -d /tmp/cmteh-release-media.XXXXXX)
        trap 'rm -rf "$scratch"' EXIT
        tar -C /var/www/cmteh-private -cf "$scratch/media.tar" media
        mkdir "$scratch/restored"
        tar -C "$scratch/restored" -xf "$scratch/media.tar"
        cmp /var/www/cmteh-private/media/gate-public.png "$scratch/restored/media/gate-public.png"
        cmp /var/www/cmteh-private/media/gate-private.png "$scratch/restored/media/gate-private.png"
    """)
    print("PASS: repeat migration and isolated DB/media recovery preserve the lead received after the first backup",
          flush=True)


def collect_browser(runtime, tooling, reference, evidence):
    browser_dir = ROOT / "tests" / "browser"
    env = os.environ | {"CMTEH_BROWSER_BASE": runtime.origin, "CMTEH_BROWSER_TOOLING": str(tooling),
                        "CMTEH_BROWSER_EVIDENCE": str(evidence)}
    for name in ("storefront.mjs", "capture.mjs"):
        subprocess.run(["node", str(browser_dir / name)], env=env, check=True)

    pairs = []
    for width in WIDTHS:
        for page in PAGES:
            stem = f"{page}-{width}.png"
            original = reference / ("reference-" + stem)
            shutil.copyfile(original, evidence / original.name)
            pairs.append(f'<section><h2>{page} · {width}px</h2><div>'
                         f'<img loading="lazy" width="{width}" src="reference-{stem}" alt="Эталон">'
                         f'<img loading="lazy" width="{width}" src="candidate-{stem}" alt="OpenCart"></div></section>')
    (evidence / "visual-comparison.html").write_text(
        '<!doctype html><html lang="ru"><meta charset="utf-8"><title>СМ ТЕХНО · сравнение</title>'
        '<style>body{margin:2rem;font:1rem system-ui}section{margin:2rem 0}'
        'section div{display:grid;grid-template-columns:1fr 1fr;gap:1rem;align-items:start}'
        'img{max-width:100%;height:auto;border:1px solid #ccc}</style>'
        '<h1>СМ ТЕХНО · 18 пар снимков</h1><p>Слева Next.js 61a10e2. Справа OpenCart.</p>'
        + "".join(pairs) + '</html>\n', encoding="utf-8",
    )


def preflight(tooling, reference):
    require((tooling / "node_modules" / "playwright").is_dir(), "Install locked Playwright tooling before the gate.")
    for width in WIDTHS:
        for page in PAGES:
            path = reference / f"reference-{page}-{width}.png"
            require(path.is_file(), f"Missing reference capture: {path.name}")
            with path.open("rb") as stream:
                require(stream.read(8) == b"\x89PNG\r\n\x1a\n", f"Invalid reference PNG: {path.name}")


def run_http(runtime, evidence):
    command = [sys.executable, str(ROOT / "tests" / "http-storefront.py"), "--base", runtime.origin]
    result = subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                            env=os.environ | {"PYTHONIOENCODING": "utf-8"},
                            text=True, encoding="utf-8", check=False)
    (evidence / "http-storefront.txt").write_text(result.stdout, encoding="utf-8")
    print(result.stdout, end="", flush=True)
    result.check_returncode()


def sha256(path):
    with path.open("rb") as stream:
        digest = hashlib.sha256()
        for part in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(part)
    return digest.hexdigest()
