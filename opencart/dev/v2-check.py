"""Affected checks for the isolated V2 preview; not the production release gate."""

import argparse
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
from urllib.parse import urlencode
from zipfile import ZipFile

HERE = Path(__file__).resolve().parent
ROOT = HERE.parent.parent
sys.path.insert(0, str(HERE))
spec = importlib.util.spec_from_file_location("v2_local", HERE / "v2-local.py")
launcher = importlib.util.module_from_spec(spec)
spec.loader.exec_module(launcher)
from release_checks import run_php_suite


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def check(runtime, owner, integration=True):
    counts = {"safety": 5, "http": 0, "integration_suites": 0, "package": 0}
    subprocess.run([sys.executable, str(ROOT / "opencart/tests/v2-local-test.py")], check=True)
    runtime.smoke()
    # Verify the real public application, not standalone mock HTML.
    routes = ["/", "/catalog", "/catalog?q=1R-1808", "/catalog?q=does-not-exist-v2",
              "/brands", "/brand/john-deere", "/category/filters", "/product/jd-re568158",
              "/request", "/parts-list", "/delivery", "/payment", "/about", "/contacts", "/articles",
              owner["warehouse_product_url"]]
    for path in routes:
        status, body = runtime.request(path)
        require(status == 200 and '<html lang="ru">' in body and "v2-storefront" in body,
                f"The installed V2 route did not render: {path} (HTTP {status}).")
        counts["http"] += 1
    status, raw = runtime.request("/api/search?" + urlencode({"q": "RE568158", "mode": "suggestions"}))
    response = json.loads(raw)
    require(status == 200 and any(row.get("sku") == "RE568158" for row in response.get("data", [])),
            "The existing search API did not return the synthetic known SKU.")
    counts["http"] += 1
    for name in ("storefront.mjs", "cart.mjs", "request.mjs", "parts-list.mjs", "v2-search/design.css",
                 "v2-search/images/excavator-drawing.webp", "v2-search/images/search.svg"):
        status, body = runtime.request("/extension/cmteh/catalog/view/assets/" + name)
        require(status == 200 and len(body) > 20, "Missing installed asset: " + name)
        counts["http"] += 1
    print(f"PASS: {counts['http']} installed V2 routes, catalog/search API and assets.", flush=True)

    protected = ["opencart/extension/cmteh", "opencart/packaging", "opencart/dev/runtime.py",
                 "opencart/dev/compose.yml", "opencart/dev/bootstrap.sh"]
    changed = subprocess.check_output(["git", "diff", "--name-only", "origin/main", "--", *protected],
                                      cwd=ROOT, text=True).strip()
    require(not changed, "V1's default source or packaging changed.")
    with ZipFile(runtime.directory / "cmteh.ocmod.zip") as archive:
        source = ROOT / "opencart/extension/cmteh"
        entries = {path.relative_to(source).as_posix(): path for path in source.rglob("*") if path.is_file()}
        require(set(archive.namelist()) == set(entries), "Default extension package members differ.")
        for name, path in entries.items():
            require(archive.read(name) == path.read_bytes(), "Default extension package bytes differ: " + name)
        counts["package"] = len(entries)
    print(f"PASS: default V1 extension/package unchanged ({counts['package']} exact files); V2 is a local overlay.", flush=True)

    if integration:
        for suite in ("catalog-integration.php", "warehouse-offers-integration.php", "quote-integration.php"):
            run_php_suite(runtime, suite)
            counts["integration_suites"] += 1
    evidence = runtime.directory / "evidence"
    evidence.mkdir(exist_ok=True)
    scope = ("local V2 affected checks, not full release gate" if integration
             else "local V2 presentation recheck; integration suites not rerun")
    report = {"scope": scope, "result": "PASS",
              "origin": runtime.origin, "counts": counts, "browser": "separate CUA acceptance by lead",
              "extension_sha256": hashlib.sha256((runtime.directory / "cmteh.ocmod.zip").read_bytes()).hexdigest()}
    filename = "v2-check.json" if integration else "v2-presentation-check.json"
    (evidence / filename).write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    print("PASS: V2 affected checkset (safety, real HTTP/MySQL behavior, unchanged default package)."
          if integration else "PASS: V2 presentation recheck (safety/HTTP/package); integration suites not rerun.", flush=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--runtime-dir", type=Path, required=True)
    parser.add_argument("--port", type=int, default=3162)
    parser.add_argument("--http-only", action="store_true", help="narrow recheck after a presentation-only fix")
    args = parser.parse_args()
    try:
        runtime, owner = launcher.owned_runtime(args.runtime_dir, args.port)
        check(runtime, owner, integration=not args.http_only)
    except (ValueError, OSError, RuntimeError, subprocess.CalledProcessError) as error:
        parser.exit(1, f"V2 affected checks failed: {error}\n")


if __name__ == "__main__":
    main()
