ARG PYTHON_IMAGE
ARG PHP_IMAGE
FROM ${PYTHON_IMAGE} AS python
FROM ${PHP_IMAGE}

COPY inputs.json /opt/peanut/inputs.json
# The complete official Python installation, including stdlib, extensions and config.
# Its Debian runtime libraries are installed from the same fixed signed snapshot below.
COPY --from=python /usr/local/ /usr/local/
RUN <<'SH'
set -eux
php -r '$m=json_decode(file_get_contents("/opt/peanut/inputs.json"),true,512,JSON_THROW_ON_ERROR); foreach($m["snapshot"]["packages"] as $p) echo $p["Package"]."=".$p["Version"]."\n";' > /tmp/package-spec
php -r '$m=json_decode(file_get_contents("/opt/peanut/inputs.json"),true); foreach(["inRelease","packages"] as $n) { $s=$m["snapshot"]; $b=file_get_contents($s[$n."Url"]); if(hash("sha256",$b)!==$s[$n."Sha256"]) exit(1); file_put_contents("/tmp/".$n.($n==="packages"?".xz":""),$b); }'
gpgv --keyring /usr/share/keyrings/debian-archive-keyring.gpg /tmp/inRelease
rm -f /etc/apt/sources.list /etc/apt/sources.list.d/*
php -r '$m=json_decode(file_get_contents("/opt/peanut/inputs.json"),true); echo "deb [check-valid-until=no signed-by=/usr/share/keyrings/debian-archive-keyring.gpg] ".$m["snapshot"]["url"]." bookworm main\n";' > /etc/apt/sources.list
apt-get update
php <<'PHP'
<?php
$snapshot = json_decode(file_get_contents('/opt/peanut/inputs.json'), true, 512, JSON_THROW_ON_ERROR)['snapshot'];
$releases = glob('/var/lib/apt/lists/*_InRelease');
if (count($releases) !== 1 || hash_file('sha256', $releases[0]) !== $snapshot['inReleaseSha256']) {
    throw new RuntimeException('APT_ACTUAL_RELEASE_DIFFERS');
}
$indexes = array_values(array_filter(glob('/var/lib/apt/lists/*_binary-amd64_Packages*'),
    static fn($path) => preg_match('/_Packages(?:\.(?:lz4|xz|gz|bz2|lzma))?$/', $path) === 1));
if (count($indexes) !== 1) throw new RuntimeException('APT_ACTUAL_INDEX_AMBIGUOUS');
$wanted = array_column($snapshot['packages'], null, 'Package');
$seen = [];
$hashes = [];
foreach (['fixed' => '/tmp/packages.xz', 'actual' => $indexes[0]] as $kind => $path) {
    $process = proc_open(['/usr/lib/apt/apt-helper', 'cat-file', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('APT_INDEX_DECOMPRESSION_FAILED');
    $hash = hash_init('sha256');
    $record = [];
    $finish = static function () use (&$record, &$seen, $wanted, $kind): void {
        $name = $record['Package'] ?? '';
        if ($kind === 'actual' && isset($wanted[$name])) {
            if (isset($seen[$name]) || $record != $wanted[$name]) throw new RuntimeException('APT_DIRECT_RECORD_DIFFERS: ' . $name);
            $seen[$name] = $record;
        }
        $record = [];
    };
    while (($line = fgets($pipes[1])) !== false) {
        hash_update($hash, $line);
        if (trim($line) === '') $finish();
        elseif (preg_match('/^(Package|Version|Filename|SHA256): (.*)$/', rtrim($line, "\r\n"), $match)) $record[$match[1]] = $match[2];
    }
    $finish();
    fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException('APT_INDEX_DECOMPRESSION_FAILED: ' . $error);
    $hashes[$kind] = hash_final($hash);
}
if ($hashes['fixed'] !== $snapshot['packagesContentSha256'] || $hashes['actual'] !== $hashes['fixed'] || count($seen) !== count($wanted)) {
    throw new RuntimeException('APT_ACTUAL_INDEX_DIFFERS');
}
file_put_contents('/opt/peanut/apt-input-identity.json', json_encode(['inReleaseSha256' => $snapshot['inReleaseSha256'],
    'packagesContentSha256' => $hashes['actual'], 'directPackages' => $snapshot['packages']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
PHP
xargs -a /tmp/package-spec apt-get install -y --no-install-recommends
docker-php-ext-install -j2 pdo_mysql zip
ldconfig
rm -rf /var/lib/apt/lists/* /tmp/package-spec /tmp/packages.xz /tmp/inRelease
SH

RUN python3 - <<'PY'
import hashlib, json, pathlib, subprocess, urllib.request
m = json.loads(pathlib.Path('/opt/peanut/inputs.json').read_text())
for name in ('node', 'composer', 'pnpm'):
    item = m[name]
    data = urllib.request.urlopen(item['url']).read()
    if hashlib.sha256(data).hexdigest() != item['sha256']:
        raise RuntimeError('Official tool download hash differs: ' + name)
    path = pathlib.Path('/tmp/' + name)
    path.write_bytes(data)
    if name == 'node':
        subprocess.run(['tar', '-xJf', str(path), '-C', '/usr/local', '--strip-components=1'], check=True)
    elif name == 'composer':
        path.replace('/usr/local/bin/composer')
        pathlib.Path('/usr/local/bin/composer').chmod(0o755)
    else:
        target = pathlib.Path('/usr/local/lib/node_modules/pnpm')
        target.mkdir(parents=True)
        subprocess.run(['tar', '-xzf', str(path), '-C', str(target), '--strip-components=1'], check=True)
        pathlib.Path('/usr/local/bin/pnpm').symlink_to('../lib/node_modules/pnpm/bin/pnpm.cjs')
    if path.exists():
        path.unlink()
PY
COPY identity.py /opt/peanut/identity.py
RUN python3 /opt/peanut/identity.py > /opt/peanut/toolchain-identity.json
ENV PATH=/usr/local/bin:/usr/local/sbin:/usr/sbin:/usr/bin:/sbin:/bin
