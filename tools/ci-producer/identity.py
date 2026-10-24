"""Bind the actual standard producer tools and runtime libraries to the image inputs."""
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys


def command(*args):
    return subprocess.check_output(args, text=True).strip()


root = Path('/opt/peanut')
manifest = json.loads((root / 'inputs.json').read_text())
versions = dict(php=command('php', '-r', 'echo PHP_VERSION;'),
                python3=command('python3', '-c', 'import platform; print(platform.python_version())'),
                node=command('node', '--version').removeprefix('v'),
                pnpm=command('pnpm', '--version'),
                composer=re.search(r'Composer version ([0-9.]+)', command('composer', '--version', '--no-ansi'))[1])
if versions != manifest['tools']:
    raise RuntimeError('Actual tools differ from fixed producer requirements: ' + json.dumps(versions))
paths = {name: str(Path(shutil.which(name)).resolve()) for name in versions}
if any(not path.startswith('/usr/local/') for path in paths.values()):
    raise RuntimeError('Producer command escaped the selected tool installation')
if command('php', '-r', 'echo realpath(PHP_BINARY);') != paths['php']:
    raise RuntimeError('PHP_BINARY does not bind the selected PHP executable')
if str(Path(sys.executable).resolve()) != paths['python3'] or command('node', '-p', 'process.execPath') != paths['node']:
    raise RuntimeError('Interpreter subprocess path differs')
child = command('php', '-r', 'passthru("php -r " . escapeshellarg("echo realpath(PHP_BINARY);"));')
if child != paths['php']:
    raise RuntimeError('PHP child process escaped the selected executable')
extensions = json.loads(command('php', '-r', 'echo json_encode(get_loaded_extensions());'))
if not set(manifest['extensions']).issubset(set(extensions)):
    raise RuntimeError('Required producer PHP extensions are absent')
libraries = {}
for file in sorted(Path('/usr/local/lib/python3.14/lib-dynload').glob('*.so')):
    linked = command('ldd', str(file))
    if 'not found' in linked:
        raise RuntimeError('Incomplete Python runtime libraries: ' + str(file))
    libraries[str(file)] = hashlib.sha256(file.read_bytes()).hexdigest()
    for library in re.findall(r'=> (/[^ ]+)', linked):
        library = Path(library).resolve()
        libraries[str(library)] = hashlib.sha256(library.read_bytes()).hexdigest()
packages = command('dpkg-query', '-W', '-f=${binary:Package}=${Version}\n')
value = dict(schema=1, inputsSha256=hashlib.sha256((root / 'inputs.json').read_bytes()).hexdigest(),
             tools=versions, paths=paths,
             executableSha256={name: hashlib.sha256(Path(path).read_bytes()).hexdigest() for name, path in paths.items()},
             extensions=sorted(extensions), osRelease=Path('/etc/os-release').read_text(),
             packages=packages.splitlines(), packagesSha256=hashlib.sha256(packages.encode()).hexdigest(),
             pythonExtensions=libraries)
print(json.dumps(value, indent=2))
