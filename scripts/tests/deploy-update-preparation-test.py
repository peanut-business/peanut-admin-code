#!/usr/bin/env python3
"""Native preparation failure propagation and first-install storage ordering."""
from pathlib import Path
import json
import os
import shutil
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='peanut-prepare-exit-') as name:
    root = Path(name)
    def write(path, text):
        target = root / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(text)
    write('.peanut/application-manifest.json', '{"application":{"edition":"standalone","version":"1.0.0"}}')
    vendor_script = Path('server/docker/scripts/vendor-state.py')
    (root / vendor_script.parent).mkdir(parents=True)
    shutil.copy2(ROOT / vendor_script, root / vendor_script)
    module_root = 'app/modules/fixture/example'
    write('modules.lock', json.dumps({'modules': [{'modules': [{'key': 'fixture.example', 'root': 'server/' + module_root}]}]}))
    write('server/' + module_root + '/composer.json', '{"name":"fixture/example","version":"1.0.2"}')
    write('server/composer.json', '{"require":{"fixture/example":"1.0.1"}}')
    write('server/vendor/autoload.php', r'''<?php
namespace think {
    class App {
        public $env;
        public $config;
        public function __construct($root) {
            set_exception_handler(function (\Throwable $error) { echo "framework rendered exception\n"; });
            $this->env = new class { public function get($key, $default) { return $default; } };
            $this->config = new class {
                private $deployment;
                public function set($config, $key) { $this->deployment = $config; }
                public function get($key) { return $this->deployment['mode']; }
            };
        }
        public function loadEnv($name) {}
    }
}
namespace app\common\value\installation {
    class ApplicationSourceIdentity {
        public static function loadGeneratedApp($root) {
            if (getenv('PREPARE_FAILURE') === 'identity') throw new \RuntimeException('identity rejected');
        }
    }
    class ApplicationReleaseVersions {
        public static function load($path) { return new self(); }
        public function toArray() { return ['core_web' => []]; }
    }
}
namespace app\common\infrastructure\scaffold {
    class ReleaseDependencyIdentity { public static function verifyArchives(...$args) {
        if (getenv('PREPARE_FAILURE') === 'archive') throw new \RuntimeException('archive rejected');
    } }
}
namespace app\platform\infrastructure\module {
    class ModuleLockResolver { public function __construct(...$args) {} public function all() {
        if (getenv('PREPARE_FAILURE') === 'module-lock') throw new \RuntimeException('module lock rejected');
        return [];
    } }
}
namespace app\common\module {
    class Dependencies {
        public function __construct(...$args) {}
        public function prepareRelease(...$args) {
            file_put_contents('prepare-marker', 'started');
            if (getenv('PREPARE_FAILURE') === 'build') throw new \RuntimeException('frontend failed');
            if (getenv('PREPARE_FAILURE') !== 'stale') {
                $lock = json_decode(file_get_contents('server/composer.lock'), true);
                $lock['packages'][0]['version'] = '1.0.2';
                file_put_contents('server/composer.lock', json_encode($lock));
            }
            return ['operations' => [], 'frontend_adoption' => []];
        }
    }
}
''')
    write('server/vendor/topthink/framework/src/helper.php', '<?php\n')
    write('server/config/deployment.php', "<?php return ['mode' => 'standalone'];\n")
    write('server/module', '<?php file_put_contents(getenv("PREPARE_COMPILE_MARKER"), "compiled"); echo "{}";\n')
    (root / 'scripts').mkdir()
    shutil.copy2(ROOT / 'scripts/deploy-update.sh', root / 'scripts/deploy-update.sh')
    subprocess.run(['git', 'init', '--quiet', str(root)], check=True)
    marker = root / 'compile-marker'
    lock_error = 'ValueError: locked package has a non-HTTPS source'
    for failure, expected_error in (('identity', '[deploy-update] identity rejected'), ('build', '[deploy-update] frontend failed'),
                                    ('stale', lock_error), ('wrong-root', lock_error), ('wrong-pin', lock_error),
                                    ('unselected', lock_error), ('remote-source', lock_error), ('', '')):
        url = 'elsewhere/fixture' if failure == 'wrong-root' else module_root
        package = {'name': 'fixture/example', 'version': '1.0.1', 'dist': {'type': 'path', 'url': url}}
        if failure == 'wrong-pin':
            package['version'] = '1.0.0'
        if failure == 'unselected':
            package['name'] = 'other/example'
        if failure == 'remote-source':
            package['source'] = {'type': 'git', 'url': 'http://example.invalid/package'}
        write('server/composer.lock', json.dumps({'content-hash': '0' * 32, 'packages': [package]}))
        (root / 'prepare-marker').unlink(missing_ok=True)
        if marker.exists():
            marker.unlink()
        env = dict(os.environ, PREPARE_FAILURE=failure, PREPARE_COMPILE_MARKER=str(marker))
        result = subprocess.run(['bash', 'scripts/deploy-update.sh', '--prepare-release', '--version=1.0.0'],
                                cwd=root, env=env, text=True, capture_output=True, timeout=20)
        assert (root / 'prepare-marker').exists() == (failure in ('build', 'stale', '')), failure
        if failure:
            assert result.returncode != 0, (failure, result.stdout, result.stderr)
            assert expected_error in result.stderr.splitlines(), result.stderr
            assert not marker.exists(), 'compile must not follow failed preparation'
            assert '[deploy-update] Prepared' not in result.stdout
        else:
            assert result.returncode == 0, result.stderr
            assert marker.exists() and '[deploy-update] Prepared' in result.stdout
        print(f'{failure or "success"}: passed')

    # First-install also moves legacy storage. Reject every read-only preflight
    # before that move, then keep preparation and retry on the native path.
    shutil.copy2(ROOT / 'server/docker/scripts/migrate-storage-layout.py',
                 root / 'server/docker/scripts/migrate-storage-layout.py')
    write('fixture-bin/composer', '#!/bin/sh\n'
          'test "$*" = "run-script post-autoload-dump" || exit 91\n'
          'printf refreshed > ../composer-marker\n')
    (root / 'fixture-bin/composer').chmod(0o700)
    write('scripts/check-installation-contract.php',
          '<?php file_put_contents("contract-marker", "checked");\n')
    original_public = b'public fixture content'
    original_private = b'private fixture content'

    def reset_first_install(failure):
        for path in ('server/storage', 'server/public/storage', 'server/private/storage'):
            target = root / path
            if target.is_symlink():
                target.unlink()
            elif target.exists():
                shutil.rmtree(target)
        static = root / 'server/public/admin'
        if static.is_symlink():
            static.unlink()
        write('server/public/storage/fixture.txt', original_public.decode())
        write('server/private/storage/fixture.txt', original_private.decode())
        package = {'name': 'fixture/example',
                   'version': '1.0.0' if failure == 'wrong-pin' else '1.0.1',
                   'dist': {'type': 'path', 'url': module_root}}
        write('server/composer.lock', json.dumps({'content-hash': '0' * 32, 'packages': [package]}))
        for path in ('compile-marker', 'prepare-marker', 'composer-marker', 'contract-marker'):
            (root / path).unlink(missing_ok=True)
        if failure == 'linked-static':
            (root / 'outside-static').mkdir(exist_ok=True)
            static.symlink_to(root / 'outside-static', target_is_directory=True)

    first_env = dict(os.environ, PREPARE_COMPILE_MARKER=str(marker),
                     PATH=str(root / 'fixture-bin') + os.pathsep + os.environ['PATH'])
    for failure, expected_error in (
        ('identity', '[deploy-update] identity rejected'),
        ('archive', '[deploy-update] archive rejected'),
        ('module-lock', '[deploy-update] module lock rejected'),
        ('wrong-pin', lock_error),
        ('linked-static', 'deploy-update: static destination must not be linked'),
        ('', ''),
    ):
        reset_first_install(failure)
        result = subprocess.run(['bash', 'scripts/deploy-update.sh', '--prepare-first-install'],
                                cwd=root, env=dict(first_env, PREPARE_FAILURE=failure),
                                text=True, capture_output=True, timeout=20)
        if failure:
            assert result.returncode != 0, (failure, result.stdout, result.stderr)
            assert expected_error in result.stderr.splitlines(), result.stderr
            assert (root / 'server/public/storage').is_dir() and not (root / 'server/public/storage').is_symlink(), (
                failure, 'preflight rejection must not move legacy public storage')
            assert (root / 'server/public/storage/fixture.txt').read_bytes() == original_public
            assert (root / 'server/private/storage/fixture.txt').read_bytes() == original_private
            assert not (root / 'server/storage').exists(), 'preflight must not create canonical storage'
            assert not (root / 'prepare-marker').exists()
            assert not marker.exists()
        else:
            assert result.returncode == 0, (result.stdout, result.stderr)
            assert (root / 'server/public/storage').is_symlink()
            assert os.readlink(root / 'server/public/storage') == '../storage/public'
            assert (root / 'server/storage/public/fixture.txt').read_bytes() == original_public
            assert (root / 'server/storage/private/fixture.txt').read_bytes() == original_private
            assert not (root / 'server/private/storage').exists()
            for path in ('prepare-marker', 'compile-marker', 'composer-marker', 'contract-marker'):
                assert (root / path).is_file(), path
            # A second preparation sees the already canonical layout and preserves bytes.
            repeated = subprocess.run(['bash', 'scripts/deploy-update.sh', '--prepare-first-install'],
                                      cwd=root, env=dict(first_env, PREPARE_FAILURE=''),
                                      text=True, capture_output=True, timeout=20)
            assert repeated.returncode == 0, (repeated.stdout, repeated.stderr)
            assert (root / 'server/storage/public/fixture.txt').read_bytes() == original_public
            assert (root / 'server/storage/private/fixture.txt').read_bytes() == original_private
            print('first-install canonical retry: passed')
        print(f'first-install {failure or "success"}: passed')
