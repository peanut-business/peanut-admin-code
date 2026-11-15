<?php
declare(strict_types=1);

// Verify historical paired receipts with the original read-only contract, using an isolated synthetic instance.
$root = dirname(__DIR__, 3);
$fixture = <<<'PY'
import gzip, hashlib, hmac, json, os, runpy, tarfile, tempfile
from pathlib import Path
stage = runpy.run_path('scripts/paired_recovery_verifier.py')
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

with tempfile.TemporaryDirectory(prefix='peanut-paired-registered-') as temporary:
    root=Path(temporary).resolve(); server=root/'application/server'; w=root/'workspace'; rec=w/'recovery'; rc=w/'root-client'
    key=b'b'*64
    write(server/'private/maintenance/update-verification.key',key+b'\n');(server/'private/maintenance').chmod(0o700)
    def signed(path,record):
        write(path,record);write(path.with_name(path.name+'.hmac'),hmac.new(key,path.read_bytes(),hashlib.sha256).hexdigest().encode())
    snapshots=dict.fromkeys(native['SNAPSHOT_NAMES'],None)
    for name in native['SNAPSHOT_NAMES']:
        (rec/name).parent.mkdir(mode=0o700,parents=True,exist_ok=True)
    rel_id={'application':{'slug':'fixture-app'}}
    write(server/'.peanut/release-identity.json',rel_id);(server/'.peanut/release-identity.json').chmod(0o644)
    endpoint={'endpoint_id':'fixture-endpoint','host':'host.docker.internal','port':20189,'consumers':['container']}
    reg={'schema_version':1,'authority':{'role':'application'},'project_id':'fixture-app','resources':{'databases':[{
        'stable_resource_id':'fixture-db','service_type':'mysql','fallback':'none','application_runtime':True,
        'database':'fixture','namespace':'fixture','container_endpoint':endpoint,
    }]}}
    reg_bytes=json.dumps(reg,sort_keys=True).encode()
    env_body=b'\n'.join([
        b'PEANUT_DATABASE_SOURCE=registered',
        b'PEANUT_DATABASE_RESOURCE_ID=fixture-db',
        b'PEANUT_DATABASE_CONSUMER=container',
        b'PEANUT_DATABASE_ENDPOINT_ID=fixture-endpoint',
        b'DB_HOST=host.docker.internal',
        b'DB_PORT=20189',
        b'DB_NAME=fixture',
    ])+b'\n'
    for name,body in {'.env':env_body,'private/resources/project-resources.json':reg_bytes,'private/installation/installed.json':b'{"state":"installed"}','private/installation/baseline.json':b'{}'}.items():
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
    raw_sql_sha=hashlib.sha256(sql).hexdigest()
    app={'root':str(server.parent),'database':'fixture','resource_id':'fixture-db','endpoint_id':'fixture-endpoint'}
    identity={k:app[k] for k in ('database','resource_id','endpoint_id')}
    rel_sha=digest(server/'.peanut/release-identity.json')
    plan={'protocol':'peanut.server-release-inputs.v1','update_id':'fixture-reg-op',
        'source':{'identity_path':'server/.peanut/release-identity.json','identity_sha256':rel_sha,
            **{k:snapshots['private/installation/'+name] for k,name in
                [('installed_receipt_sha256','installed.json'),('baseline_sha256','baseline.json'),('deployment_state_sha256','deployment.json')]}},
        'target':{'identity_sha256':rel_sha}}
    write(w/'plan.json',plan)
    tool_spec={
        'transport':'ssh-docker-exec','ssh_alias':'mac-14','container_name':'mysql84',
        'ssh_command':'/usr/bin/ssh','docker_command':'/usr/local/bin/docker','mysql_command':'/usr/bin/mysql',
        'mysqldump_command':'/usr/bin/mysqldump','timeout_command':'/usr/bin/timeout','digest_command':'/usr/bin/sha256sum',
        'source_schema_backup':{'whole_schema':True,'database_resource_id':'fixture-db','database':'fixture',
            'mysql_version':'8.4.10','server_uuid':'12345678-1234-1234-1234-123456789abc','timeout_seconds':120},
    }
    tool_path=w/'backup-tool.json';write(tool_path,tool_spec)
    tool_meta={'path':str(tool_path),'sha256':digest(tool_path),'owner_uid':os.getuid(),'mode':0o600}
    prod={'protocol':'peanut.product-upgrade-plan.v2','workspace':str(w),'instance_root':str(server.parent),
        'candidate':plan['update_id'],'inputs_sha256':digest(w/'plan.json'),'backup_tool':tool_meta,'state_path':str(w/'state.json')}
    prod['plan_sha256']=canonical(prod);write(w/'product-plan.json',prod)
    state={'protocol':'peanut.product-upgrade-state.v1','candidate':prod['candidate'],'plan_sha256':prod['plan_sha256']}
    state['state_sha256']=canonical(state);write(w/'state.json',state)
    write(w/'product-binding.json',{'plan_path':str(w/'product-plan.json'),'plan_sha256':prod['plan_sha256']})
    binding={'source':'registered','resource_id':'fixture-db','endpoint':endpoint,'database':'fixture',
        'registry_sha256':digest(server/'private/resources/project-resources.json'),'environment_sha256':digest(server/'.env')}
    expected_provider={'source':'registered','database_binding':binding,'backup_tool':tool_meta}
    def make_proc(tag, op, stdout_path=None, stdout_sha=None):
        err_p=rc/f'{tag}.err';write(err_p,b'')
        if stdout_path is None:
            out_p=rc/f'{tag}.out';write(out_p,b'{}')
            out_ref={'path':str(out_p),'sha256':digest(out_p)}
        else:
            out_ref={'path':str(stdout_path),'sha256':stdout_sha}
        proc_p=rc/f'{tag}.json'
        write(proc_p,{'operation':op,'provider':expected_provider,'product_plan_sha256':prod['plan_sha256'],
            'status':'terminal','rc':0,'remote_success_terminal_confirmed':True,'stdout':out_ref,'stderr':{'path':str(err_p),'sha256':digest(err_p)}})
        return {'path':str(proc_p),'sha256':digest(proc_p)}
    meta_proc_1=make_proc('meta1','metadata');meta_proc_2=make_proc('meta2','metadata')
    cap_proc=make_proc('cap','capture',rec/'database.sql',raw_sql_sha)
    base_meta={'identity':{'server_uuid':'12345678-1234-1234-1234-123456789abc','database':'fixture',
        'version':'8.4.10','current_user':'root@localhost','partial_revokes':0,
        'charset':'utf8mb4','collation':'utf8mb4_0900_ai_ci','table_count':1},
        'metadata':{'tables':[{'TABLE_NAME':'items','TABLE_TYPE':'BASE TABLE','ENGINE':'InnoDB','TABLE_COLLATION':'utf8mb4_0900_ai_ci'}],
            'tablespaces':[{'NAME':'fixture/items','SPACE_TYPE':'Single'}],
            'views':[],'routines':[],'events':[],'triggers':[],
            'foreign_keys':[{'TABLE_SCHEMA':'fixture','TABLE_NAME':'items','CONSTRAINT_NAME':'parent_fk',
                'COLUMN_NAME':'parent_id','ORDINAL_POSITION':1,'REFERENCED_TABLE_SCHEMA':'fixture',
                'REFERENCED_TABLE_NAME':'items','REFERENCED_COLUMN_NAME':'id'}]},
        'mysql_sha256':'a'*64,'mysqldump_sha256':'b'*64}
    write(rec/'database-metadata.json',{**base_meta,'process':meta_proc_1})
    write(rec/'database-metadata-after.json',{**base_meta,'process':meta_proc_2})
    mysql={'server_uuid':'12345678-1234-1234-1234-123456789abc','database':'fixture'}
    backup={'protocol':'peanut.server-update-backup.v1','update_id':plan['update_id'],'plan_sha256':digest(w/'plan.json'),
        'product_plan_sha256':prod['plan_sha256'],'database_provider':expected_provider,'database_identity':identity,
        'registered_evidence':{'metadata_sha256':digest(rec/'database-metadata.json'),
            'metadata_after_sha256':digest(rec/'database-metadata-after.json'),'capture':cap_proc,'raw_dump_sha256':raw_sql_sha},
        'storage':storage,'snapshots':snapshots,'database_dump_sha256':digest(dump),'mysql_identity':mysql}
    signed(w/'backup.json',backup)
    receipt=w/'database-recovery.json'
    saved={'status':'completed','update_id':plan['update_id'],'backup_sha256':digest(w/'backup.json'),'mysql_identity':mysql}
    signed(receipt,saved)
    def check_reg():
        return stage['native_evidence']({'path':str(receipt),'sha256':digest(receipt)},app,'recovery')
    assert check_reg()['native_producer_sha256']==verifier_sha
    def set_metadata(metadata):
        write(rec/'database-metadata.json',{**base_meta,'metadata':metadata,'process':meta_proc_1})
        write(rec/'database-metadata-after.json',{**base_meta,'metadata':metadata,'process':meta_proc_2})
        backup['registered_evidence']['metadata_sha256']=digest(rec/'database-metadata.json')
        backup['registered_evidence']['metadata_after_sha256']=digest(rec/'database-metadata-after.json')
        signed(w/'backup.json',backup)
        saved['backup_sha256']=digest(w/'backup.json');signed(receipt,saved)
    for section, rows, expected in (
        ('views',[{'TABLE_NAME':'item_view'}],'unsupported non-table objects'),
        ('foreign_keys',[{**base_meta['metadata']['foreign_keys'][0],'REFERENCED_TABLE_SCHEMA':'foreign'}],'cross-schema foreign keys'),
        ('foreign_keys',[{**base_meta['metadata']['foreign_keys'][0],'TABLE_SCHEMA':'foreign'}],'cross-schema foreign keys'),
        ('tables',[{**base_meta['metadata']['tables'][0],'ENGINE':'MyISAM'}],'non-InnoDB or non-base-table objects'),
        ('tablespaces',[{'NAME':'fixture/items','SPACE_TYPE':'General'}],'non-file-per-table objects'),
    ):
        set_metadata({**base_meta['metadata'],section:rows})
        try:
            check_reg()
        except ValueError as exc:
            assert expected in str(exc),str(exc)
        else:
            raise AssertionError('accepted unsupported registered metadata: '+section)
    set_metadata(base_meta['metadata'])
    assert check_reg()['native_producer_sha256']==verifier_sha
    print('REGISTERED-SCHEMA passed: producer-shaped positive; view, outbound/inbound foreign key, MyISAM and shared tablespace rejected')
    write(tool_path,b'{"tampered":true}')
    try:
        check_reg()
    except (ValueError,stage['GateError']):
        pass
    else:
        raise AssertionError('accepted tampered registered backup_tool')
print('SERVER-UPDATE-GATE passed: authentic paired plan, HMAC, SQL archive and installed afterimage; no live restore')
PY;
$process = proc_open(['python3', '-c', $fixture], [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, $root);
if (!is_resource($process) || proc_close($process) !== 0) {
    throw new RuntimeException('Paired recovery evidence contract failed');
}
