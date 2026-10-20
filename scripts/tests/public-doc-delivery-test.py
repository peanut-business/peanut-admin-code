#!/usr/bin/env python3
"""Offline tests of the real PHP documentation delivery checker; no APP generation."""
from pathlib import Path
import json, os, subprocess, tempfile, unittest
ROOT = Path(__file__).resolve().parents[2]
PHP = os.environ.get('PHP_BINARY', 'php')

class PublicDocsTest(unittest.TestCase):
    def setUp(self):
        owned = ROOT / '.local/tmp'
        owned.mkdir(parents=True, exist_ok=True)
        self.tmp = tempfile.TemporaryDirectory(prefix='public-doc-contract-', dir=owned)
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.names = ['docs/README.md'] + ['docs/public/' + n + '.md' for n in
            ['README', 'release-channels', 'api-and-sdk', 'module-development', 'installation', 'application-release', 'support']]
        for name in self.names:
            self.write(name, '# Public manual\n')
        self.write('docs/public/release-channels.md', '# Channels\nphp server/database/install.php\nscripts/upgrade plan\n')
        self.write('docs/public/api-and-sdk.md', '# API\n./scripts/check-openapi\n')
        self.write('docs/public/application-release.md', '# Release\nPEANUT_UPGRADE_PHP; PHP 8.3; existing workspace\n')
        for name in ('AGENTS', 'SECURITY'):
            self.write('server/resources/scaffold-application/' + name + '.md.stub', '# Public application guidance\n')
        self.entries = [{'path': p, 'target': p, 'classification': 'managed'} for p in self.names]
        self.save()
        self.write('release-versions.json', json.dumps({'source_product_version': '9.8.7'}))
        self.write('server/generated/openapi.json', json.dumps({'info': {'version': '9.8.7'}}))
    def write(self, name, text):
        p = self.root / name;p.parent.mkdir(parents=True, exist_ok=True);p.write_text(text);return p
    def save(self): self.write('scaffold/application-template-inventory.json', json.dumps({'files': self.entries}))
    def check(self):
        return subprocess.run([PHP, str(ROOT/'scripts/check-public-docs'), '--root='+str(self.root)], capture_output=True,text=True,timeout=10)
    def test_matching_source_and_delivered_manuals_pass(self):
        r=self.check();self.assertEqual(0,r.returncode,r.stderr)
    def test_source_version_is_not_duplicated_in_prose(self):
        self.assertNotIn('9.8.7',(self.root/'docs/public/release-channels.md').read_text());self.assertEqual(0,self.check().returncode)
    def test_missing_manual_fails(self):
        (self.root/'docs/public/support.md').unlink();self.assertNotEqual(0,self.check().returncode)
    def test_undelivered_relative_link_fails(self):
        self.write('docs/maintenance/only.md','# Internal tool\n');self.write('docs/README.md','[internal](maintenance/only.md)\n')
        self.assertIn('not shipped',self.check().stderr)
    def test_duplicate_site_delivery_fails(self):
        self.entries.append({'path':'docs/README.md','target':'docs-site/index.md','classification':'managed'});self.save()
        self.assertIn('duplicate docs-site',self.check().stderr)
    def test_private_path_is_rejected(self):
        self.write('docs/public/support.md','Open /Users/private/secret\n');self.assertNotEqual(0,self.check().returncode)
    def test_api_version_drift_is_rejected(self):
        self.write('server/generated/openapi.json',json.dumps({'info':{'version':'old'}}));self.assertIn('API version differs',self.check().stderr)
    def test_relative_delivered_link_passes(self):
        self.write('docs/README.md','[support](public/support.md)\n');self.assertEqual(0,self.check().returncode)
    def test_escaping_link_is_rejected(self):
        self.write('docs/README.md','[escape](../../../outside.md)\n');self.assertNotEqual(0,self.check().returncode)
    def test_symlink_to_an_existing_shipped_doc_is_rejected(self):
        (self.root/'docs/alias.md').symlink_to(self.root/'docs/public/support.md')
        self.write('docs/README.md','[alias](alias.md)\n')
        self.assertIn('unsafe', self.check().stderr)
    def test_wrong_case_is_rejected(self):
        self.write('docs/README.md','[case](public/Support.md)\n')
        self.assertNotEqual(0,self.check().returncode)
    def test_missing_update_php_prerequisite_rejected(self):
        self.write('docs/public/application-release.md','# Release\nPHP 8.3; workspace\n')
        self.assertIn('PEANUT_UPGRADE_PHP',self.check().stderr)
    def test_missing_inventory_refuses_source_claim(self):
        (self.root/'scaffold/application-template-inventory.json').unlink();self.assertEqual(2,self.check().returncode)

if __name__ == '__main__': unittest.main(verbosity=2)
