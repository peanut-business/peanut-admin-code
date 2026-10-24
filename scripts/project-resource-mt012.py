#!/usr/bin/env python3
"""One registered MT .12 partial-first-install window; no caller command or SQL.

The existing lease owns exclusion. Its native in-use marker prevents release and
pruning across the one SSH child. Every remote authority request is checked live
against that lease, the current Project authority, fixed source and exact scope.
This is cooperative maintenance admission, not an SQL sandbox or an assertion
that an administrator outside the native protocol can never write the server.
"""
import argparse
import base64
import hashlib
import json
import os
from pathlib import Path
import queue
import re
import runpy
import shlex
import subprocess
import sys
import threading
import time

ROOT = Path(__file__).resolve().parent.parent
BASE = runpy.run_path(str(ROOT / 'scripts/project-resource-application.py'))
need, regular, canonical, sha = (BASE[k] for k in ('need','regular','canonical','sha'))
PROTOCOL = 'peanut.mt012-partial-firstinstall-operation.v1'
OPERATION = 'mt012-firstinstall-01a16eb4-20261025'
B_SHA = 'e2f262995e91c9cc930e0ff9c1d69e874e87167b4d13ede05e7903e937c91935'
STATE = 'application-operation.json'
FILES = {'scripts/project-resource-lease','scripts/project-resource-application.py',
         'scripts/project-resource-registry','scripts/project-resource-mt012.py'}


def load_operation():
    registry, project, resolver, registry_sha = BASE['project_authority']()
    doc = BASE['json_unique'](regular(registry))
    matches = [x for x in doc['resources']['application_operations'] if x.get('stable_resource_id') == OPERATION]
    need(len(matches) == 1, 'exact MT012 operation not registered; submit fixed actor/source/closure request')
    op = matches[0]
    need(set(op) == {'stable_resource_id','protocol','owner','thread','max_seconds','execution_authorized',
                     'tool_source','target','closure','package','approval_path','receipt_root','terminal_review'},
         'MT012 closed operation fields differ')
    need(op['protocol'] == PROTOCOL and op['owner'] == op['thread']
         and re.fullmatch('[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}',op['owner'])
         and type(op['max_seconds']) is int and 60 <= op['max_seconds'] <= 3600,
         'MT012 actor/window invalid')
    tool = op['tool_source']
    common = canonical(BASE['git'](ROOT,'rev-parse','--path-format=absolute','--git-common-dir').decode().strip())
    need(tool['root'] == str(ROOT) and tool['common_dir'] == str(common)
         and common.parent.name == 'peanut-admin-code' and common.name == '.git'
         and BASE['git'](ROOT,'rev-parse','HEAD').decode().strip() == tool['commit']
         and not BASE['git'](ROOT,'status','--porcelain=v1','--untracked-files=all'),
         'MT012 fixed management source/common-dir changed or dirty')
    need(set(tool['files']) == FILES, 'MT012 native source closure differs')
    for name,digest in tool['files'].items():
        raw = regular(ROOT/name)
        need(sha(raw) == digest and BASE['git'](ROOT,'show',tool['commit']+':'+name) == raw,
             'MT012 committed native tool bytes changed')
    target = op['target']
    need(target == {'host':'oracle3','root':'/www/docker/peanut-app-multi','database':'peanut_app_multi',
                    'uuid':'5d2176c9-c200-11f1-b1f9-427095895d27','compose':'peanut-multi-source',
                    'port':18093,'database_resource':'peanut-app-multi-bundled-mysql84',
                    'application_resource':'peanut-app-multi-application','listener_resource':'peanut-app-multi-http'},
         'MT012 target differs from the exact reviewed operation')
    for kind, resource_id in [('databases',target['database_resource']),('containers',target['application_resource']),
                              ('local_listeners',target['listener_resource'])]:
        rows = [x for x in doc['resources'][kind] if x.get('stable_resource_id') == resource_id]
        need(len(rows) == 1 and rows[0]['host'] == target['host'], 'MT012 original resource identity differs')
        row = rows[0]
        if kind == 'databases':
            need(row['database'] == target['database'] and row['namespace'] == target['root']+'/server/docker/mysql'
                 and row['current_identity_observation']['server_uuid'] == target['uuid'], 'MT012 DB binding differs')
        elif kind == 'containers': need(row['namespace'] == target['root'], 'MT012 original runtime root differs')
        else: need(row['port'] == target['port'] and row['namespace'] == target['compose'], 'MT012 listener differs')
    closure = op['closure']
    need(set(closure) == {'plan','backup','managed','guided','remote'}, 'MT012 recipe closure incomplete')
    for entry in closure.values():
        need(set(entry) == {'path','sha256'}, 'MT012 closure entry differs')
        need(sha(regular(canonical(entry['path']))) == entry['sha256'], 'MT012 fixed recipe/plan changed')
    package = op['package']
    need(set(package) == {'path','sha256'} and package['sha256'] == B_SHA
         and sha(regular(canonical(package['path']))) == B_SHA, 'MT012 exact B changed')
    for key in ['approval_path','receipt_root','terminal_review']:
        path = canonical(op[key]); need(path.parent.is_dir() and ROOT not in path.parents,
                                       'MT012 private runtime input/output overlaps source')
    need(type(op['execution_authorized']) is bool, 'MT012 execution authorization must be explicit')
    return op, common, registry_sha


