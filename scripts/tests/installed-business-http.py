#!/usr/bin/env python3
"""已安装合成应用的真实 HTTP 切片。只使用明确交接和登记，不生成/重装/清库。
凭据由本机文件读入内存；结果只记录断言和响应状态，不记录令牌、Cookie或密码。
"""
from __future__ import annotations
import argparse
import base64
import hashlib
import http.client
import json
import os
from pathlib import Path
import re
import runpy
import secrets
import socket
import subprocess
import time
import urllib.parse

class VerificationFailure(RuntimeError):
    pass

def require(condition: bool, code: str) -> None:
    if not condition:
        raise VerificationFailure(code)

def private_values(path: Path, php: str) -> dict[str, str]:
    st = path.lstat()
    require(path.is_file() and not path.is_symlink() and st.st_nlink == 1 and st.st_mode & 0o777 == 0o600, 'PRIVATE_FILE_PERMISSIONS')
    # 使用应用环境相同的 PHP INI 解释器，输出仅被父进程内存捕获，不打印或存证。
    p = subprocess.run([php, '-r', '$v=parse_ini_file($argv[1],false,INI_SCANNER_RAW);echo json_encode($v,JSON_THROW_ON_ERROR);', str(path)], capture_output=True, text=True, timeout=10)
    require(p.returncode == 0, 'PRIVATE_FILE_PARSE')
    return json.loads(p.stdout)

class Session:
    def __init__(self, port: int, host: str, events: list[dict]):
        self.port, self.host, self.events = port, host, events
        self.token = ''
        self.cookie = ''
        self.response_request_ids = ()
        self.last_request_id = ''

    def request(self, method: str, path: str, data=None, *, statuses=(200,), token=None, label=None, headers=None):
        self.response_request_ids = ()
        require(path.startswith('/') and not path.startswith('//'), 'HTTP_PATH')
        self.last_request_id = 'owned-http-' + secrets.token_hex(12)
        hs = {'Host': self.host, 'Accept': 'application/json', 'User-Agent': 'PeanutOwnedBusinessProbe/1.0', 'Origin': 'http://' + self.host, 'Sec-Fetch-Site': 'same-origin', 'X-Request-Id': self.last_request_id}
        active = self.token if token is None else token
        if active:
            hs['Authorization'] = 'Bearer ' + active
        if self.cookie:
            hs['Cookie'] = self.cookie
        body = None
        if data is not None:
            body = data if isinstance(data, bytes) else json.dumps(data, ensure_ascii=False).encode()
            hs['Content-Type'] = 'application/json'
        hs.update(headers or {})
        connection = http.client.HTTPConnection('127.0.0.1', self.port, timeout=20)
        try:
            connection.request(method, path, body, hs)
            response = connection.getresponse()
            self.response_request_ids = tuple(value for name, value in response.getheaders()
                                              if name.lower() == 'x-request-id')
            raw = response.read(2_000_001)
            require(len(raw) <= 2_000_000, 'HTTP_RESPONSE_TOO_LARGE')
            cookie = response.getheader('Set-Cookie')
            if cookie:
                self.cookie = cookie.split(';', 1)[0]
            try:
                result = json.loads(raw) if raw else {}
            except json.JSONDecodeError:
                result = {'_non_json': True}
            event = {'case': label or path.split('?')[0], 'method': method, 'http_status': response.status, 'business_code': result.get('code'), 'error_code': result.get('error_code'), 'json': '_non_json' not in result}
            if isinstance(result.get('error'), dict):
                event['problem_code'] = result['error'].get('code')
            self.events.append(event)
            print(json.dumps(event, ensure_ascii=False), flush=True)
            require(response.status in statuses, 'HTTP_STATUS:' + event['case'] + ':' + str(response.status))
            require('_non_json' not in result, 'HTTP_EXPECTED_JSON:' + event['case'])
            if response.status < 300 and result.get('code') is not None:
                require(result['code'] == 20000, 'BUSINESS_REJECTED:' + event['case'] + ':' + str(result['code']))
            return result
        finally:
            connection.close()

    def upload(self, image: bytes, name: str):
        boundary = 'PeanutFixture' + secrets.token_hex(12)
        payload = ('--' + boundary + '\r\nContent-Disposition: form-data; name="file"; filename="' + name + '.png"\r\nContent-Type: image/png\r\n\r\n').encode() + image + ('\r\n--' + boundary + '--\r\n').encode()
        return self.data('POST', '/adminapi/peanut.file.upload.image', payload, label='synthetic-image-upload', headers={'Content-Type': 'multipart/form-data; boundary=' + boundary})

    def download(self, url: str, label: str, statuses=(200,)) -> bytes:
        parsed = urllib.parse.urlsplit(url)
        require(not parsed.scheme or parsed.scheme in ['http', 'https'], 'DELIVERY_SCHEME')
        require(not parsed.netloc or parsed.netloc == self.host, 'DELIVERY_OTHER_HOST')
        path = parsed.path + ('?' + parsed.query if parsed.query else '')
        require(path.startswith('/api/storage/delivery'), 'EXPECTED_SIGNED_STORAGE_DELIVERY')
        c = http.client.HTTPConnection('127.0.0.1', self.port, timeout=20)
        try:
            c.request('GET', path, headers={'Host': self.host, 'User-Agent': 'PeanutOwnedBusinessProbe/1.0'})
            r = c.getresponse(); data = r.read(5_000_001)
            event = {'case': label, 'method': 'GET', 'http_status': r.status, 'bytes': len(data)}
            self.events.append(event); print(json.dumps(event), flush=True)
            require(r.status in statuses and len(data) <= 5_000_000, 'DELIVERY_FAILED:' + label + ':' + str(r.status))
            if r.status == 422:
                require(r.getheader('Content-Type', '').split(';')[0] == 'application/json'
                        and json.loads(data).get('data', {}).get('error_code') == 'STORAGE_DELIVERY_INPUT_INVALID',
                        'UNEXPECTED_DELIVERY_REJECTION')
                event['error_code'] = 'STORAGE_DELIVERY_INPUT_INVALID'
            return data
        finally:
            c.close()

    def login(self, email: str, password: str, tenant_code='default'):
        r = self.request('POST', '/adminapi/tenant/session/login', {'email': email, 'password': password, 'tenant_code': tenant_code}, label='tenant-login')
        data = r.get('data', {})
        self.token = data.get('access_token', '')
        require(bool(self.token), 'TENANT_LOGIN_TOKEN_MISSING:keys=' + ','.join(data.keys()))
        return data

    def data(self, method, path, data=None, **kwargs):
        return self.request(method, path, data, **kwargs).get('data')

