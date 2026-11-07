<?php
declare(strict_types=1);

// Actual product Git update flow with isolated migration and Module command entry points.
$root = dirname(__DIR__, 3);
$fixture = <<<'PY'
import json, os, shutil, subprocess, tempfile
from pathlib import Path
source = Path.cwd()
with tempfile.TemporaryDirectory(prefix='peanut-product-git-update-') as temporary:
    base=Path(temporary).resolve()
    upstream=base/'upstream';app=base/'installed'
    def run(argv, cwd, expected=0):
        result=subprocess.run(argv,cwd=cwd,capture_output=True,text=True,timeout=25)
        if result.returncode!=expected:
            raise AssertionError((argv,result.returncode,result.stdout,result.stderr))
        return result.stdout+result.stderr
    def git(root,*args):return run(['git',*args],root).strip()
    def write(root,name,body):
        target=root/name;target.parent.mkdir(parents=True,exist_ok=True);target.write_text(body)
    def commit(root,msg):
        git(root,'add','.');git(root,'-c','user.name=Fixture','-c','user.email=fixture@example.invalid','commit','--quiet','-m',msg)
        return git(root,'rev-parse','HEAD')
    def deploy(target,expected=0,more=()):
        return run(['bash','scripts/deploy-update.sh','--target='+target,*more],app,expected)
    upstream.mkdir();git(upstream,'init','-q','-b','main')
    for name,body in {'.peanut/application-manifest.json':'{"application":{"edition":"standalone","version":"1.0.0"}}',
        '.gitignore':'.peanut/deploy-update.pending\nserver/.env\nserver/public/uploads/\nserver/private/\nfixture-*\n',
        'server/public/admin/index.html':'before','server/app/remove.php':'remove','server/app/main.php':'old',
        'server/think':'''<?php
$root=dirname(__DIR__);file_put_contents($root.'/fixture-native.log',implode(' ',array_slice($argv,1))."\\n",FILE_APPEND);
if($argv[1]==='migrate:run' && file_exists($root.'/fixture-interrupt')){fwrite(STDERR,"simulated native migration interruption\\n");exit(23);}
''',
        'server/module':'''<?php
$r=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
file_put_contents(dirname(__DIR__).'/fixture-native.log',$r['operation'].' '.implode(' ',$r['arguments'])."\\n",FILE_APPEND);
''', 'scripts/deploy-update.sh':(source/'scripts/deploy-update.sh').read_text()}.items():
        write(upstream,name,body)
    old=commit(upstream,'installed version')
    git(base,'clone','-q','--no-local',str(upstream),str(app))
    write(upstream,'server/app/main.php','updated')
    (upstream/'server/app/remove.php').unlink();write(upstream,'server/app/add.php','added')
    write(upstream,'server/public/admin/index.html','reviewed target')
    new=commit(upstream,'target version')
    private={'server/.env':'original environment','server/public/uploads/customer.bin':'upload',
             'server/private/installation/installed.json':'original installation','customer.php':'downstream custom'}
    for name,body in private.items():write(app,name,body)
    pending=app/'.peanut/deploy-update.pending'
    assert 'selected origin branch changed' in deploy(old,1)
    assert git(app,'rev-parse','HEAD')==old and not pending.exists()
    write(app,'server/app/main.php','customer change')
    assert 'tracked files have uncommitted changes' in deploy(new,1) and not pending.exists()
    git(app,'restore','--','server/app/main.php')
    write(app,'fixture-interrupt','before migration')
    assert 'simulated native migration interruption' in deploy(new,23)
    assert git(app,'rev-parse','HEAD')==new
    original=pending.read_bytes()
    assert original.decode().split()==[old,new,'standalone','admin','-','main'] and pending.stat().st_mode & 0o777==0o600
    def protected():return {name:(app/name).read_text() for name in private}
    assert protected()==private
    assert 'before selecting another target' in deploy(old,1) and pending.read_bytes()==original
    (app/'fixture-interrupt').unlink()
    assert 'Resuming' in deploy(new)
    assert not pending.exists() and protected()==private
    assert (app/'server/app/main.php').read_text()=='updated' and (app/'server/app/add.php').read_text()=='added'
    assert not (app/'server/app/remove.php').exists() and (app/'server/public/admin/index.html').read_text()=='reviewed target'
    assert (app/'fixture-native.log').read_text().splitlines()==['migrate:run','migrate:run','compile ','reconcile --release-locked --prepared-release','clear --cache']
    assert 'Already at' in deploy(new) and not pending.exists()
    write(upstream,'.peanut/application-manifest.json','{"application":{"edition":"multi-tenant"}}')
    changed=commit(upstream,'other edition')
    assert 'cannot change the installed Edition' in deploy(changed,1) and not pending.exists() and git(app,'rev-parse','HEAD')==new
    assert protected()==private
print('SERVER-UPDATE-PLAN passed: real Git ff-only update, pending-bound resume after injected native-stage failure, protected customer files; no live DB')
PY;
$process = proc_open(['python3', '-c', $fixture], [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, $root);
if (!is_resource($process) || proc_close($process) !== 0) {
    throw new RuntimeException('Product Git update contract failed');
}