def resources(op, registry_sha):
    t = op['target']
    # Same original production aliases as the historical native admission; no
    # alternate DB ID, host spelling or fabricated schema-sharing declaration.
    return {'gate':{'product-server-first-install'},'worktree':{str(ROOT)},
            'workflow-session':{op['thread']},'mt012-operation':{OPERATION},
            'mt012-operation-sha256':{sha(json.dumps(op,sort_keys=True,separators=(',',':')).encode())},
            'registry-sha256':{registry_sha},'resource-id':{t['database_resource'],t['application_resource'],t['listener_resource']},
            'compose-project':{t['compose']},'docker-container':{t['compose']+'-'+n+'-1' for n in ['php','nginx','mysql']},
            'mysql-resource':{t['database_resource']},'database':{t['database']},'mysql-db':{t['database']},
            'deployment-root':{t['host']+':'+t['root']},'environment':{'production'},'host':{t['host']},
            'port':{str(t['port'])},'output-dir':{op['receipt_root']}}


def check(lease_id, owner, *, running=False, allow_expired=False):
    op, common, digest = load_operation()
    need(owner == op['owner'] == os.environ.get('CODEX_THREAD_ID'), 'MT012 requires actual current Codex owner/thread')
    need(re.fullmatch('[A-Za-z0-9][A-Za-z0-9._-]{0,63}',lease_id), 'invalid MT012 lease ID')
    directory = common/'peanut-admin-resource-leases/leases'/lease_id
    meta = dict(BASE['table'](directory/'metadata.tsv',2))
    actual = BASE['resource_map'](BASE['table'](directory/'resources.tsv',3))
    need(actual == resources(op,digest), 'MT012 complete native resource closure changed')
    need(meta['lease'] == lease_id and meta['owner'] == meta['thread'] == owner and meta['status'] == 'ACTIVE'
         and meta['candidate'] == op['tool_source']['commit'] and meta['candidate_repository'] == meta['worktree'] == str(ROOT)
         and not meta['parent_lease'] and meta['gate'] == 'product-server-first-install', 'MT012 native owner/source scope differs')
    need(int(meta['created_at']) <= time.time() and (allow_expired or time.time() < int(meta['expires_at'])),
         'MT012 native lease expired')
    # Existing show --verify-locks verifies real physical holder files under its
    # own short native mutex; observations never serve as a permission receipt.
    if not allow_expired: BASE['verify_native'](lease_id)
    state = BASE['live_state'](directory)
    if running:
        need(state and state['phase'] == 'mt012' and state['status'] == 'RUNNING'
             and state['owner'] == owner and state['lease'] == lease_id
             and state['pid'] == os.getpid() and BASE['original_process_alive'](state['pid'],state['process_birth'])
             and state['lease_created_at'] == meta['created_at']
             and state['scope_sha256'] == next(iter(actual['mt012-operation-sha256']))
             and time.time() < state['binding_expires_at'], 'MT012 held native operation window lost')
        if state.get('child_pid') is not None:
            need(BASE['original_process_alive'](state['child_pid'],state.get('child_birth')),
                 'MT012 actual SSH child identity changed')
    return op, common, directory, meta, state


def durable(path,value):
    BASE['save'](path,value)
    fd = os.open(path.parent,os.O_RDONLY)
    try: os.fsync(fd)
    finally: os.close(fd)


