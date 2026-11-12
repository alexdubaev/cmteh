"""Start the isolated, local-only V2 storefront design preview."""

import argparse
import hashlib
import importlib.util
import json
from pathlib import Path
import secrets
import socket
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[2]
MARKER = "v2-preview.json"
HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))
from runtime import LocalOpenCart


def validate_runtime(directory: Path, port: int):
    raw = directory.absolute()
    for ancestor in [raw, *raw.parents]:
        if ancestor.is_symlink() or getattr(ancestor, "is_junction", lambda: False)():
            raise ValueError("V2 runtime must not use linked directories.")
    directory = raw.resolve()
    v1 = Path("D:/codex/cmteh").resolve()
    if directory == v1 or v1 in directory.parents:
        raise ValueError("V1 is protected; choose a separate V2 runtime.")
    if directory == ROOT or ROOT in directory.parents:
        raise ValueError("The V2 runtime must be outside the source checkout.")
    if not directory.name.startswith("cmteh-v2-search-"):
        raise ValueError("Use a dedicated cmteh-v2-search-* runtime directory.")
    if not 1024 <= port <= 65535:
        raise ValueError("Use an unprivileged loopback port.")
    if directory.exists() and not directory.is_dir():
        raise ValueError("The V2 runtime path must be a directory.")
    marker = directory / MARKER
    if marker.exists():
        try:
            owner = json.loads(marker.read_text(encoding="utf-8"))
        except (ValueError, OSError):
            raise ValueError("Invalid V2 runtime ownership record.") from None
        if (not isinstance(owner, dict) or owner.get("design") != "v2-search"
                or owner.get("source") != str(ROOT) or owner.get("port") != port):
            raise ValueError("V2 runtime ownership does not match this checkout and port.")
        return owner
    if directory.exists() and any(directory.iterdir()):
        raise ValueError("Existing directory has no V2 runtime ownership record.")
    return None


def owned_runtime(directory: Path, port: int):
    owner = validate_runtime(directory, port)
    if owner is None:
        raise ValueError("Start V2 first; its ownership record is missing.")
    return LocalOpenCart(directory, port), owner


def write_owner(runtime, owner):
    path = runtime.directory / MARKER
    temporary = path.with_suffix(".tmp")
    temporary.write_text(json.dumps(owner, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    temporary.replace(path)


def seed_demo(runtime, owner):
    fixture = ROOT / "opencart/fixtures/demo.ndjson"
    digest = hashlib.sha256(fixture.read_bytes()).hexdigest()
    if owner.get("demo_sha256") == digest:
        return
    if owner.get("demo_sha256"):
        raise ValueError("The demo fixture changed. Choose a new V2 runtime to preserve existing test data.")
    command = ["exec", "-T", "web", "php", "/workspace/bin/import-catalog.php",
               "--config=/var/www/html/config.php", "--input=/workspace/fixtures/demo.ndjson"]
    preview = json.loads(runtime.docker(*command, "--preview", capture=True).stdout)
    if preview.get("errors") != 0 or preview.get("sha256") != digest:
        raise RuntimeError("The synthetic catalog preview was not accepted.")
    applied = json.loads(runtime.docker(*command, "--apply", "--confirm=" + digest,
                                       "--run-key=v2-demo-" + digest[:16], capture=True).stdout)
    if applied.get("errors") != 0:
        raise RuntimeError("The confirmed synthetic catalog did not apply.")
    owner["demo_sha256"] = digest
    write_owner(runtime, owner)
    print("PASS: synthetic catalog imported; production data was not copied.")


def seed_warehouses(runtime, owner):
    if owner.get("warehouse_fixture"):
        return
    token = owner.setdefault("warehouse_token", secrets.token_hex(6))
    write_owner(runtime, owner)
    fixture = json.loads(runtime.docker(
        "exec", "-T", "web", "php", "/workspace/tests/warehouse-offers-integration.php",
        "/var/www/html/config.php", "--browser-setup", token, capture=True,
    ).stdout)
    owner["warehouse_fixture"] = fixture
    owner["warehouse_product_url"] = "/product/" + fixture["source"]
    write_owner(runtime, owner)
    print("PASS: two synthetic warehouse offers available for the local journey.")


def apply_design(runtime):
    runtime.docker("exec", "-T", "web", "sh", "-c", """
set -eu
source=/workspace/designs/v2-search
templates=/var/www/html/extension/cmteh/catalog/view/template/storefront
assets=/var/www/html/extension/cmteh/catalog/view/assets/v2-search
mkdir -p "$templates/v2" "$assets"
cp -a "$source/templates/." "$templates/v2/"
cp "$source/entry.twig" "$templates/layout.twig"
cp "$source/design.css" "$assets/design.css"
if [ -d "$source/images" ]; then cp -a "$source/images" "$assets/"; fi
""")
    print("PASS: V2 presentation applied to its own local installed copy.")


def start(directory: Path, upstream: Path, port: int):
    owner = validate_runtime(directory, port)
    if owner is None:
        with socket.socket() as probe:
            probe.bind(("127.0.0.1", port))
        directory.mkdir(parents=True, exist_ok=True)
        owner = {"design": "v2-search", "source": str(ROOT), "port": port}
        runtime = LocalOpenCart(directory, port)
        write_owner(runtime, owner)
    else:
        runtime = LocalOpenCart(directory, port)
    runtime.start(upstream, refresh=True)
    seed_demo(runtime, owner)
    seed_warehouses(runtime, owner)
    apply_design(runtime)
    runtime.smoke()
    owner["origin"] = runtime.origin
    write_owner(runtime, owner)
    print(f"V2: {runtime.origin}\nSynthetic warehouses: {runtime.origin}{owner['warehouse_product_url']}")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--runtime-dir", type=Path, required=True)
    parser.add_argument("--port", type=int, default=3162)
    parser.add_argument("--upstream", type=Path)
    parser.add_argument("command", choices=("start", "refresh", "stop", "check"))
    args = parser.parse_args()
    try:
        if args.command == "start":
            if args.upstream is None:
                raise ValueError("Provide --upstream with the pinned official OpenCart ZIP.")
            start(args.runtime_dir, args.upstream, args.port)
        else:
            runtime, _ = owned_runtime(args.runtime_dir, args.port)
            if args.command == "refresh":
                apply_design(runtime)
            elif args.command == "stop":
                runtime.docker("stop")
            else:
                subprocess.run([sys.executable, str(HERE / "v2-check.py"),
                                "--runtime-dir", str(args.runtime_dir), "--port", str(args.port)], check=True)
    except (OSError, ValueError, RuntimeError, subprocess.CalledProcessError) as error:
        parser.exit(1, f"V2 local preview failed: {error}\n")


if __name__ == "__main__":
    main()
