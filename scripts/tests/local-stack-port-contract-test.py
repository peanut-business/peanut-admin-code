#!/usr/bin/env python3
"""Check that daily development only starts on registered listener ports."""

import json
import os
import subprocess
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


class LocalStackPortContractTest(unittest.TestCase):
    def test_registered_port_is_accepted_and_stale_override_is_rejected(self):
        with tempfile.TemporaryDirectory(prefix="peanut-local-port-") as directory:
            stack = Path(directory) / "stack.env"
            backend = Path(directory) / "backend.env"
            stack.write_text("PHP_PORT=20180\n")
            backend.write_text("DB_USER=peanut_admin_development\nDB_PASS=synthetic\n")
            stack.chmod(0o600)
            backend.chmod(0o600)
            env = {
                **os.environ,
                "PEANUT_LOCAL_ENV_FILE": str(stack),
                "PEANUT_SERVER_ENV_FILE": str(backend),
            }

            ready = subprocess.run(
                [str(ROOT / "scripts/local-stack.sh"), "urls"],
                env=env, text=True, capture_output=True, timeout=15,
            )
            self.assertEqual(ready.returncode, 0, ready.stderr)
            self.assertIn("http://127.0.0.1:20180/", ready.stdout)

            probe = [
                str(ROOT / "scripts/local-environment-probe"), "--config-only",
                "--env-file", str(stack), "--backend-env", str(backend),
            ]
            valid = subprocess.run(probe, text=True, capture_output=True, timeout=15)
            self.assertEqual(valid.returncode, 0, valid.stderr)
            self.assertEqual(json.loads(valid.stdout)["status"], "pass")

            stack.write_text(stack.read_text().replace("PHP_PORT=20180", "PHP_PORT=8000"))
            refused = subprocess.run(
                [str(ROOT / "scripts/local-stack.sh"), "urls"],
                env=env, text=True, capture_output=True, timeout=15,
            )
            self.assertNotEqual(refused.returncode, 0)
            self.assertIn("PHP_PORT differs from its registered port", refused.stderr)

            invalid = subprocess.run(probe, text=True, capture_output=True, timeout=15)
            self.assertNotEqual(invalid.returncode, 0)
            checks = json.loads(invalid.stdout)["checks"]
            self.assertEqual(
                next(item["status"] for item in checks if item["id"] == "config.local_environment"),
                "fail",
            )


if __name__ == "__main__":
    unittest.main()
