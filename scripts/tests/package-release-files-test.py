#!/usr/bin/env python3
"""Run real source-copy/archive operations on isolated synthetic release inputs."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('package_files', ROOT/'scripts/package-release-files.py')
pack = importlib.util.module_from_spec(spec)
spec.loader.exec_module(pack)


class PackagingFilesTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix='peanut-packaging-test-')
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.source = self.root/'application'; self.source.mkdir()
        self.out = self.root/'output'
        self.version = '4.0.0-rc.1'
        self.manifest = {
            'schema_version': 2, 'protocol': 'peanut.application-scaffold.v2',
            'application': {'edition': 'standalone'},
            'generation_source': {'commit': 'a'*40},
            'ownership': {'baseline_root': '.peanut/scaffold-baseline/4.0.0-rc.1/files'},
            'files': []}
        versions = {'source_product_version': self.version,
                    'core_php': {'constraint': self.version, 'resolved_version': self.version, 'source_reference': 'b'*40},
                    'core_web': {'packages': {'@peanut-admin/client': {'version': self.version}}}}
        self.add('release-versions.json', json.dumps(versions))
        self.add('server/composer.json', json.dumps({'require': {'peanut-admin/core': self.version}, 'repositories': []}))
        self.add('server/composer.lock', json.dumps({'packages': [{'name': 'peanut-admin/core', 'version': self.version, 'source': {'reference':'b'*40}}]}))
        for file in ('server/database/install.php', 'server/public/index.php', 'scripts/upgrade', 'plugins.lock'):
            self.add(file, '<?php /* packaging fixture, not an application */\n' if file.endswith('.php') else '{}\n')
        self.add('server/app/common/value/runtime/RuntimeNamespace.php', '<?php\n')
        self.add('server/runtime/.gitkeep', '')
        for client in pack.CLIENTS:
            self.add(f'{client}/package.json', json.dumps({'dependencies': {'@peanut-admin/client': self.version}}))
            self.add(f'{client}/{pack.LOCKS[client]}', '{}')
            self.add(f'{client}/src/page.vue', '<template>fixture</template>')
            self.add(f'{client}/.env.production', 'PUBLIC_TEMPLATE_ONLY=true\n')
        self.save()

    def add(self, name, text, classification='managed'):
        path=self.source/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_text(text)
        digest=hashlib.sha256(path.read_bytes()).hexdigest()
        item={'path':name,'sha256':digest,'mode':0o644,'classification':classification}
        if classification in ('managed','generated-managed'):
            baseline=self.manifest['ownership']['baseline_root']+'/'+name
            target=self.source/baseline;target.parent.mkdir(parents=True,exist_ok=True);target.write_text(text)
            item.update(baseline_path=baseline,baseline_sha256=digest)
        self.manifest['files']=[x for x in self.manifest['files'] if x['path']!=name]+[item]

    def save(self):
        path=self.source/pack.MANIFEST;path.parent.mkdir(parents=True,exist_ok=True)
        path.write_text(json.dumps(self.manifest))

    def asset_tree(self):
        build=self.root/'build';build.mkdir()
        for name in ('web/dist','platform/dist','uniapp/dist/build/h5'):
            path=build/name;path.mkdir(parents=True);(path/'index.html').write_text('<html>compiled</html>')
        pc=build/'pc/.output/public/_nuxt';pc.mkdir(parents=True);(pc/'entry.js').write_text('// compiled runtime including bundled libraries\n')
        (pc.parent/'index.html').write_text('<html>generated SPA entry</html>')
        # These are installed/runtime files, not public browser output.
        private=build/'pc/.output/server/node_modules/private';private.mkdir(parents=True)
        (private/'index.js').write_text('do not distribute this dependency tree')
        return build

    def test_complete_sources_and_existing_baselines_survive(self):
        pack.snapshot(self.source,self.out)
        self.assertEqual((self.out/pack.MANIFEST).read_bytes(),(self.source/pack.MANIFEST).read_bytes())
        for entry in self.manifest['files']:
            self.assertEqual((self.out/entry['path']).read_bytes(),(self.source/entry['path']).read_bytes())
            self.assertEqual((self.out/entry['baseline_path']).read_bytes(),(self.source/entry['baseline_path']).read_bytes())

    def test_unlisted_dependencies_uploads_secrets_and_ssr_runtime_not_copied(self):
        for name in ('vendor/a.php','web/node_modules/a/index.js','pc/.output/server/node_modules/a/x.js','server/.env','server/public/storage/customer.jpg'):
            path=self.source/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_text('not release source')
        pack.snapshot(self.source,self.out)
        self.assertFalse(any(p.name in ('node_modules','vendor') for p in self.out.rglob('*')))
        self.assertFalse((self.out/'server/.env').exists())
        self.assertFalse((self.out/'server/public/storage/customer.jpg').exists())

    def test_manifest_cannot_smuggle_dependencies(self):
        for name in ('server/vendor/a.php','web/src/custom/node_modules/a.js'):
            with self.subTest(name=name):
                self.add(name,'bad');self.save()
                with self.assertRaisesRegex(ValueError,'installed dependency'):
                    pack.snapshot(self.source,self.root/('out-'+str(len(self.manifest['files']))))

    def test_runtime_source_is_not_mistaken_for_runtime_data(self):
        self.assertTrue(pack.allowed_source('server/app/common/value/runtime/RuntimeNamespace.php'))
        self.assertTrue(pack.allowed_source('web/src/modules/foo/runtime/Page.vue'))
        self.assertTrue(pack.allowed_source('server/runtime/.gitkeep'))
        self.assertFalse(pack.allowed_source('server/runtime/customer-data.json'))

    def test_secret_and_raw_core_archives_are_rejected(self):
        for path in ('server/.env','pc/.env.local','keys/signing.key','packages/core-web/old.tgz'):
            self.assertFalse(pack.allowed_source(path),path)
        self.assertTrue(pack.allowed_source('pc/.env.production'))

    def test_modified_source_is_rejected(self):
        (self.source/'web/src/page.vue').write_text('changed')
        with self.assertRaisesRegex(ValueError,'has changed'):pack.snapshot(self.source,self.out)

    def test_modified_upgrade_baseline_is_rejected(self):
        (self.source/self.manifest['files'][0]['baseline_path']).write_text('changed')
        with self.assertRaisesRegex(ValueError,'has changed'):pack.snapshot(self.source,self.out)

    def test_missing_frontend_source_not_masked_by_package_manifest(self):
        self.manifest['files']=[x for x in self.manifest['files'] if x['path']!='pc/src/page.vue'];self.save()
        with self.assertRaisesRegex(ValueError,'source is missing'):pack.snapshot(self.source,self.out)

    def test_case_collision_is_rejected(self):
        self.add('web/src/Page.vue','collision');self.save()
        with self.assertRaisesRegex(ValueError,'colliding'):pack.snapshot(self.source,self.out)

    def test_path_traversal_is_rejected(self):
        for path in ('../secret','/tmp/secret','web/../secret','web//secret','./web/a','web\\secret'):
            with self.subTest(path=path),self.assertRaises(ValueError):pack.safe_relative(path)

    def test_symlink_and_hardlink_are_rejected(self):
        original=self.source/'web/src/page.vue';original.unlink();original.symlink_to(self.source/'pc/src/page.vue')
        with self.assertRaisesRegex(ValueError,'symlink'):pack.snapshot(self.source,self.out)
        original.unlink();os.link(self.source/'pc/src/page.vue',original)
        with self.assertRaisesRegex(ValueError,'hard-linked'):pack.regular_file(self.source,'web/src/page.vue')

    def test_missing_existing_manifest_not_replaced_with_a_new_state_system(self):
        (self.source/pack.MANIFEST).unlink()
        with self.assertRaises(OSError):pack.snapshot(self.source,self.out)

    def test_local_core_specifier_is_rejected_even_with_a_local_archive(self):
        data=json.loads((self.source/'pc/package.json').read_text())
        data['dependencies']['@peanut-admin/client']='file:../old.tgz'
        (self.source/'pc/package.json').write_text(json.dumps(data))
        with self.assertRaisesRegex(ValueError,'non-portable'):pack.released_dependencies(self.source)

    def test_core_native_lock_mismatch_is_rejected(self):
        path=self.source/'server/composer.lock';data=json.loads(path.read_text());data['packages'][0]['version']='3.0.0';path.write_text(json.dumps(data))
        with self.assertRaisesRegex(ValueError,'lock does not match'):pack.released_dependencies(self.source)

    def test_development_version_is_not_a_published_release(self):
        path=self.source/'release-versions.json';data=json.loads(path.read_text());data['source_product_version']='4.0.0-dev';path.write_text(json.dumps(data))
        with self.assertRaisesRegex(ValueError,'fixed product'):pack.released_dependencies(self.source)

    def test_browser_outputs_keep_four_names_without_shipping_ssr_dependencies(self):
        build=self.asset_tree();pack.snapshot(self.source,self.out);assets=pack.public_assets(build,self.out)
        self.assertIn('server/public/pc/_nuxt/entry.js',assets)
        self.assertFalse((self.out/'pc/.output').exists())
        self.assertTrue((self.out/'server/public/admin/index.html').exists())
        self.assertFalse(any(p.name=='node_modules' for p in self.out.rglob('*')))

    def test_browser_php_and_dependency_folders_are_rejected(self):
        build=self.asset_tree();pack.snapshot(self.source,self.out)
        (build/'web/dist/evil.php').write_text('<?php echo 1;')
        with self.assertRaisesRegex(ValueError,'non-public'):pack.public_assets(build,self.out)

    def test_browser_source_collision_is_rejected(self):
        self.add('server/public/admin/index.html','managed source');self.save();pack.snapshot(self.source,self.out)
        with self.assertRaisesRegex(ValueError,'conflicts'):pack.public_assets(self.asset_tree(),self.out)

    def test_actual_archive_is_reproducible_and_dependency_free(self):
        import tarfile
        pack.snapshot(self.source,self.out);pack.public_assets(self.asset_tree(),self.out)
        writer=ROOT/'server/app/common/infrastructure/scaffold/DeterministicEditionArchive.php'
        php='require $argv[1]; (new app\\common\\infrastructure\\scaffold\\DeterministicEditionArchive())->write($argv[2],"peanut-test",$argv[3]);'
        for name in ('a.tar.gz','b.tar.gz'):
            subprocess.run(['php','-r',php,str(writer),str(self.out),str(self.root/name)],check=True,capture_output=True)
        self.assertEqual((self.root/'a.tar.gz').read_bytes(),(self.root/'b.tar.gz').read_bytes())
        with tarfile.open(self.root/'a.tar.gz') as tar:
            names=tar.getnames()
            self.assertIn('peanut-test/.peanut/application-manifest.json',names)
            self.assertIn('peanut-test/server/public/pc/_nuxt/entry.js',names)
            self.assertFalse(any('node_modules' in n.split('/') or 'vendor' in n.split('/') for n in names))

    def test_assembly_compares_build_to_the_unchanged_source_manifest(self):
        build=self.root/'compiled';pack.snapshot(self.source,build)
        (build/'web/src/page.vue').write_text('modified by build')
        run=subprocess.run(['python3',str(ROOT/'scripts/package-release-files.py'),'assemble',
                            '--application-root',str(self.source),'--build-root',str(build),
                            '--target',str(self.out)],text=True,capture_output=True)
        self.assertNotEqual(run.returncode,0)
        self.assertIn('native build changed',run.stderr)
        self.assertFalse((self.out/'release-manifest.txt').exists())

    def test_long_build_stderr_does_not_deadlock_the_existing_builder(self):
        source=(ROOT/'scripts/build-edition-installers').read_text()
        function=source.split('function editionInstallerRun(',1)[1].split('function editionInstallerDelete',1)[0]
        driver=self.root/'pipe-test.php'
        php_command = 'fwrite(STDERR,str_repeat("x",262144));echo "DONE";'
        driver.write_text('<?php\nfunction editionInstallerRun('+function+
            '$out=editionInstallerRun([PHP_BINARY,"-r",'+json.dumps(php_command)+']);'+
            'if(strlen($out)!==262148 || !str_ends_with($out,"DONE")){exit(1);}echo "pipe drained";')
        result=subprocess.run(['php',str(driver)],capture_output=True,text=True,timeout=10)
        self.assertEqual(result.returncode,0,result.stderr)
        self.assertEqual(result.stdout,'pipe drained')

    def test_real_installer_explains_missing_dependencies_before_configuration(self):
        import shutil
        server=self.root/'fresh/server/database';server.mkdir(parents=True)
        shutil.copyfile(ROOT/'server/database/install.php',server/'install.php')
        result=subprocess.run(['php',str(server/'install.php')],capture_output=True,text=True,timeout=10)
        self.assertEqual(result.returncode,2)
        self.assertIn('install --working-dir=server --no-scripts',result.stderr)
        self.assertNotIn('Fatal error',result.stderr)
        self.assertFalse((server.parent/'vendor').exists())

    def test_packager_generates_a_complete_spa_without_mutable_prebuilt_shortcut(self):
        script=(ROOT/'scripts/package-release.sh').read_text()
        self.assertIn('run generate',script)
        self.assertIn('NUXT_PC_RENDER_MODE=spa',script)
        self.assertNotIn('rsync -a',script)
        self.assertIn('run build',script)
        self.assertIn('--skip-client-build|--core-web-candidates=*) die',script)
        self.assertIn('DeterministicEditionArchive',script)
        self.assertIn('--application-root=',(ROOT/'scripts/build-edition-installers').read_text())


if __name__=='__main__':unittest.main(verbosity=2)
