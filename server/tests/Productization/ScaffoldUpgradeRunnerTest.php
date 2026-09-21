<?php
declare(strict_types=1);

use app\common\infrastructure\scaffold\ScaffoldUpgradeRunner;
use app\platform\infrastructure\plugin\PluginArtifactWriter;

$root=dirname(__DIR__,3);
require_once $root.'/server/vendor/autoload.php';
require $root.'/scripts/scaffold-runtime/ScaffoldPathGuard.php';
require $root.'/scripts/scaffold-runtime/ScaffoldManifest.php';
require $root.'/scripts/scaffold-runtime/ScaffoldUpgradeLedger.php';
require $root.'/server/app/platform/exception/plugin/PluginArtifactToolException.php';
require $root.'/server/app/platform/exception/plugin/PluginLifecycleException.php';
require $root.'/server/app/platform/value/plugin/ModuleFrontendLayout.php';
require $root.'/server/app/platform/value/plugin/PluginDescriptor.php';
require $root.'/server/app/platform/infrastructure/plugin/PluginArtifactWriter.php';
require $root.'/server/app/platform/infrastructure/plugin/PluginLockResolver.php';
require $root.'/scripts/scaffold-runtime/ScaffoldUpgradeRunner.php';

const SCAFFOLD_FROM_COMMIT='14412607ba36f1816e39f7117f77eea4a9e7419e';
const SCAFFOLD_V1_1_2_CREATE_COMMIT='2cdb5763621e320c60cdbb834dcc0160e7bb7636';

