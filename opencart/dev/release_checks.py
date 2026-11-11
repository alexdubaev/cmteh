"""Acceptance checks for a disposable, loopback-only OpenCart runtime."""

import hashlib
import base64
from email import policy
from email.parser import BytesParser
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
from zipfile import ZipFile

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


def import_fixture(runtime, source, expected_added, key, expected_updated=0):
    command = ["exec", "-T", "web", "php", "/workspace/bin/import-catalog.php",
               "--config=" + CONFIG, "--input=" + source]
    preview = json.loads(runtime.docker(*command, "--preview", capture=True).stdout)
    require(preview.get("errors") == 0 and preview.get("added") == expected_added
            and preview.get("updated") == expected_updated,
            "The clean-catalog import preview did not match the expected input.")
    checksum = preview.get("sha256", "")
    require(isinstance(checksum, str) and re.fullmatch(r"[0-9a-f]{64}", checksum),
            "The import preview did not produce a SHA-256.")
    applied = json.loads(runtime.docker(
        *command, "--apply", "--confirm=" + checksum, "--run-key=" + key, capture=True,
    ).stdout)
    require(applied.get("errors") == 0 and applied.get("added") == expected_added
            and applied.get("updated") == expected_updated,
            "The catalog import did not apply the entire confirmed fixture.")
    print(f"PASS: catalog preview and confirmed import ({expected_added} added, {expected_updated} updated)", flush=True)
    return {"preview": preview, "applied": applied}


def admin_post(session, route, fields):
    return session.request_json(session.action(route), urlencode(fields, doseq=True).encode("ascii"), FORM)


def create_role(admin, label, access, modify, *, password_file=None):
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
    if password_file is not None:
        password_file.write_text(password + "\n", encoding="ascii")
        password_file.chmod(0o600)

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
    session.username = username
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


def quote_acceptance(runtime, package):
    try:
        _quote_acceptance(runtime, package)
    except (ValueError, OSError) as exc:
        # Native URLs contain session tokens: never propagate transport URLs to logs.
        raise RuntimeError("Quote acceptance transport failed (" + type(exc).__name__ + ").") from None


