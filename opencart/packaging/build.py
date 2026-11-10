"""Build a deterministic installer from a hash-verified official OpenCart asset."""

import argparse
import hashlib
import json
from pathlib import Path
import zipfile

HERE = Path(__file__).resolve().parent
ROOT = HERE.parent
LOCK = json.loads((HERE / "open-cart.lock.json").read_text(encoding="utf-8"))


def digest(path: Path) -> str:
    with path.open("rb") as stream:
        hasher = hashlib.sha256()
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            hasher.update(chunk)
        return hasher.hexdigest()


def verify_upstream(path: Path) -> None:
    if digest(path) != LOCK["sha256"]:
        raise ValueError("Official OpenCart archive SHA-256 does not match the lock file")


def write_zip(path: Path, entries: dict[str, Path]) -> None:
    if any(source.is_symlink() or not source.is_file() for source in entries.values()):
        raise ValueError("Archive sources must be regular files")
    temporary = path.with_suffix(".tmp")
    with zipfile.ZipFile(temporary, "w") as archive:
        for name, source in sorted(entries.items()):
            info = zipfile.ZipInfo(name)
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            archive.writestr(info, source.read_bytes(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
    temporary.replace(path)


def tree_files(root: Path) -> dict[str, Path]:
    entries = {}
    for path in root.rglob("*"):
        relative = path.relative_to(root)
        if path.is_symlink():
            raise ValueError(f"Symlink in package source: {relative.as_posix()}")
        if path.is_file():
            if any(part.startswith(".") for part in relative.parts):
                raise ValueError(f"Hidden file in package source: {relative.as_posix()}")
            entries[relative.as_posix()] = path
    return entries


def build(upstream: Path, output: Path, extension_only: bool = False) -> Path:
    verify_upstream(upstream)
    output.mkdir(parents=True, exist_ok=True)
    extension = output / "cmteh.ocmod.zip"
    entries = tree_files(ROOT / "extension" / "cmteh")
    if "install.json" not in entries:
        raise ValueError("OpenCart extension install.json is missing")
    write_zip(extension, entries)
    if extension_only:
        return extension

    package = output / "cmteh-install.zip"
    files = {
        f'opencart-{LOCK["version"]}.zip': upstream,
        extension.name: extension,
        "setup/open-cart.lock.json": HERE / "open-cart.lock.json",
        "setup/htaccess": HERE / "htaccess",
        "setup/seal.php": HERE / "seal.php",
        "INSTALL.md": HERE / "README.md",
    }
    if (ROOT / "README.md").is_file():
        files["README.md"] = ROOT / "README.md"
    cli = ROOT / "bin"
    if cli.is_dir():
        for path in cli.iterdir():
            if path.is_file() and path.name.startswith(("import", "generate", "send-leads", "send-quotes")):
                files[f"cli/{path.name}"] = path
    write_zip(package, files)
    (output / f"{package.name}.sha256").write_text(f"{digest(package)}  {package.name}\n", encoding="ascii")
    return package


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--upstream", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    parser.add_argument("--extension-only", action="store_true")
    options = parser.parse_args()
    print(build(options.upstream.resolve(), options.output.resolve(), options.extension_only))


if __name__ == "__main__":
    main()
