#!/usr/bin/env python3
"""Regression for the existing PHP development router's locked Module asset URL."""
import json
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

SOURCE = Path(__file__).resolve().parents[2] / "server/public/router.php"
PROBE = r"""
$_SERVER['REQUEST_URI'] = $argv[2];
$_SERVER['REQUEST_METHOD'] = $argv[3];
$_SERVER['DOCUMENT_ROOT'] = dirname($argv[1]);
$_SERVER['SCRIPT_NAME'] = parse_url($argv[2], PHP_URL_PATH);
http_response_code(200);
ob_start();
$passthrough = require $argv[1];
$body = ob_get_clean();
echo json_encode(['passthrough' => $passthrough, 'status' => http_response_code(), 'body' => $body]);
"""


class PhpRouterModuleAssetTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="peanut-module-router-")
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root / "router.php").write_bytes(SOURCE.read_bytes())
        (self.root / "admin").mkdir()
        (self.root / "admin/index.html").write_text("<html>SPA_MARKER</html>")
        (self.root / "admin/assets").mkdir()
        (self.root / "admin/assets/app-hash.css").write_text("css body")
        (self.root / "admin/module-assets/acme.first/nested").mkdir(parents=True)
        (self.root / "admin/module-assets/acme.first/nested/catalog.json").write_bytes(b'{"key":"first"}\n')
        (self.root / "admin/module-assets/acme.first/nested/code.PHP").write_text("<?php echo 1;")
        (self.root / "admin/module-assets/acme.first/.private").write_text("PRIVATE")

    def request(self, uri, method="GET"):
        proc = subprocess.run(
            ["php", "-r", PROBE, str(self.root / "router.php"), uri, method],
            capture_output=True, text=True, timeout=8, check=True,
        )
        return json.loads(proc.stdout)

    def test_real_locked_asset_and_normal_hashed_vite_asset_passthrough(self):
        for uri in (
            "/admin/module-assets/acme.first/nested/catalog.json",
            "/admin/module-assets/acme.first/nested/catalog.json?revision=rc4",
            "/admin/assets/app-hash.css",
        ):
            with self.subTest(uri=uri):
                result = self.request(uri)
                self.assertIs(result["passthrough"], False)
                self.assertEqual(result["status"], 200)
                self.assertEqual(result["body"], "")

    def test_removed_missing_or_invalid_module_asset_never_returns_spa(self):
        for uri in (
            "/admin/module-assets", "/admin/module-assets/",
            "/admin/module-assets/acme.first/missing.json",
            "/admin/module-assets/acme.retired/nested/catalog.json",
            "/admin/module-assets/acme.first/.private",
            "/admin/module-assets/acme.first/nested/code.PHP",
            "/admin/module-assets/acme.first/../nested/catalog.json",
            "/admin/module-assets/acme.first/%2e%2e/nested/catalog.json",
            "/admin/module-assets/acme.first/%00catalog.json",
        ):
            with self.subTest(uri=uri):
                result = self.request(uri)
                self.assertIs(result["passthrough"], True)
                self.assertEqual(result["status"], 404)
                self.assertEqual(result["body"], "")

    def test_only_get_and_head_allowed_for_module_asset_namespace(self):
        result = self.request("/admin/module-assets/acme.first/nested/catalog.json", "HEAD")
        self.assertIs(result["passthrough"], False)
        self.assertEqual(result["status"], 200)
        for method in ("POST", "PUT", "DELETE"):
            with self.subTest(method=method):
                result = self.request("/admin/module-assets/acme.first/nested/catalog.json", method)
                self.assertIs(result["passthrough"], True)
                self.assertEqual(result["status"], 405)
                self.assertEqual(result["body"], "")

    def test_authentication_routes_and_spa_history_fallback_remain_unchanged(self):
        result = self.request("/admin/dashboard/workplace")
        self.assertIs(result["passthrough"], True)
        self.assertEqual(result["status"], 200)
        self.assertEqual(result["body"], "<html>SPA_MARKER</html>")


if __name__ == "__main__":
    unittest.main(verbosity=2)