def _quote_acceptance(runtime, package):
    """Real native ACL, immutable MIME and the CLI bytes extracted from the candidate."""
    original = b"%PDF-1.4\n% Synthetic private quote\n1 0 obj\n<<>>\nendobj\n%%EOF\n"
    filename = "Потребность № 01.pdf"
    payload = {"request_key": str(uuid4()), "name": "<Quote> Example", "email": "quote@example.test",
               "message": "Первая строка\nВторая строка", "input_kind": "file",
               "file": {"filename": filename, "data_base64": base64.b64encode(original).decode("ascii")}}
    code, body = runtime.request("/api/quote", json.dumps(payload).encode(), "application/json")
    result = json.loads(body)
    require(code == 200 and result.get("replayed") is False and set(result) == {"id", "replayed"},
            "Public quote API did not persist a private original.")
    quote_id = result["id"]
    code, body = runtime.request("/api/quote", json.dumps(payload).encode(), "application/json")
    require(code == 200 and json.loads(body) == {"id": quote_id, "replayed": True}, "HTTP quote replay changed identity.")
    text = {"request_key": str(uuid4()), "name": "Quote text", "phone": "+70000000000",
            "input_kind": "text", "list_text": "001A;2\n002;3"}
    require(runtime.request("/api/quote", json.dumps(text).encode(), "application/json")[0] == 200,
            "HTTP text quote was not accepted.")
    require(runtime.request("/api/quote")[0] == 405
            and runtime.request("/api/quote", b"x", "text/plain")[0] == 415
            and runtime.request("/api/quote", b" " * 8388609, "application/json")[0] == 413,
            "Quote method/type/encoded limits failed.")
    request = Request(runtime.origin + "/api/quote", json.dumps(text).encode(),
                      {"Content-Type": "application/json", "Origin": "https://other.example.test"})
    try:
        runtime.http.open(request, timeout=15)
        raise RuntimeError("Cross-origin quote was accepted.")
    except __import__("urllib.error", fromlist=["HTTPError"]).HTTPError as exc:
        require(exc.code == 403, "Cross-origin quote did not return 403.")
    runtime.login((runtime.directory / "admin-password").read_text(encoding="ascii").strip())
    sessions = []
    try:
        reader = create_role(runtime, "Quote reader", [LEADS], [])
        editor = create_role(runtime, "Quote manager", [LEADS], [LEADS])
        outsider = create_role(runtime, "Quote outsider", [CATALOG], [])
        sessions += [reader, editor, outsider]
        def action(session, verb="", **fields):
            return session.action(LEADS, type="quote", id=quote_id, action=verb, **fields)
        anonymous = LocalOpenCart(runtime.directory, runtime.port)
        code, body = anonymous.request("/admin/index.php?" + urlencode({"route": LEADS, "type": "quote", "id": quote_id, "action": "download"}))
        require(code in (200, 401, 403) and "Synthetic private quote" not in body and filename not in body,
                "Anonymous visitor received private quote bytes.")
        code, body = outsider.request(action(outsider, "download"))
        require(code in (401, 403) and "Synthetic private quote" not in body, "Unprivileged role received private quote bytes.")
        code, page = reader.request(action(reader))
        require(code == 200 and "&lt;Quote&gt; Example" in page and "<Quote> Example" not in page
                and filename in page and "В очереди" in page, "Native quote detail failed or leaked unescaped contact input.")
        with reader.http.open(runtime.origin + html.unescape(action(reader, "download")), timeout=15) as response:
            require(response.read() == original and response.headers.get_content_type() == "application/pdf"
                    and "no-store" in response.headers.get("Cache-Control", "")
                    and response.headers.get("X-Content-Type-Options") == "nosniff"
                    and response.headers.get("Content-Disposition", "").startswith("attachment;"),
                    "Native download changed bytes or omitted private attachment headers.")
        require(reader.request(action(reader, "deleteAttachment"), b"", FORM)[0] == 403,
                "Read-only quote role could delete an original.")
        require(reader.request(action(reader, "retry"), b"", FORM)[0] == 403,
                "Read-only quote role could retry a job.")
        for token in (None, uuid4().hex):
            fields = {"route": LEADS, "type": "quote", "id": quote_id, "action": "download"}
            if token is not None:
                fields["user_token"] = token
            code, denied = reader.request("/admin/index.php?" + urlencode(fields))
            require("Synthetic private quote" not in denied and filename not in denied,
                    "Missing or invalid native token exposed an original.")
        require(editor.request(action(editor, "deleteAttachment"), b"", FORM)[0] == 409,
                "Pending original could be deleted.")
        require(editor.request(action(editor, "retry"), b"", FORM)[0] == 409,
                "Pending job could be retried manually.")
        set_failed = ("require '/var/www/html/config.php';require DIR_EXTENSION.'cmteh/system/Database.php';"
                      "$p=\\Cmteh\\Database::connectFromOpenCart();"
                      "$s=$p->prepare('UPDATE '.DB_PREFIX.'cmteh_quote_outbox o JOIN '.DB_PREFIX."
                      "'cmteh_quote q ON q.quote_id=o.quote_id SET o.status=\\'failed\\',o.attempts=3 WHERE q.public_id=?');"
                      "$s->execute(['" + quote_id + "']);")
        runtime.docker("exec", "-T", "web", "php", "-r", set_failed, capture=True)
        code, retried = editor.request(action(editor, "retry"), b"", FORM)
        require(code == 200 and "Ожидает повторной отправки" in retried,
                "Authorized failed-job retry did not preserve the original and restore the queue.")
        with ZipFile(package) as archive:
            cli = archive.read("cli/send-quotes.php")
        require(cli == (ROOT / "bin/send-quotes.php").read_bytes(), "Candidate quote CLI differs from verified source.")
        (runtime.directory / "verified-send-quotes.php").write_bytes(cli)
        captured = "http-quote-" + uuid4().hex[:12] + ".eml"
        counts = json.loads(runtime.docker("exec", "-T", "web", "php", "/runtime/verified-send-quotes.php",
                                          "--config=" + CONFIG, "--capture=" + captured, "--limit=10", capture=True).stdout)
        require(counts.get("sent", 0) >= 2, "Packaged CLI did not process the saved text and file quote.")
        capture_script = ("require '/var/www/html/config.php';require DIR_EXTENSION.'cmteh/system/Database.php';"
                          "echo file_get_contents(\\Cmteh\\Database::privateDir().'/quote-test-capture/" + captured + "');")
        captured_raw = runtime.docker("exec", "-T", "web", "php", "-r", capture_script, capture=True).stdout.encode()
        captured_message = BytesParser(policy=policy.default).parsebytes(captured_raw)
        require([(address.display_name, address.addr_spec) for address in captured_message["From"].addresses]
                == [("СМ ТЕХНО", "info@cmteh.ru")],
                "Packaged CLI capture did not use the approved decoded sender name and address.")
        # Capture each saved quote independently through its immutable service DTO, no external transport.
        script = ("require '/var/www/html/config.php';require DIR_EXTENSION.'cmteh/system/Quote.php';"
                  "require DIR_EXTENSION.'cmteh/system/QuoteMail.php';"
                  "$q=new \\Cmteh\\Quote(\\Cmteh\\Database::connectFromOpenCart(),DB_PREFIX);"
                  "$v=$q->find('" + quote_id + "');$v['attachment']=$q->attachment('" + quote_id + "');"
                  "echo \\Cmteh\\QuoteMail::message($v,['from'=>'info@cmteh.ru','to'=>'info@cmteh.ru','from_name'=>'СМТЕХНО']);")
        raw = runtime.docker("exec", "-T", "web", "php", "-r", script, capture=True).stdout.encode()
        message = BytesParser(policy=policy.default).parsebytes(raw)
        files = list(message.iter_attachments())
        require(len(files) == 1 and files[0].get_filename() == filename and files[0].get_payload(decode=True) == original
                and message["From"].addresses[0].addr_spec == "info@cmteh.ru"
                and message["To"].addresses[0].addr_spec == "info@cmteh.ru"
                and message["Reply-To"].addresses[0].addr_spec == "quote@example.test"
                and "Первая строка\nВторая строка" in message.get_body(preferencelist=("plain",)).get_content(),
                "Independent MIME parser did not recover original UTF-8 name, bytes, contacts and multiline comment.")
        require(reader.request(action(reader, "deleteAttachment"), b"", FORM)[0] == 403,
                "Read-only role could delete an accepted file.")
        old_opener = reader.http
        old_download = runtime.origin + html.unescape(action(reader, "download"))
        reader.logout()
        with old_opener.open(old_download, timeout=15) as response:
            revoked_body = response.read()
            require(original not in revoked_body and b'form-login' in revoked_body,
                    "Logged-out cookie and token still authorized a quote download.")
        require(editor.request(action(editor, "deleteAttachment"))[0] == 405,
                "GET could mutate an accepted attachment.")
        code, page = editor.request(action(editor, "deleteAttachment"), b"", FORM)
        require(code == 200 and "Вложение удалено сотрудником" in page,
                "Authorized explicit deletion did not preserve quote history.")
        require(editor.request(action(editor, "download"))[0] == 404,
                "Deleted original remained downloadable.")
        print("PASS: public quote HTTP, native access/modify privacy, parsed original MIME and packaged CLI capture", flush=True)
    finally:
        for session in sessions:
            session.logout()
        runtime.logout()