def release_check(lease_id, owner):
    op, common, directory, meta, state = check(lease_id,owner,allow_expired=True)
    need(state and state['phase'] == 'mt012' and state['owner'] == owner
         and not BASE['original_process_alive'](state['pid'],state['process_birth'])
         and state['child_pid'] is None and state['actual_process_terminal'] is True,
         'MT012 real coordinator/SSH window is not terminal')
    if state['status'] != 'COMPLETED':
        # UNKNOWN is not auto-release or retry permission. Reuse the existing
        # independent versioned terminal-review convention for this exact state.
        review = canonical(op['terminal_review']); raw = regular(review,private=True)
        proof = BASE['json_unique'](raw); project = BASE['project_authority']()[1]
        need(project in review.parents and BASE['git'](project,'show','HEAD:'+str(review.relative_to(project))) == raw,
             'MT012 UNKNOWN terminal review must be versioned by the lawful Project writer')
        need(proof.get('lease') == lease_id and proof.get('state_sha256') == sha(regular(directory/STATE,private=True))
             and proof.get('verdict') == 'SAFE_TO_RELEASE' and proof.get('actual_remote_terminal') is True
             and proof.get('reviewer_thread') not in (None,'',owner), 'MT012 exact remote terminal reconciliation absent')
    receipt = canonical(op['receipt_root'])/(lease_id+'-terminal.json')
    need(not receipt.exists() and not receipt.is_symlink(), 'MT012 terminal receipt already exists')
    durable(receipt,state)
    (directory/STATE).unlink()


