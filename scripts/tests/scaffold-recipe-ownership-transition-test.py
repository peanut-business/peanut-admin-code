#!/usr/bin/env python3
import json
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


class ScaffoldRecipeOwnershipTransitionTest(unittest.TestCase):
    def test_legacy_ci_removal_requires_explicit_recipe_transition(self):
        with tempfile.TemporaryDirectory(prefix="peanut-scaffold-recipe-transition-") as temporary:
            temp = Path(temporary)
            app = temp / "app"
            workflow = app / ".github/workflows/ci.yml"
            workflow.parent.mkdir(parents=True)
            workflow.write_text("existing application CI\n")

            release = {
                "schema_version": 2,
                "protocol": "peanut.scaffold-release.v2",
                "release": {
                    "version": "1.0.0",
                    "source_commit": "a" * 40,
                    "source_tree": "b" * 40,
                    "inventory_sha256": "c" * 64,
                    "inventory_template_version": "1.0.0",
                    "tokens": {
                        "product_name": "product-token",
                        "slug": "slug-token",
                        "package_identity": "package-token",
                    },
                },
                "files": [{
                    "path": ".github/workflows/ci.yml",
                    "owner": "host",
                    "policy": "managed",
                    "classification": "managed",
                    "transform": "tokens",
                    "mode": 420,
                    "template_sha256": "d" * 64,
                }],
            }
            from_path = temp / "from.json"
            to_path = temp / "to.json"
            from_path.write_text(json.dumps(release))
            release["release"]["version"] = "1.0.1"
            release["files"] = []
            to_path.write_text(json.dumps(release))

            code = r"""
foreach (['ScaffoldManifest', 'ScaffoldPathGuard', 'Semver', 'ScaffoldUpgradeRunner'] as $class) {
    require $argv[1] . '/scripts/scaffold-runtime/' . $class . '.php';
}
use app\common\value\scaffold\ScaffoldManifest;
use app\common\infrastructure\scaffold\ScaffoldUpgradeRunner;
$decisions = [ScaffoldManifest::additionDecision(false, false),
    ScaffoldManifest::additionDecision(true, true), ScaffoldManifest::additionDecision(true, false)];
if (array_column($decisions, 'action') !== ['create', 'preserve', 'conflict']) {
    throw new RuntimeException('new-path decisions changed');
}
$method = new ReflectionMethod(ScaffoldUpgradeRunner::class, 'classify');
$actions = $method->invoke(new ScaffoldUpgradeRunner(), $argv[2],
    ['files' => [['path' => '.github/workflows/ci.yml', 'classification' => 'managed']]],
    ScaffoldManifest::load($argv[3]), ScaffoldManifest::load($argv[4]), [], [], []);
if ($actions[0]['action'] !== 'conflict' || $actions[0]['reason'] !== 'recipe_ownership_transition_required') {
    throw new RuntimeException('legacy CI deletion was allowed');
}
echo "shared decisions and legacy ownership guard passed\n";
"""
            result = subprocess.run(
                ["php", "-r", code, str(ROOT), str(app), str(from_path), str(to_path)],
                text=True,
                capture_output=True,
            )
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(workflow.read_text(), "existing application CI\n")


if __name__ == "__main__":
    unittest.main(verbosity=2)