def admin_roles(runtime, lead_id):
    admin_navigation(runtime)
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
                  "status": "draft", "is_indexable": "0"}

        def save(session, status, **query):
            payload = urlencode(fields | {"status": status}).encode("ascii")
            return session.request(session.action(CATALOG, type="page", **query), payload, FORM)

        code, body = operator.request(operator.action(CATALOG, type="page", new="1"))
        require(code == 200 and 'name="title"' in body, "The operator could not open the catalog editor.")
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


def admin_navigation(runtime):
    """Exercise installed event hooks, real native screens and role-specific links."""
    anonymous = LocalOpenCart(runtime.directory, runtime.port)
    code, login = anonymous.request(anonymous.action("common/login"))
    require(code == 200 and "Вход в панель управления" in login and "Имя пользователя" in login,
            "Native login is not translated by the installed extension.")
    runtime.login((runtime.directory / "admin-password").read_text(encoding="ascii").strip())
    sessions = []
    try:
        def snapshot():
            query = ("SELECT `key`, value FROM oc_setting WHERE `key` IN "
                     "('config_language','config_language_id','config_language_admin') ORDER BY `key`; "
                     "SELECT user_group_id, permission FROM oc_user_group ORDER BY user_group_id;")
            return runtime.docker("exec", "-T", "db", "sh", "-ec",
                                  'export MYSQL_PWD="$(cat /runtime/db-root-password)"; '
                                  'mysql -uroot -NB cmteh_dev -e "$1"', "language-snapshot", query,
                                  capture=True).stdout

        before = snapshot()
        for _ in range(2):
            require(admin_post(runtime, MODULE + ".upgrade", {}).get("success"), "Admin upgrade failed.")
        require(snapshot() == before, "Admin translation changed the existing data/store language.")
        event_count = runtime.docker("exec", "-T", "db", "sh", "-ec",
                                    'export MYSQL_PWD="$(cat /runtime/db-root-password)"; '
                                    'mysql -uroot -NB cmteh_dev -e "SELECT COUNT(*) FROM oc_event '
                                    "WHERE code IN ('cmteh_admin_menu','cmteh_admin_language','cmteh_admin_header','cmteh_admin_dashboard') AND status=1\"",
                                    capture=True).stdout.strip()
        require(event_count == "4", "Repeat upgrade duplicated or failed to activate admin events.")
        for route, words in (
            ("catalog/product", ["Товары", "Название товара", "Фильтр"]),
            ("catalog/product.form", ["Добавить товар", "Описание", "Мета-заголовок"]),
            ("catalog/category.form", ["Добавить категорию", "Родительская категория"]),
            ("catalog/manufacturer.form", ["Добавить бренд", "Название бренда"]),
            ("common/filemanager", ["Изображения"]),
            ("common/filemanager.list", ["Название папки", "Загрузить"]),
        ):
            code, page = runtime.request(runtime.action(route))
            require(code == 200 and all(word in page for word in words), f"Native {route} screen is not Russian.")
        code, rejected = runtime.request(runtime.action("catalog/manufacturer.save"), b"name=", FORM)
        require(code == 200 and "Название бренда" in str(json.loads(rejected).get("error", {})),
                "Native form validation did not return its Russian field error.")
        editor = create_role(runtime, "Navigation editor", [MODULE, CATALOG, LEADS], [CATALOG])
        sessions.append(editor)
        code, page = editor.request(editor.action(MODULE))
        for label in ("Товары", "Категории", "Бренды", "Страницы", "Главная страница", "Статьи",
                      "Меню и подвал", "Заявки", "Настройки сайта"):
            require(label in page, f"Direct admin navigation misses {label}.")
        manager = create_role(runtime, "Navigation lead manager", [LEADS], [])
        operator = create_role(runtime, "Navigation catalog operator", [CATALOG, "catalog/category"], [CATALOG])
        sessions += [manager, operator]
        for session, route, expected, forbidden in (
            (manager, LEADS, LEADS, CATALOG), (operator, CATALOG, CATALOG, LEADS),
        ):
            code, page = session.request(session.action(route))
            menu = re.search(r'<nav id="column-left".*?</nav>', page, re.S)
            require(code == 200 and menu and expected in menu[0] and forbidden not in menu[0],
                    "Direct menu does not follow the role's existing access permissions.")
        require(operator.request(operator.action(LEADS))[0] in (401, 403), "Catalog role can access leads.")
        require(manager.request(manager.action(CATALOG))[0] in (401, 403), "Lead role can access catalog.")
        print("PASS: Russian native screens, direct ACL menu and repeat upgrade preserve the data language", flush=True)
    finally:
        for session in sessions:
            session.logout()
        runtime.logout()