def run(lease_id,owner):
    os.umask(0o077)
    op, common, directory, meta, previous = check(lease_id,owner)
    need(op['execution_authorized'] is True, 'MT012 production activation not granted by current registered operation')
    need(sys.flags.optimize == 0, 'optimized interpreter forbidden')
    receipt = canonical(op['receipt_root']); receipt.mkdir(mode=0o700,exist_ok=True)
    need(receipt.stat().st_uid == os.getuid() and (receipt.stat().st_mode & 0o777) == 0o700,
         'MT012 private receipt root required')
    with BASE['mutex'](common):
        # The existing release/prune paths consult this same in-use marker.
        need(not (directory/STATE).exists() and not (directory/STATE).is_symlink(),
             'MT012 already started; reconcile original handle, never redispatch')
        need(dict(BASE['table'](directory/'metadata.tsv',2)) == meta,
             'MT012 lease changed between native verification and window entry')
        state = dict(protocol='peanut.application-operation-state.v1',phase='mt012',lease=lease_id,owner=owner,
                     pid=os.getpid(),process_birth=BASE['process_birth'](os.getpid()),child_pid=None,
                     status='RUNNING',actual_process_terminal=False,started_at=time.time(),
                     lease_created_at=meta['created_at'],
                     scope_sha256=next(iter(resources(op,sha(regular(canonical(os.environ['PEANUT_RESOURCE_REGISTRY']))))['mt012-operation-sha256'])),
                     binding_expires_at=min(int(meta['expires_at']),time.time()+op['max_seconds']))
        durable(directory/STATE,state)
    child = None
    try:
        check(lease_id,owner,running=True)
        bundle = {k:base64.b64encode(regular(canonical(v['path']))).decode() for k,v in op['closure'].items() if k != 'plan'}
        bundle.update(plan_sha256=op['closure']['plan']['sha256'],package=base64.b64encode(regular(canonical(op['package']['path']))).decode(),
                      owner=owner,lease=lease_id,scope_sha256=state['scope_sha256'],expires_at=state['binding_expires_at'])
        # The only remote executable is a pinned stdlib bootstrap, not a caller
        # command. One SSH process owns the entire remote flock lifetime.
        bootstrap = "import sys,json,base64; v=json.loads(sys.stdin.buffer.readline()); exec(compile(base64.b64decode(v.pop('remote')),'mt012-remote','exec'),{'__name__':'peanut.mt012.transport','bundle':v})"
        remote = shlex.join(['sudo','-n','/usr/bin/python3','-B','-c',bootstrap])
        env = {k:v for k,v in os.environ.items() if k in ('HOME','USER','LOGNAME','LANG','LC_ALL','TZ')}
        env['PATH']='/usr/bin:/bin:/usr/sbin:/sbin'
        child = subprocess.Popen(['/usr/bin/ssh','-o','BatchMode=yes','-o','StrictHostKeyChecking=yes',
                                  '-o','ConnectTimeout=5','-o','ServerAliveInterval=5','-o','ServerAliveCountMax=3',
                                  op['target']['host'],remote],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,env=env)
        state.update(child_pid=child.pid,child_birth=BASE['process_birth'](child.pid));durable(directory/STATE,state)
        child.stdin.write(json.dumps(bundle,separators=(',',':')).encode()+b'\n');child.stdin.flush()
        events = queue.Queue()
        def reader(name,stream):
            while True:
                data = stream.readline(2*1024*1024+1)
                events.put((name,data))
                if not data: break
        for name,stream in [('out',child.stdout),('err',child.stderr)]:
            threading.Thread(target=reader,args=(name,stream),daemon=True).start()
        ended=set(); failure=None; expected_approval=None
        rawlog = receipt/(lease_id+'-transport.jsonl')
        with rawlog.open('xb') as log:
            while len(ended)<2 or child.poll() is None:
                try:
                    # Never renew a released/expired lease or re-acquire a gap.
                    if child.poll() is None:
                        current,_,_,_,_=check(lease_id,owner,running=True)
                        need(current == op,'MT012 operation changed during SSH child')
                except BaseException as error:
                    failure=type(error).__name__;state['status']='UNKNOWN';durable(directory/STATE,state)
                    if not child.stdin.closed: child.stdin.close()
                try: name,line=events.get(timeout=1)
                except queue.Empty:
                    continue
                if not line: ended.add(name);continue
                need(len(line)<=2*1024*1024,'MT012 transport line exceeds bounded contract')
                log.write(json.dumps({'stream':name,'base64':base64.b64encode(line).decode()}).encode()+b'\n');log.flush()
                if name!='out' or not line.startswith(b'MT012 '):continue
                event=BASE['json_unique'](line[6:])
                if failure is not None:continue
                if event['kind']=='authority':
                    if child.poll() is not None:continue
                    _,_,_,live_meta,_=check(lease_id,owner,running=True)
                    need(re.fullmatch('[0-9a-f]{64}',event['nonce']),'invalid MT012 authority nonce')
                    valid_for=min(5.0,int(live_meta['expires_at'])-time.time(),state['binding_expires_at']-time.time())
                    need(valid_for>0,'MT012 permission expired before reply')
                    child.stdin.write(json.dumps(dict(kind='permit',nonce=event['nonce'],lease=lease_id,owner=owner,
                                                    scope_sha256=state['scope_sha256'],expires_at=state['binding_expires_at'],valid_for=valid_for)).encode()+b'\n');child.stdin.flush()
                elif event['kind']=='approval':
                    # A fresh observation approval is a separate state acceptance,
                    # never authority. It is accepted only in this live native run.
                    expected_approval=event
                    need(event['phase'] in ('before-quiesce','quiesced-binding','post-native-start'),
                         'MT012 unknown approval phase')
                    durable(receipt/(lease_id+'-'+event['phase']+'.json'),event)
                    while True:
                        need(child.poll() is None,'MT012 SSH ended before fresh observation acceptance')
                        check(lease_id,owner,running=True)
                        approval=Path(op['approval_path'])
                        if approval.exists():
                            accepted=BASE['json_unique'](regular(approval,private=True))
                            if accepted.get('nonce')==event['nonce']:break
                        time.sleep(1)
                    need(accepted == dict(event,owner=owner,lease=lease_id,scope_sha256=state['scope_sha256'],accepted=True),
                         'MT012 approval is not exact lawful current observation acceptance')
                    child.stdin.write(json.dumps(accepted).encode()+b'\n');child.stdin.flush();expected_approval=None
                elif event['kind']=='complete':
                    state['remote_complete']=event
            os.fsync(log.fileno())
        code=child.wait()
        state.update(child_pid=None,child_exit_code=code,actual_process_terminal=True)
        durable(directory/STATE,state)
        need(failure is None and code==0 and state.get('remote_complete',{}).get('scope_sha256')==state['scope_sha256'],
             'MT012 remote FAILED/UNKNOWN; retain exact state and do not retry')
        check(lease_id,owner,running=True)
        state['status']='COMPLETED';durable(directory/STATE,state)
    except BaseException as error:
        state.update(status='UNKNOWN',failure=type(error).__name__,actual_process_terminal=child is None or child.poll() is not None)
        if child is not None and child.poll() is not None:state.update(child_pid=None,child_exit_code=child.returncode)
        durable(directory/STATE,state)
        # No kill, no release and no redispatch. Native marker retains exclusion
        # until the real SSH/remote outcome has independent terminal reconciliation.
        if child is not None and not child.stdin.closed:child.stdin.close()
        raise


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action',choices=['resolve','run']);parser.add_argument('--lease');parser.add_argument('--owner',required=True)
    args=parser.parse_args()
    if args.action=='resolve':
        op,_,digest=load_operation();need(args.owner==op['owner'],'MT012 resolve actor differs')
        print(json.dumps({'candidate':op['tool_source']['commit'],'candidate_repository':str(ROOT),
                          'resources':{k:sorted(v) for k,v in resources(op,digest).items()}}))
    else:run(args.lease,args.owner)


if __name__=='__main__': main()
