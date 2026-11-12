"""Public safety boundary of the V2-only local launcher; no Docker required."""

import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location("v2_local", Path(__file__).parents[1] / "dev/v2-local.py")
launcher = importlib.util.module_from_spec(spec)
spec.loader.exec_module(launcher)


class RuntimeOwnership(unittest.TestCase):
    def test_source_checkout_is_rejected(self):
        with self.assertRaisesRegex(ValueError, "outside"):
            launcher.validate_runtime(launcher.ROOT / "cmteh-v2-search-test", 3162)

    def test_v1_checkout_is_rejected(self):
        with self.assertRaisesRegex(ValueError, "V1"):
            launcher.validate_runtime(Path("D:/codex/cmteh/cmteh-v2-search-test"), 3162)

    def test_existing_unowned_directory_is_rejected(self):
        with tempfile.TemporaryDirectory(prefix="cmteh-v2-search-") as temporary:
            path = Path(temporary)
            (path / "unrelated.txt").write_text("keep", encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "ownership"):
                launcher.validate_runtime(path, 3162)

    def test_foreign_owner_or_port_is_rejected(self):
        with tempfile.TemporaryDirectory(prefix="cmteh-v2-search-") as temporary:
            path = Path(temporary)
            (path / launcher.MARKER).write_text(json.dumps({
                "design": "v2-search", "source": str(launcher.ROOT), "port": 3163,
            }), encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "ownership"):
                launcher.validate_runtime(path, 3162)

    def test_empty_and_owned_directory_are_accepted_without_mutation(self):
        with tempfile.TemporaryDirectory(prefix="cmteh-v2-search-") as temporary:
            path = Path(temporary)
            self.assertIsNone(launcher.validate_runtime(path, 3162))
            self.assertEqual(list(path.iterdir()), [])
            owner = {"design": "v2-search", "source": str(launcher.ROOT), "port": 3162}
            (path / launcher.MARKER).write_text(json.dumps(owner), encoding="utf-8")
            self.assertEqual(launcher.validate_runtime(path, 3162), owner)


if __name__ == "__main__":
    unittest.main()