def file_chain(admin: Session, beta: Session | None, marker: str, snapshot, tenant_id: str) -> dict:
    png = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=')
    uploaded = admin.upload(png, marker)
    require(isinstance(uploaded, dict) and isinstance(uploaded.get('id'), int)
            and re.fullmatch(r'file_[0-9a-f]{32}', uploaded.get('file_key', '')) is not None,
            'UPLOAD_STABLE_IDENTITY')
    key, file_id = uploaded['file_key'], uploaded['id']
    public_url = uploaded['url']
    require(admin.download(public_url, 'uploaded-original-bytes') == png, 'UPLOAD_BYTES')
    query = urllib.parse.parse_qs(urllib.parse.urlsplit(public_url).query)
    require(query.get('file_key') == [key] and query.get('tenant_id') == [tenant_id]
            and len(query.get('token', [])) == 1, 'PUBLIC_GRANT_TARGET')
    # The successful download verified this signed token. Bind its claims to the same URL and login.
    encoded, signature = query['token'][0].split('.')
    claims = json.loads(base64.urlsafe_b64decode(encoded + '=' * (-len(encoded) % 4)))
    require(signature and claims['fk'] == key and str(claims['tid']) == tenant_id
            and claims['vis'] == 'public' and claims['replay'] == 'bounded'
            and type(claims['exp']) is int, 'PUBLIC_GRANT_IDENTITY')
    def listing(session, label):
        page = session.data('GET', '/adminapi/peanut.file.list?' + urllib.parse.urlencode(
            {'type': 10, 'name': marker, 'page_no': 1, 'page_size': 20}), label=label)
        require(isinstance(page, dict) and isinstance(page.get('lists'), list), 'FILE_LIST_SHAPE')
        return page['lists']
    require(any(row['id'] == file_id and row['file_key'] == key
                for row in listing(admin, 'owner-file-list')), 'OWNER_FILE_MISSING')
    if beta:
        before = snapshot(key)
        require(not any(row.get('file_key') == key for row in listing(beta, 'foreign-file-list')),
                'FOREIGN_FILE_VISIBLE')
        denied = beta.request('POST', '/adminapi/peanut.file.rename',
                              {'id': file_id, 'name': 'foreign-denied'}, statuses=(400,),
                              label='foreign-file-rename-denied')
        require(denied.get('data', {}).get('error_code') == 'INVALID_ARGUMENT',
                'FOREIGN_RENAME_DENIAL_REASON')
        require(snapshot(key) == before, 'FOREIGN_FILE_MUTATED')
    preview = admin.data('POST', '/adminapi/api/v1/files/visibility/preview',
                         {'file_keys': [key]}, label='private-visibility-preview')
    require(admin.download(public_url, 'same-public-link-before-change') == png, 'PUBLIC_GRANT_REPLAY')
    converted = admin.data('POST', '/adminapi/api/v1/files/visibility/batches', {
        'confirmation': preview['confirmation'], 'signature': preview['signature'],
        'impact_confirmed': True,
    }, headers={'Idempotency-Key': 'http-private-' + marker}, label='private-visibility-submit')
    require(converted['status'] == 'completed', 'PRIVATE_CONVERSION_INCOMPLETE')
    require(png not in admin.download(public_url, 'old-public-link-denied', statuses=(403, 404, 422)), 'OLD_PUBLIC_BYTES_DISCLOSED')
    require(time.time() < claims['exp'], 'PUBLIC_GRANT_EXPIRED_BEFORE_DENIAL')
    private = admin.data('POST', '/adminapi/api/v1/files/resolve',
                         {'file_key': key, 'operation': 'download_original'}, label='owner-private-resolve')
    require(admin.download(private['url'], 'owner-private-exact-bytes') == png, 'PRIVATE_BYTES')
    if beta:
        before = snapshot(key)
        beta.request('POST', '/adminapi/api/v1/files/resolve',
                     {'file_key': key, 'operation': 'download_original'}, statuses=(403, 404),
                     label='foreign-private-resolve-denied')
        require(snapshot(key) == before, 'FOREIGN_RESOLVE_WROTE_FILE_STATE')
    # The successful private download consumed its single-use token. Keep a distinct, unused grant for deletion.
    unused = admin.data('POST', '/adminapi/api/v1/files/resolve',
                        {'file_key': key, 'operation': 'download_original'}, label='private-link-before-delete')
    previous = urllib.parse.parse_qs(urllib.parse.urlsplit(private['url']).query)
    fresh = urllib.parse.parse_qs(urllib.parse.urlsplit(unused['url']).query)
    require(unused.get('file_key') == unused.get('delivery_file_key') == key
            and unused.get('source_sha256') == hashlib.sha256(png).hexdigest() and fresh.get('file_key') == [key]
            and len(fresh.get('token', [])) == len(previous.get('token', [])) == 1
            and fresh['token'] != previous['token'], 'DELETE_PROBE_GRANT_MISMATCH')
    deleted = admin.data('POST', '/adminapi/peanut.file.delete',
                         {'ids': [file_id]}, label='owned-file-delete')
    require(isinstance(deleted, dict) and deleted.get('files_deleted') == 1
            and deleted.get('storage_deleted') == 1, 'FILE_DELETE_RESULT')
    require(png not in admin.download(public_url, 'deleted-public-link-denied', statuses=(403, 404)), 'DELETED_PUBLIC_BYTES_DISCLOSED')
    require(png not in admin.download(unused['url'], 'deleted-private-link-denied', statuses=(403, 404)),
            'DELETED_PRIVATE_BYTES_DISCLOSED')
    require(unused['expires_at'] > time.time() * 1000, 'DELETE_PROBE_GRANT_EXPIRED')
    require(not any(row['id'] == file_id for row in listing(admin, 'deleted-file-hidden')),
            'DELETED_FILE_VISIBLE')
    return {'file_id': file_id, 'sha256': hashlib.sha256(png).hexdigest(),
            'public_download_exact': True, 'private_download_exact': True,
            'old_public_link_denied': True, 'unredeemed_private_link_denied_after_delete': True, 'deleted_private_link_denied': True,
            'two_tenants': beta is not None, 'foreign_state_unchanged': beta is not None,
            'export_and_document_references': 'NOT_APPLICABLE: no export/reference HTTP producer in core File'}