def editorial_content(runtime):
    runtime.login((runtime.directory / "admin-password").read_text(encoding="ascii").strip())

    editor = None

    def save(kind, record_id, **fields):
        fields |= {"id": record_id, "status": fields.get("status", "published"), "is_indexable": "1"}
        code, body = editor.request(
            editor.action(CATALOG, type=kind, id=record_id),
            urlencode(fields, doseq=True).encode("ascii"), FORM,
        )
        require(code == 200 and "Запись сохранена" in body, f"Admin could not save {kind} {record_id} (HTTP {code}).")

    def page(path, expected):
        status, body = runtime.request(path)
        require(status == 200 and all(value in body for value in expected),
                f"Public {path} did not reflect the editor's saved values.")
        return body

    try:
        editor = create_role(runtime, "Content editor", [MODULE, CATALOG], [CATALOG])
        page("/delivery", ["Что указать для расчёта доставки"])
        save("page", "storefront-delivery", slug="delivery", title="Доставка для проверки",
             h1="Новые условия доставки", intro="Вступление редактора",
             content="<p><strong>Публикуемый текст доставки</strong></p><script>unsafe-marker</script>",
             sections_json=json.dumps([{"key": "", "section_type": "custom",
                 "title": "Добавленный раздел", "text": "<p>Текст нового раздела</p>", "items": []}]))
        delivery = page("/delivery", ["Новые условия доставки", "Вступление редактора",
                                      "<strong>Публикуемый текст доставки</strong>", "Добавленный раздел",
                                      "Текст нового раздела"])
        require("unsafe-marker" not in delivery, "The public page retained unsafe editor markup.")

        save("site", "mock-site", company_name="Компания для проверки", phone="+7 (900) 000-00-15",
             email="editor@example.test", logo="catalog/opencart-logo.png",
             footer_text="Изменённое описание в подвале",
             footer_sections_title="Проверка редактора подвала")
        page("/contacts", ['href="tel:+79000000015"', 'mailto:editor@example.test',
                          'src="/image/catalog/opencart-logo.png"', "Изменённое описание в подвале",
                          "Проверка редактора подвала"])

        menu = {"slug": "header-articles", "label": "Публикации из редактора", "internal_target": "/articles",
                "location": "header", "sort_order": "99"}
        save("navigation", "header-articles", **menu)
        page("/", ["Публикации из редактора"])
        save("navigation", "header-articles", status="archived", **menu)
        body = page("/", ["Компания для проверки"])
        require("Публикации из редактора" not in body, "Archived navigation still appeared.")

        result = admin_post(runtime, MODULE + ".upgrade", {})
        require(result.get("success"), "The repeat editorial migration failed.")
        page("/delivery", ["Новые условия доставки", "Добавленный раздел"])
        body = page("/", ["Компания для проверки", "Проверка редактора подвала"])
        require("Публикации из редактора" not in body, "The migration republished archived navigation.")
        print("PASS: editing delivery, sections, contacts, logo and footer reaches the storefront; migration preserves edits", flush=True)
    finally:
        if editor:
            editor.logout()
        runtime.logout()


