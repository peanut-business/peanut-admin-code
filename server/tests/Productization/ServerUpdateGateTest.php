<?php
declare(strict_types=1);

// Verify historical paired receipts with the original read-only contract, using an isolated synthetic instance.
$root = dirname(__DIR__, 3);
$fixture = <<<'PY'
import gzip, hashlib, hmac, json, os, runpy, tarfile, tempfile
from pathlib import Path
stage = runpy.run_path('scripts/p0e-runtime-qualification')
native, verifier_sha = stage['paired_recovery_verifier']()
def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()
def write(path, body):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(body if isinstance(body,bytes) else json.dumps(body,sort_keys=True).encode())
    path.chmod(0o600)
def canonical(value):
    return 'sha256:'+hashlib.sha256(json.dumps(value,sort_keys=True,ensure_ascii=False,separators=(',',':')).encode()).hexdigest()
with tempfile.TemporaryDirectory(prefix='peanut-paired-evidence-') as temporary:
    root=Path(temporary).resolve(); server=root/'application/server'; w=root/'workspace'; rec=w/'recovery'
    key=b'a'*64
    write(server/'private/maintenance/update-verification.key',key+b'\n');(server/'private/maintenance').chmod(0o700)
    def signed(path,record):
        write(path,record);write(path.with_name(path.name+'.hmac'),hmac.new(key,path.read_bytes(),hashlib.sha256).hexdigest().encode())
    snapshots={}
    for name in native['SNAPSHOT_NAMES']:
        (rec/name).parent.mkdir(mode=0o700,parents=True,exist_ok=True)
        snapshots[name]=None
    for name,body in {'.env':b'PEANUT_DATABASE_SOURCE=bundled\n','private/installation/installed.json':b'{"state":"installed"}','private/installation/baseline.json':b'{}'}.items():
        write(server/name,body);write(rec/name,body);snapshots[name]=digest(rec/name)
    storage=dict.fromkeys(native['STORAGE_NAMES'])
    storage_path=rec/'private-installation.tar.gz'
    with tarfile.open(storage_path,'w:gz') as tar:
        for p in sorted((server/'private/installation').iterdir()):
            tar.add(p,arcname=p.name)
    storage_path.chmod(0o600)
    storage['private/installation']={'filename':storage_path.name,'sha256':digest(storage_path),'inventory':native['storage_inventory'](server/'private/installation')}
    sql=b'DROP DATABASE IF EXISTS `fixture`;\nCREATE DATABASE `fixture`;\nUSE `fixture`;\n'
    dump=rec/'database.sql.gz';write(dump,gzip.compress(sql))
    app={'root':str(server.parent),'database':'fixture','resource_id':'fixture-db','endpoint_id':'fixture-endpoint'}
    identity={k:app[k] for k in ('database','resource_id','endpoint_id')}
    plan={'update_id':'fixture-operation','source':{k:snapshots['private/installation/'+name] for k,name in
        [('installed_receipt_sha256','installed.json'),('baseline_sha256','baseline.json'),('deployment_state_sha256','deployment.json')]}}
    write(w/'plan.json',plan)
    prod={'protocol':'peanut.product-upgrade-plan.v2','workspace':str(w),'candidate':plan['update_id'],'state_path':str(w/'state.json')}
    prod['plan_sha256']=canonical(prod);write(w/'product-plan.json',prod)
    state={'protocol':'peanut.product-upgrade-state.v1','candidate':prod['candidate'],'plan_sha256':prod['plan_sha256']}
    state['state_sha256']=canonical(state);write(w/'state.json',state)
    write(w/'product-binding.json',{'plan_path':str(w/'product-plan.json'),'plan_sha256':prod['plan_sha256']})
    mysql={'server_uuid':'12345678-1234-1234-1234-123456789abc','database':'fixture'}
    backup={'protocol':'peanut.server-update-backup.v1','update_id':plan['update_id'],'plan_sha256':digest(w/'plan.json'),
        'product_plan_sha256':prod['plan_sha256'],'database_provider':{'source':'bundled'},'database_identity':identity,
        'storage':storage,'snapshots':snapshots,'database_dump_sha256':digest(dump),'mysql_identity':mysql}
    signed(w/'backup.json',backup)
    receipt=w/'database-recovery.json'
    saved={'status':'completed','update_id':plan['update_id'],'backup_sha256':digest(w/'backup.json'),'mysql_identity':mysql}
    signed(receipt,saved)
    def check():
        return stage['native_evidence']({'path':str(receipt),'sha256':digest(receipt)},app,'recovery')
    assert check()['native_producer_sha256']==verifier_sha
    def deny(label):
        try:check()
        except (ValueError,stage['GateError']):return
        raise AssertionError('accepted '+label)
    write(receipt.with_name(receipt.name+'.hmac'),b'0'*64);deny('forged recovery receipt')
    signed(receipt,saved)
    write(w/'backup.json.hmac',b'0'*64);deny('forged backup record')
    signed(w/'backup.json',backup)
    original=dump.read_bytes();write(dump,gzip.compress(b'USE `foreign`;'));deny('modified database archive')
    write(dump,original);write(server/'private/installation/installed.json',b'tampered');deny('modified installed afterimage')
    assert stage['read_json'](w/'product-plan.json')['plan_sha256']==prod['plan_sha256']
print('SERVER-UPDATE-GATE passed: authentic paired plan, HMAC, SQL archive and installed afterimage; no live restore')
PY;
$process = proc_open(['python3', '-c', $fixture], [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, $root);
if (!is_resource($process) || proc_close($process) !== 0) {
    throw new RuntimeException('Paired recovery evidence contract failed');
}
