"""Manage an isolated, loopback-only OpenCart development environment."""

import argparse
import hashlib
import html
import http.cookiejar
import ipaddress
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import subprocess
import sys
import time
from urllib.error import HTTPError, URLError
from urllib.parse import parse_qs, urlencode, urlsplit
from urllib.request import build_opener, HTTPRedirectHandler, HTTPCookieProcessor, Request

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE.parent / "packaging"))
from build import build


class LocalRedirects(HTTPRedirectHandler):
    def __init__(self, origin: str):
        self.origin = origin

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        parts = urlsplit(newurl)
        if f"{parts.scheme}://{parts.netloc}" != self.origin:
            raise RuntimeError("The local OpenCart server returned a nonlocal redirect.")
        return super().redirect_request(req, fp, code, msg, headers, newurl)


class LocalOpenCart:
    def __init__(self, directory: Path, port: int):
        if not 1024 <= port <= 65535:
            raise ValueError("Use an unprivileged loopback port.")
        self.directory = directory.resolve()
        source = HERE.parent.parent
        if source == self.directory or source in self.directory.parents:
            raise ValueError("The runtime directory must be outside the source checkout.")
        self.port = port
        self.origin = f"http://127.0.0.1:{port}"
        self.project = "cmteh-oc-" + hashlib.sha256(str(self.directory).encode()).hexdigest()[:12]
        self.http = build_opener(LocalRedirects(self.origin), HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token = ""

    def docker(self, *arguments: str, capture: bool = False):
        env = os.environ | {"CMTEH_DEV_DIR": str(self.directory), "CMTEH_HTTP_PORT": str(self.port)}
        command = ["docker", "compose", "-p", self.project, "-f", str(HERE / "compose.yml")]
        override = self.directory / "network.json"
        if override.is_file():
            command.extend(["-f", str(override)])
        return subprocess.run([*command, *arguments], env=env, check=True, capture_output=capture, text=True)

    def request(self, url: str, data: bytes | None = None, content_type: str = "") -> tuple[int, str]:
        absolute = self.origin + url if url.startswith("/") and not url.startswith("//") else html.unescape(url)
        parts = urlsplit(absolute)
        if f"{parts.scheme}://{parts.netloc}" != self.origin:
            raise RuntimeError("Only the local OpenCart server is allowed.")
        headers = {"Content-Type": content_type} if content_type else {}
        try:
            with self.http.open(Request(absolute, data, headers), timeout=15) as response:
                return response.status, response.read().decode("utf-8", "replace")
        except HTTPError as exc:
            return exc.code, exc.read().decode("utf-8", "replace")

    def action(self, route: str, **query: str) -> str:
        params = {"route": route, **query}
        if self.token:
            params["user_token"] = self.token
        return "/admin/index.php?" + urlencode(params)

    def request_json(self, url: str, data: bytes | None = None, content_type: str = "") -> dict:
        code, body = self.request(url, data, content_type)
        label = parse_qs(urlsplit(html.unescape(url)).query).get("route", ["unknown"])[0]
        if code != 200:
            raise RuntimeError(f"OpenCart admin action {label} returned HTTP {code}.")
        try:
            result = json.loads(body)
        except ValueError:
            raise RuntimeError(f"OpenCart admin action {label} did not return JSON.") from None
        if not isinstance(result, dict) or result.get("error"):
            raise RuntimeError(f"OpenCart admin action {label} was rejected.")
        return result

    def login(self, password: str) -> None:
        self.token = ""
        status, page = self.request(self.action("common/login"))
        login = re.search(r'<form id="form-login" action="([^"]+)"', page)
        if status != 200 or not login:
            raise RuntimeError("OpenCart admin login page is unavailable.")
        credentials = urlencode({"username": "cmteh-admin", "password": password}).encode("ascii")
        response = self.request_json(login[1], credentials, "application/x-www-form-urlencoded")
        tokens = parse_qs(urlsplit(html.unescape(response.get("redirect", ""))).query).get("user_token", [])
        if len(tokens) != 1 or not re.fullmatch(r"[A-Za-z0-9]+", tokens[0]):
            raise RuntimeError("The OpenCart admin session did not authenticate.")
        self.token = tokens[0]

    def logout(self) -> None:
        try:
            self.request(self.action("common/logout"))
        finally:
            self.token = ""
            self.http = build_opener(LocalRedirects(self.origin), HTTPCookieProcessor(http.cookiejar.CookieJar()))

    def upload_archive(self, archive: Path) -> None:
        boundary = "cmteh-" + secrets.token_hex(16)
        multipart = (
            f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="cmteh.ocmod.zip"\r\n'
            "Content-Type: application/zip\r\n\r\n"
        ).encode("ascii") + archive.read_bytes() + f"\r\n--{boundary}--\r\n".encode("ascii")
        response = self.request_json(
            self.action("marketplace/installer.upload"), multipart, f"multipart/form-data; boundary={boundary}"
        )
        if not response.get("success"):
            raise RuntimeError("OpenCart did not record the uploaded extension.")

    def installer_row(self) -> str:
        status, page = self.request(self.action("marketplace/installer"))
        if status != 200:
            raise RuntimeError(f"OpenCart extension installer returned HTTP {status}.")
        return next((row for row in re.findall(r"<tr\b.*?</tr>", page, re.S) if "github.com/alexdubaev/cmteh" in row), "")

    def install_extension(self, archive: Path, refresh: bool) -> None:
        row = self.installer_row()
        if not row:
            self.upload_archive(archive)
            row = self.installer_row()
        if not row:
            raise RuntimeError("The uploaded extension was not listed in the installer.")
        link = re.search(r'href="([^"]*route=marketplace/installer\.install[^"]*)"', row)
        if link:
            next_url = link[1]
            for _ in range(20):
                result = self.request_json(next_url)
                next_url = result.get("next")
                if not next_url:
                    break
            else:
                raise RuntimeError("OpenCart exceeded the extension installer page limit.")
            if not result.get("success"):
                raise RuntimeError("OpenCart did not complete package installation.")
        elif refresh:
            self.docker("exec", "-T", "web", "sh", "-c",
                        "cp -a /workspace/extension/cmteh/. /var/www/html/extension/cmteh/ && "
                        "cp /workspace/packaging/htaccess /var/www/html/.htaccess")

        status, page = self.request(self.action("extension/cmteh/module/cmteh"))
        if status != 200 or "Применить миграции" not in page:
            response = self.request_json(self.action("extension/module.install", extension="cmteh", code="cmteh"))
            if not response.get("success"):
                raise RuntimeError("OpenCart did not activate the module.")

        response = self.request_json(self.action("extension/cmteh/module/cmteh.upgrade"), b"")
        if not response.get("success"):
            raise RuntimeError("OpenCart did not apply module migrations.")

    def start(self, upstream: Path, refresh: bool) -> None:
        archive = build(upstream.resolve(), self.directory, extension_only=True)
        runtime_upstream = self.directory / "opencart-4.1.0.4.zip"
        if upstream.resolve() != runtime_upstream:
            shutil.copyfile(upstream, runtime_upstream)
        for name in ("admin-password", "db-password", "db-root-password"):
            path = self.directory / name
            if not path.exists():
                length = 18 if name == "admin-password" else 32
                with path.open("x", encoding="ascii", newline="\n") as stream:
                    stream.write(secrets.token_hex(length)[:length] + "\n")
                path.chmod(0o600)

        subnet = os.environ.get("CMTEH_DOCKER_SUBNET")
        override = self.directory / "network.json"
        if subnet and not override.exists():
            network = ipaddress.IPv4Network(subnet)
            private_ranges = ("10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16")
            if not 16 <= network.prefixlen <= 28 or not any(
                network.subnet_of(ipaddress.IPv4Network(prefix)) for prefix in private_ranges
            ):
                raise ValueError("CMTEH_DOCKER_SUBNET must be an unused private IPv4 subnet (/16 through /28).")
            configuration = {"networks": {"default": {"ipam": {"config": [{"subnet": str(network)}]}}}}
            override.write_text(json.dumps(configuration), encoding="ascii")

        self.docker("up", "-d", "--build")
        for _ in range(45):
            try:
                code, body = self.request("/admin/")
                if code == 200 and 'id="form-login"' in body:
                    break
            except (URLError, TimeoutError, ConnectionError):
                pass
            time.sleep(1)
        else:
            raise RuntimeError("The local admin login was not ready. Inspect the web service logs.")

        self.login((self.directory / "admin-password").read_text(encoding="ascii").strip())
        try:
            self.install_extension(archive, refresh)
        finally:
            self.logout()
        print(f"OpenCart local runtime: {self.origin} ({self.project})")

    def smoke(self) -> None:
        self.token = ""
        code, body = self.request("/_cmteh_platform")
        expected = {"opencart": True, "mysql": True, "private_storage": True}
        if code != 200 or json.loads(body) != expected:
            raise RuntimeError(f"OpenCart extension/MySQL smoke returned HTTP {code}.")
        print("PASS: OpenCart event, extension controller, PDO/MySQL, external storage")

        status, page = self.request(self.action("extension/cmteh/module/cmteh.upgrade"))
        if status != 200 or 'id="form-login"' not in page:
            raise RuntimeError("An anonymous request reached an admin-only action.")
        print("PASS: OpenCart admin session is required")

        denied = (
            "/?route=account/login",
            "/index.php?r%6fute=checkout/cart",
            "/?route%5B%5D=api/order",
            "/?_route_=product",
            "/config.php",
            "/admin/config.php",
            "/system/framework.php",
            "/install/index.php",
            "/extension/cmteh/system/Database.php",
            "/cmteh-private/admin.php",
        )
        for route in denied:
            if self.request(route)[0] != 404:
                raise RuntimeError(f"Protected request returned a status other than 404: {route}")
        print(f"PASS: {len(denied)} private and native storefront entry points blocked")

        self.login((self.directory / "admin-password").read_text(encoding="ascii").strip())
        code, page = self.request(self.action("extension/cmteh/module/cmteh"))
        if code != 200 or "Применить миграции" not in page:
            raise RuntimeError("The authenticated module admin page did not render.")
        print("PASS: authenticated OpenCart module admin")

        code, _ = self.request(self.action("extension/cmteh/module/cmteh.upgrade"))
        if code != 405:
            raise RuntimeError("Admin migration is not POST-only.")
        print("PASS: authenticated migrations require POST")
        self.logout()


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--runtime-dir", type=Path, required=True)
    parser.add_argument("--port", type=int, default=3154)
    subcommands = parser.add_subparsers(dest="command", required=True)
    start = subcommands.add_parser("start")
    start.add_argument("--upstream", type=Path, required=True)
    start.add_argument("--refresh", action="store_true")
    subcommands.add_parser("smoke")
    compose = subcommands.add_parser("compose")
    compose.add_argument("arguments", nargs=argparse.REMAINDER)
    options = parser.parse_args()

    try:
        runtime = LocalOpenCart(options.runtime_dir, options.port)
        if options.command == "start":
            runtime.start(options.upstream, options.refresh)
        elif options.command == "smoke":
            runtime.smoke()
        else:
            runtime.docker(*options.arguments)
    except (RuntimeError, ValueError, OSError, subprocess.CalledProcessError) as error:
        parser.exit(1, f"{error}\n")


if __name__ == "__main__":
    main()