def native_catalog_editor(runtime):
    run_php_suite(runtime, "admin-catalog-integration.php")
    runtime.login((runtime.directory / "admin-password").read_text(encoding="ascii").strip())

    def save_category(slug, label, *, category_id=0, parent_id=0, status=1):
        fields = {
            "category_id": category_id, "parent_id": parent_id, "status": status,
            "category_store[]": [0], "category_seo_url[0][1]": slug,
            "category_description[1][name]": label, "category_description[1][meta_title]": label,
            "category_description[1][description]": "<p>Текст штатного редактора</p>",
            "category_description[1][meta_description]": "",
            "category_description[1][meta_keyword]": "",
        }
        result = admin_post(runtime, "catalog/category.save", fields)
        require(result.get("success"), "Native OpenCart category editor rejected a valid record.")
        return category_id or int(result["category_id"])

    def save_product(slug, title, article, category_id, *, product_id=0, manufacturer_id=0, price=123.45):
        fields = {
            "product_id": product_id, "model": article, "status": "1", "price": price,
            "manufacturer_id": manufacturer_id,
            "date_available": "2020-01-01", "product_store[]": [0], "product_category[]": [category_id],
            "product_seo_url[0][1]": slug, "product_description[1][name]": title,
            "product_description[1][description]": "<p>Описание штатного товара</p>",
            "product_description[1][meta_title]": title, "product_description[1][tag]": "",
            "product_description[1][meta_description]": "", "product_description[1][meta_keyword]": "",
        }
        result = admin_post(runtime, "catalog/product.save", fields)
        require(result.get("success"), "Native OpenCart product editor rejected a valid record.")
        return product_id or int(result["product_id"])

    def catalog_snapshot():
        # Compare real native IDs, hierarchy, products, links and public projections without logging data.
        script = r'''require '/var/www/html/config.php';
require '/workspace/extension/cmteh/system/Database.php';
$pdo=\Cmteh\Database::connectFromOpenCart(); $state=[];
foreach (['category','category_description','category_path','category_to_store','product',
          'product_description','product_to_category','cmteh_category','cmteh_product','cmteh_route'] as $table) {
    $state[$table]=$pdo->query('SELECT * FROM `'.DB_PREFIX.$table.'` ORDER BY 1,2')->fetchAll(PDO::FETCH_ASSOC);
}
echo hash('sha256',json_encode($state,JSON_THROW_ON_ERROR));'''
        return runtime.docker("exec", "-T", "web", "php", "-r", script, capture=True).stdout.strip()

    try:
        nonce = uuid4().hex[:8]
        parent_slug, child_slug, product_slug = ("test-" + prefix + "-" + nonce for prefix in ("parent", "child", "product"))
        parent = save_category(parent_slug, "Штатный каталог " + nonce)
        child = save_category(child_slug, "Штатный подраздел " + nonce, parent_id=parent)
        product = save_product(product_slug, "Штатный товар " + nonce, "OLD-" + nonce, child)
        empty_slug = "test-unlinked-" + nonce
        empty = save_category(empty_slug, "Несвязанная тестовая категория " + nonce)
        before_delete = catalog_snapshot()
        for protected in (parent, child):
            status, body = runtime.request(runtime.action("catalog/category.delete"),
                                           urlencode({"selected[]": [empty, protected]}, doseq=True).encode("ascii"), FORM)
            require(status == 200, "Native category dependency refusal did not return its JSON response.")
            denied = json.loads(body)
            require(isinstance(denied.get("error"), str) and "Категор" in denied["error"] and not denied.get("success"),
                    "Native deletion did not clearly reject the complete dependent category batch.")
            require(catalog_snapshot() == before_delete,
                    "Denied native deletion changed category IDs, paths, products, links or public projections.")
            require(runtime.request("/category/" + empty_slug)[0] == 200
                    and runtime.request("/category/" + child_slug)[0] == 200
                    and runtime.request("/product/" + product_slug)[0] == 200,
                    "Denied category deletion changed an existing public URL.")
        require(admin_post(runtime, "catalog/category.delete", {"selected[]": [empty]}).get("success"),
                "Native deletion rejected an unlinked synthetic category.")
        require(runtime.request("/category/" + empty_slug)[0] == 410,
                "Native deletion of an unlinked category did not retire its URL.")
        print("PASS: native category deletion denies whole dependent batches without mutations; unlinked deletion returns URL410", flush=True)
        code, body = runtime.request("/category/" + parent_slug)
        require(code == 200 and "Штатный подраздел " + nonce in body and "Штатный товар " + nonce in body,
                "Native category tree did not appear or roll up its descendant's product.")
        brand_slug = "test-brand-" + nonce
        brand_label = "Штатный бренд " + nonce
        created = admin_post(runtime, "catalog/manufacturer.save", {
            "name": brand_label, "manufacturer_store[]": [0], "manufacturer_seo_url[0][1]": brand_slug,
        })
        require(created.get("success"), "Native OpenCart manufacturer editor rejected a valid record.")
        save_product(product_slug, "Обновлённый товар " + nonce, "NEW-" + nonce, child, product_id=product,
                     manufacturer_id=int(created["manufacturer_id"]), price=234.56)
        code, body = runtime.request("/brand/" + brand_slug)
        require(code == 200 and brand_label in body and "Обновлённый товар " + nonce in body,
                "The native brand did not appear with its product.")
        found = runtime.request_json("/api/search?q=" + "NEW-" + nonce)
        require(any(row["slug"] == product_slug and row["sku"] == "NEW-" + nonce
                    and float(row["price"]) == 234.56 for row in found["data"]),
                "Native product changes did not update the existing search projection.")
        code, body = runtime.request("/product/" + product_slug)
        require(code == 200 and "Обновлённый товар " + nonce in body, "The edited product lost its public URL.")
        save_category(child_slug, "Скрытый штатный подраздел", category_id=child, parent_id=parent, status=0)
        require(runtime.request("/category/" + child_slug)[0] == 404, "A disabled native category stayed visible.")
        require(admin_post(runtime, "catalog/product.delete", {"selected[]": [product]}).get("success"),
                "Native OpenCart did not delete the test product.")
        deleted_status, deleted_page = runtime.request("/product/" + product_slug)
        require(deleted_status == 410 and "Обновлённый товар " + nonce not in deleted_page,
                "A deleted native product did not retire its URL or retained its content.")
        print("PASS: native OpenCart creates categories, subcategories and products and updates the storefront/search", flush=True)
    finally:
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
    password_file = runtime.directory / "admin-cms-password"
    runtime.login((runtime.directory / "admin-password").read_text(encoding="ascii").strip())
    try:
        # Separate synthetic account: never add permissions to an existing group.
        browser_routes = [MODULE, CATALOG, "catalog/product", "catalog/category", "catalog/manufacturer",
                          "common/filemanager"]
        browser_editor = create_role(runtime, "Browser catalog editor", browser_routes,
                                     [route for route in browser_routes if route != MODULE],
                                     password_file=password_file)
    finally:
        runtime.logout()
    username = browser_editor.username
    browser_editor.logout()
    env = os.environ | {"CMTEH_BROWSER_BASE": runtime.origin, "CMTEH_BROWSER_TOOLING": str(tooling),
                        "CMTEH_BROWSER_EVIDENCE": str(evidence),
                        "CMTEH_ADMIN_CMS_SCENARIO": "full",
                        "CMTEH_ADMIN_USERNAME": username,
                        "CMTEH_ADMIN_PASSWORD_FILE": str(password_file)}
    for name in ("storefront.mjs", "admin-cms.mjs", "admin-shell.mjs", "capture.mjs"):
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
