#!/usr/bin/env python3
"""Execute the metadata consumer used by the real publisher with JSON inputs."""
import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[2]
HELPER = ROOT / "scripts/release-metadata-version.cjs"


class ReleaseMetadataVersionTest(unittest.TestCase):
    def run_case(self, payload: dict) -> subprocess.CompletedProcess[str]:
        return subprocess.run(["node", "-e", "const {releaseMetadataVersion}=require(process.argv[1]);"
                               "process.stdout.write(releaseMetadataVersion(JSON.parse(process.argv[2])))",
                               str(HELPER), json.dumps(payload)], text=True, capture_output=True, check=False)

    def test_v3_product_prerelease(self) -> None:
        case = {"schema_version": 3, "protocol": "peanut.release-metadata.v3",
                "source_product_version": "4.0.0-rc.10", "instance_version": None}
        result = self.run_case(case)
        self.assertEqual((result.returncode, result.stdout), (0, "4.0.0-rc.10"))

    def test_v2_and_v3_instance_identity(self) -> None:
        for schema in (2, 3):
            with self.subTest(schema=schema):
                result = self.run_case({"schema_version": schema, "protocol": f"peanut.release-metadata.v{schema}",
                                        "source_product_version": "4.0.0", "instance_version": "4.0.1"})
                self.assertEqual((result.returncode, result.stdout), (0, "4.0.1"))

    def test_rejects_mismatched_protocol_and_missing_version(self) -> None:
        for case in ({"schema_version": 3, "protocol": "peanut.release-metadata.v2", "source_product_version": "4.0.0"},
                     {"schema_version": 3, "protocol": "peanut.release-metadata.v3", "instance_version": None}):
            with self.subTest(case=case):
                self.assertNotEqual(self.run_case(case).returncode, 0)


if __name__ == "__main__":
    unittest.main()