function scaffoldExpect(bool $condition,string $message): void { if(!$condition)throw new RuntimeException($message); }
function scaffoldRun(array $command,?string $cwd=null,array $environment=[]): string
{
    $pipes=[];$process=proc_open($command,[1=>['pipe','w'],2=>['pipe','w']],$pipes,$cwd,$environment+$_ENV);
    if(!is_resource($process))throw new RuntimeException('unable to start command');
    $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
    if($code!==0)throw new RuntimeException('command failed('.$code.'): '.trim((string)$stderr));
    return (string)$stdout;
}
function scaffoldDelete(string $path): void
{
    if(is_dir($path)&&!is_link($path)){foreach(array_diff(scandir($path)?:[],['.','..'])as$entry)scaffoldDelete($path.'/'.$entry);rmdir($path);return;}
    if(file_exists($path)||is_link($path))unlink($path);
}
function scaffoldCopy(string $source,string $target): void
{
    mkdir($target,0775,true);$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
    foreach($iterator as$file){$relative=substr($file->getPathname(),strlen($source)+1);$destination=$target.'/'.$relative;if($file->isDir())mkdir($destination,$file->getPerms()&0777,true);else{copy($file->getPathname(),$destination);chmod($destination,$file->getPerms()&0777);}}
}
function scaffoldFileTree(string $root,bool $includeUpgradeState=false): string
{
    $rows=[];$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
    foreach($iterator as$file){$relative=str_replace('\\','/',substr($file->getPathname(),strlen($root)+1));if(!$includeUpgradeState&&($relative==='.peanut/upgrades'||str_starts_with($relative,'.peanut/upgrades/')))continue;$rows[]=($file->isDir()?'d':'f')."\0".$relative."\0".($file->isFile()?hash_file('sha256',$file->getPathname()):'-')."\0".($file->getPerms()&0777);}
    sort($rows,SORT_STRING);return hash('sha256',implode("\n",$rows));
}
function scaffoldOwnedTree(string $root,array $manifest,string $classification): string
{
    $rows=[];foreach($manifest['files'] as$file){if(($file['classification']??null)!==$classification)continue;$path=$root.'/'.$file['path'];$rows[]=$file['path']."\0".hash_file('sha256',$path)."\0".(fileperms($path)&0777);}sort($rows,SORT_STRING);return hash('sha256',implode("\n",$rows));
}
function scaffoldPlanPath(string $project,array $plan): string{return $project.'/'.$plan['plan_path'];}
function scaffoldFails(callable $callback,string $message): void
{
    try{$callback();}catch(RuntimeException $exception){scaffoldExpect(str_contains($exception->getMessage(),$message),'unexpected failure: '.$exception->getMessage());return;}
    throw new RuntimeException('expected failure: '.$message);
}
function scaffoldFresh(string $source,string $target): void
{
    scaffoldRun(['php',$source.'/scripts/create-app','--name=Acme Console','--slug=acme-console','--package=acme/acme-console','--target='.$target]);
    scaffoldInstallVersionContract($target);
}
function scaffoldInstallVersionContract(string $target,string $productRelease='0.1.0'): void
{
    $manifest=json_decode((string)file_get_contents($target.'/.peanut/application-manifest.json'),true,512,JSON_THROW_ON_ERROR);
    $templateVersion=$manifest['template']['version']??null;
    scaffoldExpect(is_string($templateVersion)&&preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:[-+][0-9A-Za-z.-]+)?$/D',$templateVersion)===1,'historical scaffold fixture must expose a valid template version');
    scaffoldExpect(preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:[-+][0-9A-Za-z.-]+)?$/D',$productRelease)===1,'historical scaffold fixture must expose a valid product release');
    $fixture=dirname(__DIR__,3).'/server/tests/fixtures/scaffold-upgrade/release-versions-template.json';
    $contract=json_decode((string)file_get_contents($fixture),true,512,JSON_THROW_ON_ERROR);
    scaffoldExpect(is_array($contract)&&($contract['scaffold_template']??null)==='__TEMPLATE_VERSION__','historical version contract fixture must retain its template placeholder');
    $contract['scaffold_template']=$templateVersion;
    $contract['product_release']=$productRelease;
    $written=file_put_contents($target.'/release-versions.json',json_encode($contract,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
    scaffoldExpect($written!==false,'historical scaffold fixture version contract must be written');
}
function scaffoldInstallV2VersionContract(string $target,string $instanceVersion,?string $coreVersion=null): void
{
    $manifest=json_decode((string)file_get_contents($target.'/.peanut/application-manifest.json'),true,512,JSON_THROW_ON_ERROR);
    $sourceVersion=$manifest['template']['version']??null;
    scaffoldExpect(is_string($sourceVersion),'v2 fixture source product version must be available');
    $contract=[
        'schema_version'=>2,
        'protocol'=>'peanut.release-versions.v2',
        'source_product_version'=>$sourceVersion,
        'instance_version'=>$instanceVersion,
        'scaffold_template'=>$sourceVersion,
        'generated_instance_default'=>'0.1.0',
        'core_php'=>$coreVersion??$sourceVersion,
        'core_web'=>$sourceVersion,
    ];
    file_put_contents($target.'/release-versions.json',json_encode($contract,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
}
function scaffoldFreshAdopted(string $source,string $releasePath,string $target): void
{
    $modern=is_file($source.'/server/app/common/infrastructure/scaffold/ApplicationCreator.php');
    $guard=$source.($modern?'/server/app/common/validation/scaffold/ScaffoldPathGuard.php':'/server/app/common/service/scaffold/ScaffoldPathGuard.php');
    $manifest=$source.($modern?'/server/app/common/value/scaffold/ScaffoldManifest.php':'/server/app/common/service/scaffold/ScaffoldManifest.php');
    $creatorPath=$source.($modern?'/server/app/common/infrastructure/scaffold/ApplicationCreator.php':'/server/app/common/service/scaffold/ApplicationCreator.php');
    $creatorClass=$modern?'app\\common\\infrastructure\\scaffold\\ApplicationCreator':'app\\common\\service\\scaffold\\ApplicationCreator';
    $code=<<<'PHP'
require $argv[1];
require $argv[2];
require $argv[3];
$release=json_decode((string)file_get_contents($argv[5]),true,512,JSON_THROW_ON_ERROR)['release'];
$class=$argv[4];
$creator=new $class(
    $argv[7],
    $argv[7].'/scaffold/application-template-inventory.json',
    ['commit'=>$release['source_commit'],'tree'=>$release['source_tree']],
    $argv[5]
);
$creator->create('Acme Console','acme-console','acme/acme-console',$argv[6]);
PHP;
    scaffoldRun(['php','-r',$code,$guard,$manifest,$creatorPath,$creatorClass,$releasePath,$target,$source]);
    scaffoldInstallVersionContract($target);
}
function scaffoldCopyRelease(string $source,string $target): void { scaffoldCopy(dirname($source),$target); }

$temporaryRoot=realpath(sys_get_temp_dir());if($temporaryRoot===false)throw new RuntimeException('temp root unavailable');
$temporary=$temporaryRoot.'/peanut-scaffold-e2e-'.bin2hex(random_bytes(8));mkdir($temporary,0700,true);
$fromRelease=$root.'/scaffold/releases/v1.0.0/scaffold-manifest.json';$toRelease=$root.'/scaffold/releases/v1.1.0/scaffold-manifest.json';$patchRelease=$root.'/scaffold/releases/v1.1.1/scaffold-manifest.json';$latestRelease=$root.'/scaffold/releases/v1.1.2/scaffold-manifest.json';$nextRelease=$root.'/scaffold/releases/v1.1.3/scaffold-manifest.json';$currentRelease=$root.'/scaffold/releases/v1.1.4/scaffold-manifest.json';$runtimeRelease=$root.'/scaffold/releases/v1.1.5/scaffold-manifest.json';$releaseCandidate=$root.'/scaffold/releases/v1.1.6/scaffold-manifest.json';$productRelease=$root.'/scaffold/releases/v1.1.7/scaffold-manifest.json';$hotfixRelease=$root.'/scaffold/releases/v1.1.8/scaffold-manifest.json';$managedSeederRelease=$root.'/scaffold/releases/v1.1.9/scaffold-manifest.json';
try{
    try{scaffoldFails(static fn():null=>null,'SCAFFOLD_TEST_EXPECTED_FAILURE');throw new RuntimeException('scaffoldFails accepted a successful callback');}catch(RuntimeException $exception){scaffoldExpect($exception->getMessage()==='expected failure: SCAFFOLD_TEST_EXPECTED_FAILURE','scaffoldFails must reject a successful callback');}
    $projectionRoot=$temporary.'/plugin-projection';
    scaffoldCopy($root.'/server/app/modules/official/identity',$projectionRoot.'/server/app/modules/official/identity');
    scaffoldCopy($root.'/server/app/modules/official/ops',$projectionRoot.'/server/app/modules/official/ops');
    scaffoldCopy($root.'/platform/src/modules/official-ops',$projectionRoot.'/platform/src/modules/official-ops');
    $projectionWriter=new PluginArtifactWriter($projectionRoot.'/server',false);
    $projectionWriter->make('official.identity','4.0.0-dev',['official.identity=server/app/modules/official/identity']);
    $projectionWriter->make('official.ops','1.0.0',['official.ops=server/app/modules/official/ops']);
    $projectionWriter->writeLock();
    $projectionRunner=new ScaffoldUpgradeRunner();
    $projectionReader=Closure::bind(
        fn(string $projectRoot):array=>$this->pluginProjection($projectRoot),
        $projectionRunner,
        ScaffoldUpgradeRunner::class,
    );
    scaffoldExpect(is_callable($projectionReader),'Plugin projection reader is unavailable');
    $projection=$projectionReader($projectionRoot);
    scaffoldExpect(
        ($projection['plugins']['official.identity']['frontend_roots']??null)===[]
            && ($projection['plugins']['official.ops']['frontend_roots']??null)===['platform/src/modules/official-ops'],
        'Plugin projection must derive backend-only and platform-only roots from real Module manifests',
    );
    $source=$temporary.'/from-source';
    scaffoldRun(['git','clone','--quiet','--no-local','--no-checkout',$root,$source]);
    scaffoldRun(['git','checkout','--quiet','--detach',SCAFFOLD_FROM_COMMIT],$source);
    $from=$temporary.'/from-app';scaffoldFresh($source,$from);
    $fromManifest=json_decode((string)file_get_contents($from.'/.peanut/application-manifest.json'),true,512,JSON_THROW_ON_ERROR);
    scaffoldExpect($fromManifest['template']['source_commit']===SCAFFOLD_FROM_COMMIT,'from app must use the formal create-app commit');

    $adoptionFromRelease=$temporary.'/adoption-from-release';$adoptionToRelease=$temporary.'/adoption-to-release';
    scaffoldCopyRelease($fromRelease,$adoptionFromRelease);scaffoldCopyRelease($toRelease,$adoptionToRelease);
    $adoptionPath='server/config/peanut.php';$adoptionSource=(string)file_get_contents($source.'/'.$adoptionPath);
    foreach([$adoptionFromRelease,$adoptionToRelease]as$adoptionRelease){
        $artifact=$adoptionRelease.'/files/'.$adoptionPath;if(!is_dir(dirname($artifact)))mkdir(dirname($artifact),0775,true);file_put_contents($artifact,$adoptionSource);
        $manifestPath=$adoptionRelease.'/scaffold-manifest.json';$manifest=json_decode((string)file_get_contents($manifestPath),true,512,JSON_THROW_ON_ERROR);
        $manifest['files'][]=['path'=>$adoptionPath,'source'=>'files/'.$adoptionPath,'template_sha256'=>hash('sha256',$adoptionSource),'classification'=>'managed','transform'=>'tokens','mode'=>0644,'policy'=>'managed','owner'=>'backend'];
        file_put_contents($manifestPath,json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
    }
    $runner=new ScaffoldUpgradeRunner();$adoptionPlan=$runner->preflight($from,$adoptionFromRelease.'/scaffold-manifest.json',$adoptionToRelease.'/scaffold-manifest.json');
    scaffoldExpect($adoptionPlan['status']==='blocked'&&count(array_filter($adoptionPlan['actions'],static fn(array $action):bool=>$action['reason']==='app_owned_adoption_required'))===1,'app-owned paths must require explicit adoption before becoming managed');
    $adoptionBefore=scaffoldFileTree($from);scaffoldFails(fn()=>$runner->apply($from,scaffoldPlanPath($from,$adoptionPlan)),'SCAFFOLD_PLAN_BLOCKED');scaffoldExpect(hash_equals($adoptionBefore,scaffoldFileTree($from)),'blocked ownership transition must not write the product tree');
    scaffoldDelete($from.'/.peanut/upgrades');

    $deletionPlan=$runner->preflight($from,$adoptionFromRelease.'/scaffold-manifest.json',$toRelease);
    scaffoldExpect($deletionPlan['status']==='blocked'&&count(array_filter($deletionPlan['actions'],static fn(array $action):bool=>$action['reason']==='app_owned_adoption_required'))===1,'upstream deletion of an app-owned path must require explicit adoption');
    $deletionBefore=scaffoldFileTree($from);scaffoldFails(fn()=>$runner->apply($from,scaffoldPlanPath($from,$deletionPlan)),'SCAFFOLD_PLAN_BLOCKED');scaffoldExpect(hash_equals($deletionBefore,scaffoldFileTree($from)),'blocked app-owned deletion must not write the product tree');
    scaffoldDelete($from.'/.peanut/upgrades');

    $missingManifestPath=$from.'/.peanut/application-manifest.json';$missingManifestBytes=(string)file_get_contents($missingManifestPath);$missingManifest=json_decode($missingManifestBytes,true,512,JSON_THROW_ON_ERROR);
    $missingManifest['files']=array_values(array_filter($missingManifest['files'],static fn(array $file):bool=>($file['path']??null)!=='README.md'));file_put_contents($missingManifestPath,json_encode($missingManifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
    $missingPlan=$runner->preflight($from,$fromRelease,$toRelease);scaffoldExpect($missingPlan['status']==='blocked'&&count(array_filter($missingPlan['actions'],static fn(array $action):bool=>$action['reason']==='managed_adoption_required'))===1,'a missing managed adoption record must block');
    $missingBefore=scaffoldFileTree($from);scaffoldFails(fn()=>$runner->apply($from,scaffoldPlanPath($from,$missingPlan)),'SCAFFOLD_PLAN_BLOCKED');scaffoldExpect(hash_equals($missingBefore,scaffoldFileTree($from)),'blocked missing adoption record must not write the product tree');
    file_put_contents($missingManifestPath,$missingManifestBytes);scaffoldDelete($from.'/.peanut/upgrades');

    $newTargetRelease=$temporary.'/new-target-release';scaffoldCopyRelease($toRelease,$newTargetRelease);$newPath='host/new-managed.txt';$newArtifact=$newTargetRelease.'/files/'.$newPath;if(!is_dir(dirname($newArtifact)))mkdir(dirname($newArtifact),0775,true);file_put_contents($newArtifact,"new host file\n");
    $newManifestPath=$newTargetRelease.'/scaffold-manifest.json';$newManifest=json_decode((string)file_get_contents($newManifestPath),true,512,JSON_THROW_ON_ERROR);$newManifest['files'][]=['path'=>$newPath,'source'=>'files/'.$newPath,'template_sha256'=>hash('sha256',"new host file\n"),'classification'=>'managed','transform'=>'tokens','mode'=>0644,'policy'=>'managed','owner'=>'host'];file_put_contents($newManifestPath,json_encode($newManifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
    $newPlan=$runner->preview($from,$fromRelease,$newManifestPath);scaffoldExpect($newPlan['status']==='ready'&&count(array_filter($newPlan['actions'],static fn(array $action):bool=>($action['path']??null)==='host/new-managed.txt'&&($action['action']??null)==='create'))===1,'a target-only path without an instance file must remain creatable');

    $appOwnedPath='server/config/peanut.php';file_put_contents($from.'/'.$appOwnedPath,(string)file_get_contents($from.'/'.$appOwnedPath)."\n// application customization\n");
    $appOwnedDigest=hash_file('sha256',$from.'/'.$appOwnedPath);

    $plan=$runner->preflight($from,$fromRelease,$toRelease);
    scaffoldExpect($plan['status']==='ready'&&$plan['summary']['conflicts']===0,'pristine managed tree must plan successfully');
    $apply=$runner->apply($from,scaffoldPlanPath($from,$plan));$verify=$runner->verify($from,scaffoldPlanPath($from,$plan));
    scaffoldExpect($apply['status']==='applied'&&$verify['status']==='verified','apply and verify must complete');
    scaffoldExpect(hash_equals($appOwnedDigest,(string)hash_file('sha256',$from.'/'.$appOwnedPath)),'app-owned customization must remain byte-identical');
    $toIdentity=json_decode((string)file_get_contents($toRelease),true,512,JSON_THROW_ON_ERROR)['release'];
    $toSource=$temporary.'/to-source';scaffoldRun(['git','clone','--quiet','--no-local','--no-checkout',$root,$toSource]);scaffoldRun(['git','checkout','--quiet','--detach',$toIdentity['source_commit']],$toSource);
    $toApplication=$temporary.'/to-app';scaffoldFresh($toSource,$toApplication);$toApplicationManifest=json_decode((string)file_get_contents($toApplication.'/.peanut/application-manifest.json'),true,512,JSON_THROW_ON_ERROR);
    scaffoldExpect($toApplicationManifest['template']['source_tree']===$toIdentity['source_tree'],'target create-app tree must match the release source tree');
    foreach($toApplicationManifest['files'] as $file){if(!in_array($file['classification'],['managed','generated-managed'],true))continue;$upgraded=$from.'/'.$file['path'];$generated=$toApplication.'/'.$file['path'];scaffoldExpect(is_file($upgraded)&&hash_equals((string)hash_file('sha256',$generated),(string)hash_file('sha256',$upgraded))&&((fileperms($generated)&0777)===(fileperms($upgraded)&0777)),'upgraded managed tree must exactly equal target create-app: '.$file['path']);}
    $idempotentBefore=scaffoldFileTree($from,true);
    $again=$runner->apply($from,scaffoldPlanPath($from,$plan));$verifyAgain=$runner->verify($from,scaffoldPlanPath($from,$plan));
    scaffoldExpect($again['idempotent']===true&&$verifyAgain['idempotent']===true,'successful candidate apply/verify must be idempotent');
    scaffoldExpect(hash_equals($idempotentBefore,scaffoldFileTree($from,true)),'idempotent apply/verify must perform no writes');
    $appliedManifestPath=$from.'/.peanut/application-manifest.json';$appliedManifestBytes=(string)file_get_contents($appliedManifestPath);$appliedManifest=json_decode($appliedManifestBytes,true,512,JSON_THROW_ON_ERROR);
    $managedDriftFile=null;foreach($appliedManifest['files']as$file){if(in_array($file['classification'],['managed','generated-managed'],true)&&($file['mode']??null)===0644){$managedDriftFile=$file;break;}}
    scaffoldExpect(is_array($managedDriftFile),'an ordinary managed file is required for repeat drift checks');
    $managedDriftPath=$from.'/'.$managedDriftFile['path'];$managedDriftBytes=(string)file_get_contents($managedDriftPath);
    file_put_contents($managedDriftPath,$managedDriftBytes."\nrepeat drift\n");$managedDriftTree=scaffoldFileTree($from,true);
    scaffoldFails(fn()=>$runner->apply($from,scaffoldPlanPath($from,$plan)),'SCAFFOLD_VERIFY_MANAGED_MISMATCH');
    scaffoldExpect(hash_equals($managedDriftTree,scaffoldFileTree($from,true)),'repeat apply wrote while rejecting managed content drift');
    file_put_contents($managedDriftPath,$managedDriftBytes);chmod($managedDriftPath,0600);$managedModeTree=scaffoldFileTree($from,true);
    scaffoldFails(fn()=>$runner->verify($from,scaffoldPlanPath($from,$plan)),'SCAFFOLD_VERIFY_MANAGED_MISMATCH');
    scaffoldExpect(hash_equals($managedModeTree,scaffoldFileTree($from,true)),'repeat verify wrote while rejecting managed mode drift');
    chmod($managedDriftPath,0644);
    $appOwnedBytes=(string)file_get_contents($from.'/'.$appOwnedPath);file_put_contents($from.'/'.$appOwnedPath,$appOwnedBytes."\n// post-verify drift\n");$appOwnedDriftTree=scaffoldFileTree($from,true);
    scaffoldFails(fn()=>$runner->apply($from,scaffoldPlanPath($from,$plan)),'SCAFFOLD_VERIFY_APP_OWNED_CHANGED');
    scaffoldExpect(hash_equals($appOwnedDriftTree,scaffoldFileTree($from,true)),'repeat apply wrote while rejecting app-owned drift');
    file_put_contents($from.'/'.$appOwnedPath,$appOwnedBytes);
    $identityDrift=$appliedManifest;$identityDrift['template']['source_commit']=str_repeat('0',40);file_put_contents($appliedManifestPath,json_encode($identityDrift,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");$identityDriftTree=scaffoldFileTree($from,true);
    scaffoldFails(fn()=>$runner->verify($from,scaffoldPlanPath($from,$plan)),'SCAFFOLD_VERIFY_APPLICATION_IDENTITY_MISMATCH');
    scaffoldExpect(hash_equals($identityDriftTree,scaffoldFileTree($from,true)),'repeat verify wrote while rejecting application identity drift');
    file_put_contents($appliedManifestPath,$appliedManifestBytes);

    $blocked=$temporary.'/blocked';scaffoldCopy($temporary.'/from-app',$blocked);
    // Restore a real v1 project for independent scenarios.
    scaffoldDelete($blocked);scaffoldCopy($temporary.'/from-app',$blocked);
    $blockedManifest=json_decode((string)file_get_contents($blocked.'/.peanut/application-manifest.json'),true,512,JSON_THROW_ON_ERROR);
    // The copied app is already upgraded, so create fresh scenario roots from the formal create-app tree.
    scaffoldDelete($blocked);scaffoldFresh($source,$blocked);
    $changed='scripts/scaffold-upgrade';file_put_contents($blocked.'/'.$changed,(string)file_get_contents($blocked.'/'.$changed)."\n# local managed edit\n");$beforeBlocked=scaffoldFileTree($blocked);
    $blockedPlan=$runner->preflight($blocked,$fromRelease,$toRelease);scaffoldExpect($blockedPlan['status']==='blocked','both-sides managed change must block');
    scaffoldFails(fn()=>$runner->apply($blocked,scaffoldPlanPath($blocked,$blockedPlan)),'SCAFFOLD_PLAN_BLOCKED');
    scaffoldExpect(hash_equals($beforeBlocked,scaffoldFileTree($blocked)),'blocked apply must perform zero product-tree writes');

    $stale=$temporary.'/stale';scaffoldFresh($source,$stale);
    $stalePlan=$runner->preflight($stale,$fromRelease,$toRelease);file_put_contents($stale.'/README.md',(string)file_get_contents($stale.'/README.md')."\nchanged after plan\n");
    scaffoldFails(fn()=>$runner->apply($stale,scaffoldPlanPath($stale,$stalePlan)),'SCAFFOLD_PLAN_PROJECT_CHANGED');

    $tampered=$temporary.'/tampered';scaffoldFresh($source,$tampered);$tamperedPlan=$runner->preflight($tampered,$fromRelease,$toRelease);$tamperedPath=scaffoldPlanPath($tampered,$tamperedPlan);
    $tamperedData=json_decode((string)file_get_contents($tamperedPath),true,512,JSON_THROW_ON_ERROR);$tamperedData['actions'][0]['mode']=0600;file_put_contents($tamperedPath,json_encode($tamperedData,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    scaffoldFails(fn()=>$runner->apply($tampered,$tamperedPath),'SCAFFOLD_PLAN_CHECKSUM_DRIFT');

    $fault=$temporary.'/fault';scaffoldFresh($source,$fault);
    $faultBefore=scaffoldFileTree($fault);$faultPlan=$runner->preflight($fault,$fromRelease,$toRelease);
    $faultRunner=new ScaffoldUpgradeRunner(1);scaffoldFails(fn()=>$faultRunner->apply($fault,scaffoldPlanPath($fault,$faultPlan)),'SCAFFOLD_FAULT_INJECTED');
    scaffoldExpect(!hash_equals($faultBefore,scaffoldFileTree($fault)),'fault injection must happen after a real replacement');
    $recover=$runner->recover($fault,scaffoldPlanPath($fault,$faultPlan));scaffoldExpect(hash_equals($faultBefore,scaffoldFileTree($fault)),'recovery must restore the exact pre-apply tree and modes');
    $recoverAgain=$runner->recover($fault,scaffoldPlanPath($fault,$faultPlan));scaffoldExpect($recoverAgain['idempotent']===true&&$recover['status']==='recovered','recovery must be idempotent');

    $securityRelease=$temporary.'/security-release';scaffoldCopyRelease($toRelease,$securityRelease);
    $securityManifest=$securityRelease.'/scaffold-manifest.json';$securityData=json_decode((string)file_get_contents($securityManifest),true,512,JSON_THROW_ON_ERROR);
    $securityData['files'][0]['path']='../escape';file_put_contents($securityManifest,json_encode($securityData,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    scaffoldFails(fn()=>$runner->preflight($fault,$fromRelease,$securityManifest),'SCAFFOLD_PATH_OUTSIDE_PROJECT');
    scaffoldDelete($securityRelease);scaffoldCopyRelease($toRelease,$securityRelease);$securityManifest=$securityRelease.'/scaffold-manifest.json';$securityData=json_decode((string)file_get_contents($securityManifest),true,512,JSON_THROW_ON_ERROR);
    $securityData['files'][0]['policy']='unknown';file_put_contents($securityManifest,json_encode($securityData,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    scaffoldFails(fn()=>$runner->preflight($fault,$fromRelease,$securityManifest),'SCAFFOLD_MANIFEST_POLICY_INVALID');
    scaffoldDelete($securityRelease);scaffoldCopyRelease($toRelease,$securityRelease);$securityManifest=$securityRelease.'/scaffold-manifest.json';$securityData=json_decode((string)file_get_contents($securityManifest),true,512,JSON_THROW_ON_ERROR);
    $securityData['files'][0]['transform']='unknown';file_put_contents($securityManifest,json_encode($securityData,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    scaffoldFails(fn()=>$runner->preflight($fault,$fromRelease,$securityManifest),'SCAFFOLD_MANIFEST_FILE_INVALID');

    $symlink=$temporary.'/symlink';scaffoldFresh($source,$symlink);$outside=$temporary.'/outside';file_put_contents($outside,'outside');unlink($symlink.'/scripts/scaffold-upgrade');symlink($outside,$symlink.'/scripts/scaffold-upgrade');
    scaffoldFails(fn()=>$runner->preflight($symlink,$fromRelease,$toRelease),'SCAFFOLD_PATH_SYMLINK_REJECTED');
    $hardlink=$temporary.'/hardlink';scaffoldFresh($source,$hardlink);link($hardlink.'/scripts/scaffold-upgrade',$hardlink.'/hardlink-alias');
    scaffoldFails(fn()=>$runner->preflight($hardlink,$fromRelease,$toRelease),'SCAFFOLD_PATH_HARDLINK_REJECTED');

    $drift=$temporary.'/drift';scaffoldFresh($source,$drift);$driftRelease=$temporary.'/drift-release';scaffoldCopyRelease($toRelease,$driftRelease);$driftManifest=$driftRelease.'/scaffold-manifest.json';$driftPlan=$runner->preflight($drift,$fromRelease,$driftManifest);
    file_put_contents($driftManifest,(string)file_get_contents($driftManifest)."\n");
    scaffoldFails(fn()=>$runner->apply($drift,scaffoldPlanPath($drift,$driftPlan)),'SCAFFOLD_MANIFEST_CHECKSUM_DRIFT');

    $patchSource=$temporary.'/patch-from-source';scaffoldRun(['git','clone','--quiet','--no-local','--no-checkout',$root,$patchSource]);scaffoldRun(['git','checkout','--quiet','--detach',$toIdentity['source_commit']],$patchSource);
    $patchApp=$temporary.'/patch-app';scaffoldFresh($patchSource,$patchApp);$patchBefore=scaffoldFileTree($patchApp);
    $patchPlan=$runner->preflight($patchApp,$toRelease,$patchRelease);
    scaffoldExpect($patchPlan['status']==='ready'&&$patchPlan['summary']['conflicts']===0,'v1.1.0 to patch plan must be ready');
    $patchActions=[];foreach($patchPlan['actions'] as $action)$patchActions[$action['path']]=$action['action'];
    scaffoldExpect(($patchActions['plugins.lock']??null)==='replace','patch must replace the broken Plugin lock');
    scaffoldExpect(($patchActions['server/fixtures/plugin-module-lifecycle/run.php']??null)==='delete','patch must remove the source-only lifecycle runner');
    $patchApply=$runner->apply($patchApp,scaffoldPlanPath($patchApp,$patchPlan));$patchVerify=$runner->verify($patchApp,scaffoldPlanPath($patchApp,$patchPlan));
    scaffoldExpect($patchApply['status']==='applied'&&$patchVerify['status']==='verified','v1.1.0 to patch apply/verify must complete');
    $patchLock=json_decode((string)file_get_contents($patchApp.'/plugins.lock'),true,64,JSON_THROW_ON_ERROR);
    scaffoldExpect($patchLock===['schema_version'=>1,'plugins'=>[]],'patch must install an explicitly empty Plugin lock');
    scaffoldExpect(!file_exists($patchApp.'/server/fixtures/plugin-module-lifecycle/run.php'),'patch must not retain the demo lifecycle runner');
    $patchRecover=$runner->recover($patchApp,scaffoldPlanPath($patchApp,$patchPlan));
    scaffoldExpect($patchRecover['status']==='recovered'&&hash_equals($patchBefore,scaffoldFileTree($patchApp)),'patch recovery must restore the exact v1.1.0 tree');

    $patchIdentity=json_decode((string)file_get_contents($patchRelease),true,512,JSON_THROW_ON_ERROR)['release'];
    $latestFromSource=$temporary.'/latest-from-source';scaffoldRun(['git','clone','--quiet','--no-local','--no-checkout',$root,$latestFromSource]);scaffoldRun(['git','checkout','--quiet','--detach',$patchIdentity['source_commit']],$latestFromSource);
    $latestApp=$temporary.'/latest-app';scaffoldFresh($latestFromSource,$latestApp);
    $latestAppOwnedPath='server/config/peanut.php';file_put_contents($latestApp.'/'.$latestAppOwnedPath,(string)file_get_contents($latestApp.'/'.$latestAppOwnedPath)."\n// v1.1.2 preservation proof\n");$latestAppOwnedDigest=hash_file('sha256',$latestApp.'/'.$latestAppOwnedPath);
    $latestApplicationManifestPath=$latestApp.'/.peanut/application-manifest.json';$latestApplicationManifest=json_decode((string)file_get_contents($latestApplicationManifestPath),true,512,JSON_THROW_ON_ERROR);
    $generationSource=['commit'=>str_repeat('c',40),'tree'=>str_repeat('d',40),'inventory_sha256'=>str_repeat('e',64)];$latestApplicationManifest['generation_source']=$generationSource;
    file_put_contents($latestApplicationManifestPath,json_encode($latestApplicationManifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
    $latestBefore=scaffoldFileTree($latestApp);$latestPlan=$runner->preflight($latestApp,$patchRelease,$latestRelease);
    scaffoldExpect($latestPlan['status']==='ready'&&$latestPlan['summary']['conflicts']===0,'v1.1.1 to v1.1.2 plan must be ready');
    $latestAutomatic=array_values(array_filter($latestPlan['actions'],static fn(array $action):bool=>in_array($action['action'],['create','delete','replace','regenerate'],true)));
    scaffoldExpect(count($latestAutomatic)===1&&$latestAutomatic[0]['path']==='scripts/project-resource-registry'&&$latestAutomatic[0]['action']==='replace','v1.1.2 must replace only the generic resource selector managed file');
    $latestApply=$runner->apply($latestApp,scaffoldPlanPath($latestApp,$latestPlan));$latestVerify=$runner->verify($latestApp,scaffoldPlanPath($latestApp,$latestPlan));
    scaffoldExpect($latestApply['status']==='applied'&&$latestVerify['status']==='verified','v1.1.1 to v1.1.2 apply/verify must complete');
    $latestAppliedManifest=json_decode((string)file_get_contents($latestApplicationManifestPath),true,512,JSON_THROW_ON_ERROR);
    scaffoldExpect(($latestAppliedManifest['generation_source']??null)===$generationSource,'scaffold upgrade must preserve generation_source');
    scaffoldExpect(($latestAppliedManifest['last_scaffold_upgrade']['from']??null)==='1.1.1'&&($latestAppliedManifest['last_scaffold_upgrade']['to']??null)==='1.1.2','scaffold upgrade must record the v1.1.2 transition');
    scaffoldExpect(hash_equals($latestAppOwnedDigest,(string)hash_file('sha256',$latestApp.'/'.$latestAppOwnedPath)),'v1.1.2 upgrade must preserve app-owned bytes');
    $latestRecover=$runner->recover($latestApp,scaffoldPlanPath($latestApp,$latestPlan));
    scaffoldExpect($latestRecover['status']==='recovered'&&hash_equals($latestBefore,scaffoldFileTree($latestApp)),'v1.1.2 recovery must restore the exact v1.1.1 tree');

    $nextFromSource=$temporary.'/next-from-source';scaffoldRun(['git','clone','--quiet','--no-local','--no-checkout',$root,$nextFromSource]);scaffoldRun(['git','checkout','--quiet','--detach',SCAFFOLD_V1_1_2_CREATE_COMMIT],$nextFromSource);
    $nextApp=$temporary.'/next-app';scaffoldFresh($nextFromSource,$nextApp);
    $nextAppOwnedPath='server/config/peanut.php';file_put_contents($nextApp.'/'.$nextAppOwnedPath,(string)file_get_contents($nextApp.'/'.$nextAppOwnedPath)."\n// v1.1.3 preservation proof\n");$nextAppOwnedDigest=hash_file('sha256',$nextApp.'/'.$nextAppOwnedPath);
    $nextApplicationManifestPath=$nextApp.'/.peanut/application-manifest.json';$nextApplicationManifest=json_decode((string)file_get_contents($nextApplicationManifestPath),true,512,JSON_THROW_ON_ERROR);
    $nextGenerationSource=['commit'=>str_repeat('f',40),'tree'=>str_repeat('1',40),'inventory_sha256'=>str_repeat('2',64)];$nextApplicationManifest['generation_source']=$nextGenerationSource;
    file_put_contents($nextApplicationManifestPath,json_encode($nextApplicationManifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
    $nextBefore=scaffoldFileTree($nextApp);$nextPlan=$runner->preflight($nextApp,$latestRelease,$nextRelease);
    scaffoldExpect($nextPlan['status']==='ready'&&$nextPlan['summary']['conflicts']===0,'v1.1.2 to v1.1.3 plan must be ready');
    $nextAutomatic=array_values(array_filter($nextPlan['actions'],static fn(array $action):bool=>in_array($action['action'],['create','delete','replace','regenerate'],true)));
    scaffoldExpect(count($nextAutomatic)===1&&$nextAutomatic[0]['path']==='deploy/docker/production.Dockerfile'&&$nextAutomatic[0]['action']==='replace','v1.1.3 must replace only the production Dockerfile managed file');
    $nextApply=$runner->apply($nextApp,scaffoldPlanPath($nextApp,$nextPlan));$nextVerify=$runner->verify($nextApp,scaffoldPlanPath($nextApp,$nextPlan));
    scaffoldExpect($nextApply['status']==='applied'&&$nextVerify['status']==='verified','v1.1.2 to v1.1.3 apply/verify must complete');
    $nextAppliedManifest=json_decode((string)file_get_contents($nextApplicationManifestPath),true,512,JSON_THROW_ON_ERROR);
    scaffoldExpect(($nextAppliedManifest['generation_source']??null)===$nextGenerationSource,'v1.1.3 scaffold upgrade must preserve generation_source');
    scaffoldExpect(($nextAppliedManifest['last_scaffold_upgrade']['from']??null)==='1.1.2'&&($nextAppliedManifest['last_scaffold_upgrade']['to']??null)==='1.1.3','scaffold upgrade must record the v1.1.3 transition');
    scaffoldExpect(hash_equals($nextAppOwnedDigest,(string)hash_file('sha256',$nextApp.'/'.$nextAppOwnedPath)),'v1.1.3 upgrade must preserve app-owned bytes');
    scaffoldExpect(str_contains((string)file_get_contents($nextApp.'/deploy/docker/production.Dockerfile'),'COPY plugins.lock /build/plugins.lock'),'v1.1.3 upgrade must install the production Plugin lock copy');
    scaffoldExpect(str_contains((string)file_get_contents($nextApp.'/deploy/docker/production.Dockerfile'),'COPY resources/project-resources.json resources/project-resources.json'),'v1.1.3 upgrade must install the production resource registry copy');
    $nextRecover=$runner->recover($nextApp,scaffoldPlanPath($nextApp,$nextPlan));
    scaffoldExpect($nextRecover['status']==='recovered'&&hash_equals($nextBefore,scaffoldFileTree($nextApp)),'v1.1.3 recovery must restore the exact v1.1.2 tree');

    $legacyIdentity=json_decode((string)file_get_contents($nextRelease),true,512,JSON_THROW_ON_ERROR)['release'];
    $legacySource=$temporary.'/legacy-version-source';scaffoldRun(['git','clone','--quiet','--no-local','--no-checkout',$root,$legacySource]);scaffoldRun(['git','checkout','--quiet','--detach',$legacyIdentity['source_commit']],$legacySource);
    $legacyApp=$temporary.'/legacy-version-app';scaffoldFreshAdopted($legacySource,$nextRelease,$legacyApp);
    $legacyManifestPath=$legacyApp.'/.peanut/application-manifest.json';$legacyManifest=json_decode((string)file_get_contents($legacyManifestPath),true,512,JSON_THROW_ON_ERROR);
    scaffoldExpect(($legacyManifest['protocol']??null)==='peanut.application-scaffold.v1'&&!isset($legacyManifest['application']['version']),'v1.1.3 fixture must exercise the legacy manifest path');
    $legacyMetadataPath=$legacyApp.'/RELEASE_METADATA.json';$legacyMetadata=json_decode((string)file_get_contents($legacyMetadataPath),true,512,JSON_THROW_ON_ERROR);$legacyMetadata['version']='2.4.6';file_put_contents($legacyMetadataPath,json_encode($legacyMetadata,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");scaffoldInstallVersionContract($legacyApp,'2.4.6');
    $legacyAppOwnedDigest=scaffoldOwnedTree($legacyApp,$legacyManifest,'app-owned');$legacyUniappDigest=hash_file('sha256',$legacyApp.'/uniapp/src/manifest.json');
    $legacyPlan=$runner->preflight($legacyApp,$nextRelease,$currentRelease);
    scaffoldExpect($legacyPlan['status']==='ready'&&($legacyPlan['identity']['application_version']??null)==='2.4.6','legacy manifest must uniquely adopt RELEASE_METADATA application version');
    $legacyApply=$runner->apply($legacyApp,scaffoldPlanPath($legacyApp,$legacyPlan));$legacyVerify=$runner->verify($legacyApp,scaffoldPlanPath($legacyApp,$legacyPlan));
    scaffoldExpect($legacyApply['status']==='applied'&&$legacyVerify['status']==='verified','v1.1.3 to v1.1.4 apply/verify must complete');
    $legacyApplied=json_decode((string)file_get_contents($legacyManifestPath),true,512,JSON_THROW_ON_ERROR);
    scaffoldExpect(($legacyApplied['schema_version']??null)===2&&($legacyApplied['protocol']??null)==='peanut.application-scaffold.v2'&&($legacyApplied['application']['version']??null)==='2.4.6','upgrade must normalize the application manifest without changing application.version');
    $legacyAppliedVersions=json_decode((string)file_get_contents($legacyApp.'/release-versions.json'),true,512,JSON_THROW_ON_ERROR);
    scaffoldExpect(($legacyAppliedVersions['product_release']??null)==='2.4.6'&&($legacyAppliedVersions['generated_application_default']??null)==='0.1.0','v1 upgrade must preserve the template default while retaining the instance release sequence');
    scaffoldExpect(hash_equals($legacyAppOwnedDigest,scaffoldOwnedTree($legacyApp,$legacyApplied,'app-owned')),'v1.1.4 upgrade must preserve all app-owned bytes');
    scaffoldExpect(hash_equals((string)$legacyUniappDigest,(string)hash_file('sha256',$legacyApp.'/uniapp/src/manifest.json')),'upgrade must preserve existing UniApp versionName/versionCode bytes');
    foreach(['web/package.json','pc/package.json','uniapp/package.json','server/config/project.php']as$versionPath)scaffoldExpect(str_contains((string)file_get_contents($legacyApp.'/'.$versionPath),'2.4.6'),'managed application version surface was not preserved: '.$versionPath);

    $ambiguousApp=$temporary.'/legacy-version-ambiguous';scaffoldFreshAdopted($legacySource,$nextRelease,$ambiguousApp);$ambiguousMetadataPath=$ambiguousApp.'/RELEASE_METADATA.json';$ambiguousMetadata=json_decode((string)file_get_contents($ambiguousMetadataPath),true,512,JSON_THROW_ON_ERROR);$ambiguousMetadata['application']=['version'=>'9.9.9'];file_put_contents($ambiguousMetadataPath,json_encode($ambiguousMetadata,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
    scaffoldFails(fn()=>$runner->preflight($ambiguousApp,$nextRelease,$currentRelease),'SCAFFOLD_LEGACY_APPLICATION_VERSION_AMBIGUOUS');

    $currentIdentity=json_decode((string)file_get_contents($currentRelease),true,512,JSON_THROW_ON_ERROR)['release'];
    $runtimeSource=$temporary.'/runtime-source';scaffoldRun(['git','clone','--quiet','--no-local','--no-checkout',$root,$runtimeSource]);scaffoldRun(['git','checkout','--quiet','--detach',$currentIdentity['source_commit']],$runtimeSource);
    $runtimeApp=$temporary.'/runtime-app';scaffoldFreshAdopted($runtimeSource,$currentRelease,$runtimeApp);
    scaffoldInstallV2VersionContract($runtimeApp,'2.4.6',$currentIdentity['version'].'+different-spelling');
    scaffoldFails(fn()=>$runner->preflight($runtimeApp,$currentRelease,$runtimeRelease),'product-core-version-mismatch');
    scaffoldInstallV2VersionContract($runtimeApp,'2.4.6');
    $v2Plan=$runner->preflight($runtimeApp,$currentRelease,$runtimeRelease);
    scaffoldExpect(($v2Plan['identity']['application_version']??null)==='2.4.6'&&($v2Plan['identity']['version_contract']['source_product_version']??null)===$currentIdentity['version']&&($v2Plan['identity']['version_contract']['generated_instance_default']??null)==='0.1.0','v2 upgrade must consume separate source, instance and default identities');
    scaffoldInstallVersionContract($runtimeApp);
    $runtimeAppOwnedPath='server/config/peanut.php';file_put_contents($runtimeApp.'/'.$runtimeAppOwnedPath,(string)file_get_contents($runtimeApp.'/'.$runtimeAppOwnedPath)."\n// v1.1.5 preservation proof\n");$runtimeAppOwnedDigest=hash_file('sha256',$runtimeApp.'/'.$runtimeAppOwnedPath);
    $runtimeBefore=scaffoldFileTree($runtimeApp);
    $runtimePlan=$runner->preflight($runtimeApp,$currentRelease,$runtimeRelease);scaffoldExpect($runtimePlan['status']==='ready'&&$runtimePlan['summary']['conflicts']===0,'v1.1.4 to v1.1.5 plan must be ready');
    $runtimeAutomatic=[];foreach($runtimePlan['actions']as$action)if(in_array($action['action'],['create','delete','replace','regenerate'],true))$runtimeAutomatic[$action['path']]=$action['action'];ksort($runtimeAutomatic,SORT_STRING);
    scaffoldExpect($runtimeAutomatic===['deploy/docker-compose.prod.yml'=>'replace','deploy/docker/nginx-select-admin.sh'=>'create','deploy/docker/production.Dockerfile'=>'replace','scripts/check-local-runtime-contract'=>'replace'],'v1.1.5 must contain only the production admin runtime compatibility actions');
    $runtimeApply=$runner->apply($runtimeApp,scaffoldPlanPath($runtimeApp,$runtimePlan));$runtimeVerify=$runner->verify($runtimeApp,scaffoldPlanPath($runtimeApp,$runtimePlan));
    scaffoldExpect($runtimeApply['status']==='applied'&&$runtimeVerify['status']==='verified','v1.1.4 to v1.1.5 apply/verify must complete');
    scaffoldExpect(hash_equals($runtimeAppOwnedDigest,(string)hash_file('sha256',$runtimeApp.'/'.$runtimeAppOwnedPath)),'v1.1.5 upgrade must preserve app-owned bytes');
    $runtimeDockerfile=(string)file_get_contents($runtimeApp.'/deploy/docker/production.Dockerfile');
    scaffoldExpect(str_contains($runtimeDockerfile,'VITE_DEPLOYMENT_MODE=standalone')&&str_contains($runtimeDockerfile,'VITE_DEPLOYMENT_MODE=multi-tenant')&&is_executable($runtimeApp.'/deploy/docker/nginx-select-admin.sh'),'v1.1.5 upgrade must install both admin bundles and the executable runtime selector');
    $runtimeRecover=$runner->recover($runtimeApp,scaffoldPlanPath($runtimeApp,$runtimePlan));scaffoldExpect($runtimeRecover['status']==='recovered'&&hash_equals($runtimeBefore,scaffoldFileTree($runtimeApp)),'v1.1.5 recovery must restore the exact v1.1.4 tree');

    $runtimeIdentity=json_decode((string)file_get_contents($runtimeRelease),true,512,JSON_THROW_ON_ERROR)['release'];
    $releaseCandidateSource=$temporary.'/release-candidate-source';scaffoldRun(['git','clone','--quiet','--no-local','--no-checkout',$root,$releaseCandidateSource]);scaffoldRun(['git','checkout','--quiet','--detach',$runtimeIdentity['source_commit']],$releaseCandidateSource);
    $releaseCandidateApp=$temporary.'/release-candidate-app';scaffoldFreshAdopted($releaseCandidateSource,$runtimeRelease,$releaseCandidateApp);
    $releaseCandidateAppOwnedPath='server/config/peanut.php';file_put_contents($releaseCandidateApp.'/'.$releaseCandidateAppOwnedPath,(string)file_get_contents($releaseCandidateApp.'/'.$releaseCandidateAppOwnedPath)."\n// v1.1.6 preservation proof\n");$releaseCandidateAppOwnedDigest=hash_file('sha256',$releaseCandidateApp.'/'.$releaseCandidateAppOwnedPath);
    $releaseCandidateBefore=scaffoldFileTree($releaseCandidateApp);$releaseCandidatePlan=$runner->preflight($releaseCandidateApp,$runtimeRelease,$releaseCandidate);
    scaffoldExpect($releaseCandidatePlan['status']==='ready'&&$releaseCandidatePlan['summary']['conflicts']===0,'v1.1.5 to v1.1.6 plan must be ready');
    $releaseCandidateAutomatic=[];foreach($releaseCandidatePlan['actions']as$action)if(in_array($action['action'],['create','delete','replace','regenerate'],true))$releaseCandidateAutomatic[$action['path']]=$action['action'];ksort($releaseCandidateAutomatic,SORT_STRING);
    scaffoldExpect($releaseCandidateAutomatic===['RELEASE_SBOM.spdx.json'=>'regenerate'],'v1.1.6 must only regenerate the lock-aligned SBOM');
    $releaseCandidateApply=$runner->apply($releaseCandidateApp,scaffoldPlanPath($releaseCandidateApp,$releaseCandidatePlan));$releaseCandidateVerify=$runner->verify($releaseCandidateApp,scaffoldPlanPath($releaseCandidateApp,$releaseCandidatePlan));
    scaffoldExpect($releaseCandidateApply['status']==='applied'&&$releaseCandidateVerify['status']==='verified','v1.1.5 to v1.1.6 apply/verify must complete');
    scaffoldExpect(hash_equals($releaseCandidateAppOwnedDigest,(string)hash_file('sha256',$releaseCandidateApp.'/'.$releaseCandidateAppOwnedPath)),'v1.1.6 upgrade must preserve app-owned bytes');
    $releaseCandidateRecover=$runner->recover($releaseCandidateApp,scaffoldPlanPath($releaseCandidateApp,$releaseCandidatePlan));scaffoldExpect($releaseCandidateRecover['status']==='recovered'&&hash_equals($releaseCandidateBefore,scaffoldFileTree($releaseCandidateApp)),'v1.1.6 recovery must restore the exact v1.1.5 tree');

    $releaseCandidateIdentity=json_decode((string)file_get_contents($releaseCandidate),true,512,JSON_THROW_ON_ERROR)['release'];
    $productReleaseSource=$temporary.'/product-release-source';scaffoldRun(['git','clone','--quiet','--no-local','--no-checkout',$root,$productReleaseSource]);scaffoldRun(['git','checkout','--quiet','--detach',$releaseCandidateIdentity['source_commit']],$productReleaseSource);
    $productReleaseApp=$temporary.'/product-release-app';scaffoldFreshAdopted($productReleaseSource,$releaseCandidate,$productReleaseApp);
    $productReleaseAppOwnedPath='server/config/peanut.php';file_put_contents($productReleaseApp.'/'.$productReleaseAppOwnedPath,(string)file_get_contents($productReleaseApp.'/'.$productReleaseAppOwnedPath)."\n// v1.1.7 preservation proof\n");$productReleaseAppOwnedDigest=hash_file('sha256',$productReleaseApp.'/'.$productReleaseAppOwnedPath);
    $productReleasePlan=$runner->preflight($productReleaseApp,$releaseCandidate,$productRelease);scaffoldExpect($productReleasePlan['status']==='ready'&&$productReleasePlan['summary']['conflicts']===0,'v1.1.6 to v1.1.7 plan must be ready');
    $productReleaseApply=$runner->apply($productReleaseApp,scaffoldPlanPath($productReleaseApp,$productReleasePlan));$productReleaseVerify=$runner->verify($productReleaseApp,scaffoldPlanPath($productReleaseApp,$productReleasePlan));
    scaffoldExpect($productReleaseApply['status']==='applied'&&$productReleaseVerify['status']==='verified','v1.1.6 to v1.1.7 apply/verify must complete');
    scaffoldExpect(hash_equals($productReleaseAppOwnedDigest,(string)hash_file('sha256',$productReleaseApp.'/'.$productReleaseAppOwnedPath)),'v1.1.7 upgrade must preserve app-owned bytes');

    $productReleaseIdentity=json_decode((string)file_get_contents($productRelease),true,512,JSON_THROW_ON_ERROR)['release'];
    $hotfixSource=$temporary.'/hotfix-source';scaffoldRun(['git','clone','--quiet','--no-local','--no-checkout',$root,$hotfixSource]);scaffoldRun(['git','checkout','--quiet','--detach',$productReleaseIdentity['source_commit']],$hotfixSource);
    $hotfixApp=$temporary.'/hotfix-app';scaffoldFreshAdopted($hotfixSource,$productRelease,$hotfixApp);
    $hotfixSeederDigest=hash_file('sha256',$hotfixApp.'/scripts/seed-demo-data');$hotfixBefore=scaffoldFileTree($hotfixApp);
    $hotfixPlan=$runner->preflight($hotfixApp,$productRelease,$hotfixRelease);scaffoldExpect($hotfixPlan['status']==='ready'&&$hotfixPlan['summary']['conflicts']===0,'v1.1.7 to v1.1.8 plan must be ready');
    $hotfixApply=$runner->apply($hotfixApp,scaffoldPlanPath($hotfixApp,$hotfixPlan));$hotfixVerify=$runner->verify($hotfixApp,scaffoldPlanPath($hotfixApp,$hotfixPlan));
    scaffoldExpect($hotfixApply['status']==='applied'&&$hotfixVerify['status']==='verified','v1.1.7 to v1.1.8 apply/verify must complete');
    scaffoldExpect(hash_equals((string)$hotfixSeederDigest,(string)hash_file('sha256',$hotfixApp.'/scripts/seed-demo-data')),'v1.1.8 upgrade must preserve the app-owned demo seeder');
    $hotfixDockerfile=(string)file_get_contents($hotfixApp.'/deploy/docker/production.Dockerfile');
    scaffoldExpect(str_contains($hotfixDockerfile,'COPY scripts/seed-demo-data scripts/seed-demo-data')&&str_contains($hotfixDockerfile,'chmod +x server/think scripts/seed-demo-data /usr/local/bin/peanut-php-entrypoint'),'v1.1.8 upgrade must install the executable demo seeder in the PHP image');
    $hotfixRecover=$runner->recover($hotfixApp,scaffoldPlanPath($hotfixApp,$hotfixPlan));scaffoldExpect($hotfixRecover['status']==='recovered'&&hash_equals($hotfixBefore,scaffoldFileTree($hotfixApp)),'v1.1.8 recovery must restore the exact v1.1.7 tree');

    $hotfixIdentity=json_decode((string)file_get_contents($hotfixRelease),true,512,JSON_THROW_ON_ERROR)['release'];
    $managedSeederSource=$temporary.'/managed-seeder-source';scaffoldRun(['git','clone','--quiet','--no-local','--no-checkout',$root,$managedSeederSource]);scaffoldRun(['git','checkout','--quiet','--detach',$hotfixIdentity['source_commit']],$managedSeederSource);
    $managedSeederApp=$temporary.'/managed-seeder-app';scaffoldFreshAdopted($managedSeederSource,$hotfixRelease,$managedSeederApp);
    file_put_contents($managedSeederApp.'/scripts/seed-demo-data',(string)file_get_contents($managedSeederApp.'/scripts/seed-demo-data')."\n// application wrapper customization\n");
    $managedSeederWrapperDigest=hash_file('sha256',$managedSeederApp.'/scripts/seed-demo-data');$managedSeederBefore=scaffoldFileTree($managedSeederApp);
    $managedSeederPlan=$runner->preflight($managedSeederApp,$hotfixRelease,$managedSeederRelease);scaffoldExpect($managedSeederPlan['status']==='ready'&&$managedSeederPlan['summary']['conflicts']===0,'v1.1.8 to v1.1.9 plan must be ready');
    $managedSeederApply=$runner->apply($managedSeederApp,scaffoldPlanPath($managedSeederApp,$managedSeederPlan));$managedSeederVerify=$runner->verify($managedSeederApp,scaffoldPlanPath($managedSeederApp,$managedSeederPlan));
    scaffoldExpect($managedSeederApply['status']==='applied'&&$managedSeederVerify['status']==='verified','v1.1.8 to v1.1.9 apply/verify must complete');
    scaffoldExpect(hash_equals((string)$managedSeederWrapperDigest,(string)hash_file('sha256',$managedSeederApp.'/scripts/seed-demo-data')),'v1.1.9 upgrade must preserve the app-owned wrapper bytes');
    $managedSeederDockerfile=(string)file_get_contents($managedSeederApp.'/deploy/docker/production.Dockerfile');
    scaffoldExpect(is_executable($managedSeederApp.'/server/database/seed-demo-data.php')&&str_contains($managedSeederDockerfile,'server/database/seed-demo-data.php')&&str_contains($managedSeederDockerfile,'peanut-seed-demo-data')&&!str_contains($managedSeederDockerfile,'COPY scripts/seed-demo-data'),'v1.1.9 upgrade must install the managed demo seeder without depending on the root wrapper');
    $managedSeederRecover=$runner->recover($managedSeederApp,scaffoldPlanPath($managedSeederApp,$managedSeederPlan));scaffoldExpect($managedSeederRecover['status']==='recovered'&&hash_equals($managedSeederBefore,scaffoldFileTree($managedSeederApp)),'v1.1.9 recovery must restore the exact v1.1.8 tree');

    $releaseCheckTemporary=$temporary.'/release-check-tmp';mkdir($releaseCheckTemporary,0700,true);$_ENV['TMPDIR']=$releaseCheckTemporary;
    $fromCheck=scaffoldRun(['php',$root.'/scripts/build-scaffold-release','--version=1.0.0','--source-commit='.SCAFFOLD_FROM_COMMIT,'--output='.$root.'/scaffold/releases/v1.0.0','--check']);
    $toManifest=json_decode((string)file_get_contents($toRelease),true,512,JSON_THROW_ON_ERROR);$toCheck=scaffoldRun(['php',$root.'/scripts/build-scaffold-release','--version=1.1.0','--source-commit='.$toManifest['release']['source_commit'],'--output='.$root.'/scaffold/releases/v1.1.0','--check']);
    $patchManifest=json_decode((string)file_get_contents($patchRelease),true,512,JSON_THROW_ON_ERROR);$patchCheck=scaffoldRun(['php',$root.'/scripts/build-scaffold-release','--version=1.1.1','--source-commit='.$patchManifest['release']['source_commit'],'--output='.$root.'/scaffold/releases/v1.1.1','--check']);
    $latestManifest=json_decode((string)file_get_contents($latestRelease),true,512,JSON_THROW_ON_ERROR);$latestCheck=scaffoldRun(['php',$root.'/scripts/build-scaffold-release','--version=1.1.2','--source-commit='.$latestManifest['release']['source_commit'],'--output='.$root.'/scaffold/releases/v1.1.2','--check']);
    $nextManifest=json_decode((string)file_get_contents($nextRelease),true,512,JSON_THROW_ON_ERROR);$nextCheck=scaffoldRun(['php',$root.'/scripts/build-scaffold-release','--version=1.1.3','--source-commit='.$nextManifest['release']['source_commit'],'--output='.$root.'/scaffold/releases/v1.1.3','--check']);
    $currentManifest=json_decode((string)file_get_contents($currentRelease),true,512,JSON_THROW_ON_ERROR);$currentCheck=scaffoldRun(['php',$root.'/scripts/build-scaffold-release','--version=1.1.4','--source-commit='.$currentManifest['release']['source_commit'],'--output='.$root.'/scaffold/releases/v1.1.4','--check']);
    $runtimeManifest=json_decode((string)file_get_contents($runtimeRelease),true,512,JSON_THROW_ON_ERROR);$runtimeCheck=scaffoldRun(['php',$root.'/scripts/build-scaffold-release','--version=1.1.5','--source-commit='.$runtimeManifest['release']['source_commit'],'--output='.$root.'/scaffold/releases/v1.1.5','--check']);
    $releaseCandidateManifest=json_decode((string)file_get_contents($releaseCandidate),true,512,JSON_THROW_ON_ERROR);$releaseCandidateCheck=scaffoldRun(['php',$root.'/scripts/build-scaffold-release','--version=1.1.6','--source-commit='.$releaseCandidateManifest['release']['source_commit'],'--output='.$root.'/scaffold/releases/v1.1.6','--check']);
    $productReleaseManifest=json_decode((string)file_get_contents($productRelease),true,512,JSON_THROW_ON_ERROR);$productReleaseCheck=scaffoldRun(['php',$root.'/scripts/build-scaffold-release','--version=1.1.7','--source-commit='.$productReleaseManifest['release']['source_commit'],'--output='.$root.'/scaffold/releases/v1.1.7','--check']);
    $hotfixManifest=json_decode((string)file_get_contents($hotfixRelease),true,512,JSON_THROW_ON_ERROR);$hotfixCheck=scaffoldRun(['php',$root.'/scripts/build-scaffold-release','--version=1.1.8','--source-commit='.$hotfixManifest['release']['source_commit'],'--output='.$root.'/scaffold/releases/v1.1.8','--check']);
    $managedSeederManifest=json_decode((string)file_get_contents($managedSeederRelease),true,512,JSON_THROW_ON_ERROR);$managedSeederCheck=scaffoldRun(['php',$root.'/scripts/build-scaffold-release','--version=1.1.9','--source-commit='.$managedSeederManifest['release']['source_commit'],'--output='.$root.'/scaffold/releases/v1.1.9','--check']);
    scaffoldExpect(str_contains($fromCheck,'verified')&&str_contains($toCheck,'verified')&&str_contains($patchCheck,'verified')&&str_contains($latestCheck,'verified')&&str_contains($nextCheck,'verified')&&str_contains($currentCheck,'verified')&&str_contains($runtimeCheck,'verified')&&str_contains($releaseCandidateCheck,'verified')&&str_contains($productReleaseCheck,'verified')&&str_contains($hotfixCheck,'verified')&&str_contains($managedSeederCheck,'verified'),'all immutable release trees must exactly regenerate');
}finally{unset($_ENV['TMPDIR']);scaffoldDelete($temporary);}

echo "SCAFFOLD-UPGRADE-E2E-001 passed\n";