def run_application(a: dict, output: Path) -> dict:
    php = str(Path(a['php_binary']).resolve())
    require(Path(php).is_file() and os.access(php, os.X_OK), 'PHP_EXECUTABLE_REQUIRED')
    version = subprocess.run([php, '-r', 'echo PHP_VERSION;'], text=True, capture_output=True, timeout=10)
    require(version.returncode == 0 and version.stdout == '8.3.24', 'PHP_VERSION_MISMATCH')
    app = Path(a['app']).resolve()
    resource_registry_path = Path(a['resource_registry'])
    require(resource_registry_path.is_absolute() and not resource_registry_path.is_symlink()
            and hashlib.sha256(resource_registry_path.read_bytes()).hexdigest() == a['resource_registry_sha256'],
            'MAINTAINER_RESOURCE_REGISTRY_IDENTITY_MISMATCH')
    resource_registry = json.loads(resource_registry_path.read_text())
    database_id = {'standalone': 'peanut-059-st-mysql84',
                   'multi-tenant': 'peanut-059-mt-mysql84'}[a['edition']]
    databases = [r for r in resource_registry['resources']['databases']
                 if r.get('stable_resource_id') == database_id]
    require(len(databases) == 1 and Path(databases[0]['application_root']).resolve() == app
            and 'security_http' in databases[0]['allowed_scenarios']
            and databases[0]['database'] == a['resource']['database']
            and databases[0]['server_uuid'] == a['database_identity']['uuid'],
            'REGISTERED_059_APPLICATION_REQUIRED')
    manifest_path = app / '.peanut/application-manifest.json'
    require(not manifest_path.is_symlink()
            and hashlib.sha256(manifest_path.read_bytes()).hexdigest() == a['manifest_sha256'],
            'APPLICATION_MANIFEST_IDENTITY_MISMATCH')
    manifest = json.loads(manifest_path.read_text())
    identity = a['source_identity']
    require(identity['kind'] in ('git', 'local')
            and (identity['kind'] != 'git'
                 or (set(identity) == {'kind', 'commit', 'tree'}
                     and re.fullmatch(r'[a-f0-9]{40}', identity['commit']) is not None
                     and re.fullmatch(r'[a-f0-9]{40}', identity['tree']) is not None))
            and (identity['kind'] != 'local'
                 or (set(identity) == {'kind', 'snapshot_sha256'}
                     and re.fullmatch(r'[a-f0-9]{64}', identity['snapshot_sha256']) is not None))
            and manifest['template']['source_identity'] == identity
            and manifest['generation_source']['source_identity'] == identity,
            'APPLICATION_SOURCE_MISMATCH')
    archive = a['template_archive']
    archive_path = Path(archive['path'])
    require(archive_path.is_file() and not archive_path.is_symlink(), 'APPLICATION_TEMPLATE_ARCHIVE_REQUIRED')
    with archive_path.open('rb') as archive_file:
        archive_digest = hashlib.sha256()
        for chunk in iter(lambda: archive_file.read(1024 * 1024), b''):
            archive_digest.update(chunk)
        archive_sha256 = archive_digest.hexdigest()
    require(archive_sha256 == archive['sha256']
            and manifest['template_package']['protocol'] == 'peanut.template-package.v1'
            and manifest['template_package']['archive_sha256'] == archive['sha256'],
            'APPLICATION_TEMPLATE_ARCHIVE_MISMATCH')
    require(hashlib.sha256((app / 'server/composer.lock').read_bytes()).hexdigest() == a['lock_sha256'], 'APPLICATION_LOCK_DRIFT')
    require(manifest['application']['edition'] == a['edition'], 'APPLICATION_EDITION_MISMATCH')
    require(hashlib.sha256((app / 'modules.lock').read_bytes()).hexdigest() == a['module_lock_sha256'],
            'APPLICATION_MODULE_LOCK_DRIFT')
    registry = json.loads((app / 'resources/project-resources.json').read_text())
    selected = [r for r in registry['resources']['local_listeners'] if r.get('stable_resource_id') == a['http_resource_id']]
    require(len(selected) == 1 and selected[0]['port'] == a['http_port'] and selected[0]['host'] == '127.0.0.1', 'HTTP_RESOURCE_MISMATCH')
    own_databases = [r for r in registry['resources']['databases']
                     if r.get('stable_resource_id') == database_id]
    require(len(own_databases) == 1 and own_databases[0]['database'] == databases[0]['database']
            and own_databases[0]['upstream_endpoint'] == databases[0]['upstream_endpoint'],
            'APPLICATION_DATABASE_REGISTRATION_MISMATCH')
    env_file = Path(a['backend_env'])
    require(env_file.resolve() == app / 'server/.env', 'NATIVE_APPLICATION_ENV_REQUIRED')
    private_values(env_file, php)  # 权限检查，不复制或打印环境。
    credentials = private_values(Path(a['installation_env']), php)
    tmp = Path(a['business_task_root']); tmp.mkdir(mode=0o700, parents=True, exist_ok=True)
    require(not tmp.is_symlink(), 'TMP_SYMLINK')
    env = {k: os.environ[k] for k in ['HOME', 'LANG'] if k in os.environ}
    tool = a['qualification_tool']
    tool_root = Path(__file__).resolve().parents[2]
    require(Path(tool['root']).resolve() == tool_root, 'NATIVE_QUALIFICATION_TOOL_ROOT_MISMATCH')
    tool_head = subprocess.run(['git', '-C', str(tool_root), 'rev-parse', 'HEAD'],
                               text=True, capture_output=True, timeout=10)
    tool_file = tool_root / 'scripts/p0e-runtime-qualification'
    committed_tool = subprocess.run(['git', '-C', str(tool_root), 'show',
                                    tool['commit'] + ':scripts/p0e-runtime-qualification'],
                                   capture_output=True, timeout=10)
    require(tool_head.returncode == committed_tool.returncode == 0
            and tool_head.stdout.strip() == tool['commit']
            and committed_tool.stdout == tool_file.read_bytes(), 'NATIVE_QUALIFICATION_TOOL_SOURCE_MISMATCH')
    runtime, admission = a['runtime'], a['runtime_admission']
    require(Path(runtime['root']).resolve() == app and admission['root'] == str(app)
            and runtime['resource_id'] == admission['resource_id'] == database_id
            and runtime['database'] == databases[0]['database']
            and runtime['endpoint_id'] == databases[0]['upstream_endpoint']['endpoint_id']
            and Path(runtime['environment']['path']).resolve() == env_file.resolve()
            and admission['gate'] == databases[0]['lease_gate'] == '059-security-runtime',
            'NATIVE_RUNTIME_DESCRIPTOR_MISMATCH')
    previous_registry = os.environ.get('PEANUT_RESOURCE_REGISTRY')
    def verify_admission():
        previous = os.environ.get('PEANUT_RESOURCE_REGISTRY')
        try:
            os.environ['PEANUT_RESOURCE_REGISTRY'] = str(resource_registry_path)
            native['verify_runtime_admission'](admission, a['owner'], a['thread'])
        finally:
            if previous is None:
                os.environ.pop('PEANUT_RESOURCE_REGISTRY', None)
            else:
                os.environ['PEANUT_RESOURCE_REGISTRY'] = previous
    try:
        # The maintainer tool verifies the native lease; PHP receives only its APP runtime environment.
        os.environ['PEANUT_RESOURCE_REGISTRY'] = str(resource_registry_path)
        native = runpy.run_path(str(tool_file))
        require(native['runtime_admission'](runtime, str(app), runtime['head']) == admission,
                'NATIVE_RUNTIME_ADMISSION_MISMATCH')
        verify_admission()
        runtime_environment = native['runtime_environment'](runtime, admission, php=php)
    finally:
        if previous_registry is None:
            os.environ.pop('PEANUT_RESOURCE_REGISTRY', None)
        else:
            os.environ['PEANUT_RESOURCE_REGISTRY'] = previous_registry
    env.update({'PATH': '/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin',
                'TMPDIR': str(tmp), **runtime_environment})
    connection = '''require $argv[1].'/server/database/environment-guard.php';$c=guardedDatabaseConfig();$d=guardedConnection($c);'''
    proof = connection + '''$server=$argv[1].'/server';$facts=\\app\\common\\services\\installation\\InstallationExecutionHost::verifiedInstallationFacts($server);$runtime=\\app\\common\\value\\installation\\ApplicationSourceIdentity::load($server);$generated=\\app\\common\\value\\installation\\ApplicationSourceIdentity::loadGeneratedApp($server);if($runtime->applicationIdentity()!==$generated->applicationIdentity()){throw new RuntimeException('APPLICATION_RUNTIME_SOURCE_MISMATCH');}$i=$d->query('SELECT DATABASE() db,@@server_uuid uuid,@@version version')->fetch(PDO::FETCH_ASSOC);$i['tables']=(int)$d->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn();$i['installation_receipt_sha256']=$facts['receipt_sha256'];$i['deployment_mode']=$facts['receipt']['deployment_mode'];echo json_encode($i,JSON_THROW_ON_ERROR);'''
    p = subprocess.run([php, '-d', 'zend.exception_ignore_args=1', '-r', proof, str(app)], cwd=app, env=env, text=True, capture_output=True, timeout=25)
    require(p.returncode == 0, 'APPLICATION_RESOURCE_PREFLIGHT_FAILED')
    state = json.loads(p.stdout)
    require(state['uuid'] == a['database_identity']['uuid']
            and state['db'] == a['database_identity']['db'] == a['resource']['database']
            and state['version'] == databases[0]['version'] == '8.4.10' and state['tables'] > 0,
            'INSTALLED_DATABASE_IDENTITY_MISMATCH')
    first_install = a['first_install']
    require(Path(first_install['path']).resolve() == app / 'server/private/installation/installed.json'
            and state['installation_receipt_sha256'] == first_install['sha256']
            and state['deployment_mode'] == a['edition'], 'NATIVE_INSTALLATION_FACTS_MISMATCH')
    def file_snapshot(file_key):
        # Only file business rows/counts are compared; authentication/denial audit may legitimately advance.
        code = connection + '''$v=[];foreach(['pa_file'=>'id','pa_file_object'=>'id','pa_file_reference'=>'id','pa_file_delivery_grant'=>'token_id_hash'] as $t=>$order){$q=$d->prepare("SELECT * FROM ".$t." WHERE file_key=? ORDER BY ".$order);$q->execute([$argv[2]]);$v[$t]=[$q->fetchAll(PDO::FETCH_ASSOC),(int)$d->query("SELECT COUNT(*) FROM ".$t)->fetchColumn()];}echo hash('sha256',json_encode($v,JSON_THROW_ON_ERROR));'''
        p = subprocess.run([php, '-d', 'zend.exception_ignore_args=1', '-r', code, str(app), file_key],
                           cwd=app, env=env, text=True, capture_output=True, timeout=25)
        require(p.returncode == 0 and re.fullmatch(r'[a-f0-9]{64}', p.stdout) is not None,
                'FILE_STATE_READ_FAILED')
        return p.stdout
    port = a['http_port']
    with socket.socket() as s:
        s.bind(('127.0.0.1', port))
    events = []
    result = {'edition': a['edition'], 'source_identity': identity,
              'manifest_sha256': a['manifest_sha256'], 'template_archive_sha256': archive['sha256'],
              'module_lock_sha256': a['module_lock_sha256'], 'database': state,
              'events': events, 'browser_tested': False, 'two_tenants_tested': False}
    observations = tmp / 'request-context-private.jsonl'
    require(not observations.exists() and not observations.is_symlink(), 'CONTEXT_OUTPUT_ALREADY_EXISTS')
    observations.touch(mode=0o600)
    observer_router = tmp / 'observe-native-router.php'
    require(not observer_router.exists() and not observer_router.is_symlink(), 'CONTEXT_ROUTER_ALREADY_EXISTS')
    native_path = base64.b64encode(str(app / 'server/public/router.php').encode()).decode()
    observer_path = base64.b64encode(str(observations).encode()).decode()
    observer_router.write_text('''<?php
$nativeRouter = base64_decode(''' + "'" + native_path + "'" + ''');
// The native router may return false and leave index.php to the PHP development server.
register_shutdown_function(static function () use ($nativeRouter): void {
    if (($_SERVER['SCRIPT_FILENAME'] ?? '') !== dirname($nativeRouter) . '/index.php') {
        return;
    }
    $http = $GLOBALS['http'] ?? null;
    if (!isset($http) || !class_exists(think\\App::class, false)) {
        throw new RuntimeException('CONTEXT_NATIVE_HTTP_NOT_OBSERVED');
    }
    $observedApp = think\\App::getInstance();
    $storeClass = app\\common\\execution\\ExecutionContextStore::class;
    if ($observedApp->http !== $http || !$observedApp->exists($storeClass)) {
        throw new RuntimeException('CONTEXT_NATIVE_STORE_NOT_RESOLVED');
    }
    // Container::make returns its cached instance; exists above forbids manufacturing an empty store.
    $observedStore = $observedApp->make($storeClass);
    $record = ['request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? '',
        'pid' => getmypid(), 'empty' => $observedStore->isEmpty(),
        'root_null' => $observedStore->rootIdentity() === null];
    if (file_put_contents(base64_decode(''' + "'" + observer_path + "'" + '''),
        json_encode($record, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('CONTEXT_OBSERVATION_WRITE_FAILED');
    }
});
return require $nativeRouter;
''')
    observer_router.chmod(0o600)
    def context_observation(request_id):
        deadline = time.monotonic() + 1
        while True:
            rows = [json.loads(line) for line in observations.read_text().splitlines() if line]
            matches = [row for row in rows if row['request_id'] == request_id]
            if matches:
                require(len(matches) == 1 and matches[0]['empty'] is True
                        and matches[0]['root_null'] is True, 'CONTEXT_NOT_RELEASED')
                return matches[0]
            require(time.monotonic() < deadline, 'CONTEXT_OBSERVATION_MISSING')
            time.sleep(0.01)
    log_path = tmp / 'php-http-private.log'
    with log_path.open('w') as log:
        log_path.chmod(0o600)
        process = subprocess.Popen([php, '-d', 'zend.exception_ignore_args=1', '-d', 'display_errors=0', '-S', '127.0.0.1:' + str(port), '-t', 'public', str(observer_router)], cwd=app / 'server', env=env, stdout=log, stderr=log)
        try:
            deadline = time.monotonic() + 15
            while True:
                require(process.poll() is None, 'PHP_HTTP_PROCESS_EXITED')
                try:
                    with socket.create_connection(('127.0.0.1', port), timeout=0.5):
                        break
                except OSError:
                    require(time.monotonic() < deadline, 'PHP_HTTP_START_TIMEOUT')
                    time.sleep(0.1)
            admin = Session(port, a['admin_host'], events)
            admin.request('GET', '/adminapi/login/info', statuses=(401, 403), label='anonymous-admin-denied')
            identity = admin.login(credentials['ADMIN_INITIAL_EMAIL'], credentials['ADMIN_INITIAL_PASSWORD'], a['tenant_code'])
            result['tenant_login_shape'] = list(identity)
            admin.data('POST', '/adminapi/user/info', {}, label='authenticated-admin-info')
            marker = 'http-' + secrets.token_hex(6)
            beta = None
            if a['edition'] == 'multi-tenant':
                b = a['beta']
                require(b['host'] != admin.host and b['tenant_code'] != a['tenant_code'], 'TWO_TENANT_INPUTS')
                beta = Session(port, b['host'], events)
                beta_credentials = private_values(Path(b['credentials_file']), php)
                beta.login(beta_credentials['ADMIN_INITIAL_EMAIL'], beta_credentials['ADMIN_INITIAL_PASSWORD'], b['tenant_code'])
                require(beta.token != admin.token, 'TENANT_SHARED_TOKEN')
                beta.request('GET', '/adminapi/login/info', token=admin.token,
                             statuses=(401, 403), label='alpha-token-on-beta-denied')
                admin.request('GET', '/adminapi/login/info', token=beta.token,
                              statuses=(401, 403), label='beta-token-on-alpha-denied')
                admin.data('GET', '/adminapi/login/info', label='alpha-session-still-valid')
                beta.data('GET', '/adminapi/login/info', label='beta-session-still-valid')
            result['business'] = file_chain(admin, beta, marker, file_snapshot, identity['context']['tenant_id'])
            result['two_tenants_tested'] = beta is not None
            alpha_info = admin.data('GET', '/adminapi/login/info', label='context-owner-identity')
            invalid = admin.request('POST', '/adminapi/api/v1/files/resolve',
                                    {'file_key': 'file_' + '0' * 32, 'operation': 'invalid'},
                                    statuses=(400,), label='context-production-file-exception')
            require(invalid.get('data', {}).get('error_code') == 'FILE_ACCESS_INPUT_INVALID',
                    'CONTEXT_EXPECTED_PRODUCTION_EXCEPTION')
            after_exception = context_observation(admin.last_request_id)
            next_actor = beta
            if next_actor is None:
                n = a['context_next']
                next_actor = Session(port, n['host'], events)
                next_credentials = private_values(Path(n['credentials_file']), php)
                next_actor.login(next_credentials['ADMIN_INITIAL_EMAIL'],
                                 next_credentials['ADMIN_INITIAL_PASSWORD'], n['tenant_code'])
            next_info = next_actor.data('GET', '/adminapi/login/info', label='context-next-identity')
            require(next_info['id'] != alpha_info['id'], 'CONTEXT_NEXT_IDENTITY_NOT_DISTINCT')
            after_next = context_observation(next_actor.last_request_id)
            require(after_exception['pid'] == after_next['pid'] == process.pid,
                    'CONTEXT_HTTP_WORKER_CHANGED')
            result['context'] = {'scope': 'request-return cleanup and next identity in same OS worker',
                                 'persistent_app_reentry_tested': False,
                                 'after_exception': after_exception, 'after_next_identity': after_next,
                                 'next_identity_distinct': True}
            if next_actor is not beta:
                next_actor.request('POST', '/adminapi/tenant/session/logout', {},
                                   statuses=(200, 204), label='context-next-logout')
            if beta:
                beta.request('POST', '/adminapi/tenant/session/logout', {}, statuses=(200, 204), label='beta-logout')
            before = admin.token
            refreshed = admin.data('POST', '/adminapi/tenant/session/refresh', {}, label='tenant-session-refresh')
            require(isinstance(refreshed, dict) and refreshed.get('access_token'), 'REFRESH_TOKEN_MISSING')
            admin.token = refreshed['access_token']
            require(admin.token != before, 'REFRESH_DID_NOT_ROTATE')
            admin.request('POST', '/adminapi/user/info', {}, token=before, statuses=(401, 403), label='old-access-after-refresh-denied')
            admin.request('POST', '/adminapi/user/info', {}, label='new-access-after-refresh')
            old = admin.token
            admin.request('POST', '/adminapi/tenant/session/logout', {}, statuses=(200, 204), label='tenant-logout')
            admin.request('POST', '/adminapi/user/info', {}, token=old, statuses=(401, 403), label='old-access-after-logout-denied')
            admin.request('POST', '/adminapi/tenant/session/refresh', {}, statuses=(401, 403), label='old-refresh-after-logout-denied')
            verify_admission()
            result['status'] = 'passed'
        except Exception as exc:
            result.update({'status': 'failed', 'error': str(exc) if isinstance(exc, VerificationFailure) else type(exc).__name__})
        finally:
            process.terminate()
            try:
                process.wait(timeout=8)
            except subprocess.TimeoutExpired:
                process.kill(); process.wait(timeout=3)
            result['owned_http_process_stopped'] = process.poll() is not None
    (output / (a['edition'] + '-http-result.json')).write_text(json.dumps(result, ensure_ascii=False, indent=2) + '\n')
    print(json.dumps({k: v for k, v in result.items() if k != 'events'}, ensure_ascii=False), flush=True)
    return result

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--applications', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    require('/.local/tmp/' in str(args.output.resolve()), 'OWNED_TMP_OUTPUT_REQUIRED')
    args.output.mkdir(mode=0o700, parents=True, exist_ok=True)
    results = [run_application(a, args.output) for a in json.loads(args.applications.read_text())]
    raise SystemExit(0 if all(r['status'] == 'passed' for r in results) else 1)
