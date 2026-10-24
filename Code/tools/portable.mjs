// Installed at Code/tools/portable.mjs. All application runtimes are relative
// to this package, never located in PATH or selected from another installation.
import {readFile,writeFile,mkdir,lstat} from 'node:fs/promises';
import {spawn} from 'node:child_process';
import {once} from 'node:events';
import {createServer} from 'node:net';
import {randomUUID,createHash} from 'node:crypto';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import assert from 'node:assert/strict';
import {apiRoot,execute,phpArgs} from '../service/runtime.mjs';
import {initializeEmptySuite} from './initialize-empty-suite.mjs';
import {controlSuite,statusText} from '../service/control-suite.mjs';
import {onboardInteractive} from './onboard-interactive.mjs';
import {clientAccess} from './client-access.mjs';

export const packageRoot=path.dirname(apiRoot);
export const runtime={node:path.join(packageRoot,'Runtime/node/node.exe'),php:path.join(packageRoot,'Runtime/php/php.exe'),mysqld:path.join(packageRoot,'Runtime/mysql/bin/mysqld.exe'),mysql:path.join(packageRoot,'Runtime/mysql/bin/mysql.exe'),mysqldump:path.join(packageRoot,'Runtime/mysql/bin/mysqldump.exe'),sdkDirectory:path.join(packageRoot,'Runtime/EAI')};
export function restrictEnvironment(){
 const windows=process.env.WINDIR||process.env.SystemRoot;assert.ok(windows&&path.isAbsolute(windows),'WINDOWS_SYSTEM_REQUIRED');
 process.env.PATH=[path.join(windows,'System32'),path.join(windows,'System32/WindowsPowerShell/v1.0'),windows].join(path.delimiter);
 process.env.PHPRC='';process.env.PHP_INI_SCAN_DIR='';process.env.NODE_PATH='';process.env.NODE_OPTIONS='';
 return {applicationRuntimesInPath:false,systemPathsOnly:true};
}
export async function verifyPackage(){
 const m=JSON.parse(await readFile(path.join(packageRoot,'MANIFEST.json')));assert.equal(m.profile,'CW_VEHICLES_PORTABLE_WINDOWS_RECETTE_V3');
 assert.equal(m.containsCredentials,false);assert.equal(m.containsDatabases,false);
 for(const file of m.files){assert.ok(!path.isAbsolute(file.path)&&!file.path.split(/[\\/]/).includes('..'),'MANIFEST_PATH_INVALID');const filename=path.join(packageRoot,file.path),s=await lstat(filename);assert.ok(s.isFile()&&!s.isSymbolicLink(),'REGULAR_BUNDLED_FILE_REQUIRED');assert.equal(createHash('sha256').update(await readFile(filename)).digest('hex'),file.sha256,'BUNDLED_FILE_HASH_MISMATCH');}
 return {result:'PASS',files:m.files.length,usesInstalledPhp:false,usesInstalledNode:false,usesInstalledMysql:false};
}
export async function runtimePreflight(){
 assert.equal(process.platform,'win32','WINDOWS_ONLY');restrictEnvironment();
 for(const filename of [runtime.node,runtime.php,runtime.mysqld,runtime.mysql,runtime.mysqldump,path.join(runtime.sdkDirectory,'php.exe')])assert.ok((await lstat(filename)).isFile(),'BUNDLED_RUNTIME_REQUIRED');
 const n=await execute(runtime.node,['-e','console.log(JSON.stringify({result:"PASS",version:process.version,executable:process.execPath}))']);
 const p=await execute(runtime.php,[...phpArgs(runtime.php,'-r'),'echo json_encode(array("result"=>extension_loaded("mysqli")?"PASS":"FAIL","version"=>PHP_VERSION,"executable"=>PHP_BINARY,"loadedIni"=>php_ini_loaded_file()));']);
 assert.equal(path.normalize(n.executable).toLowerCase(),path.normalize(runtime.node).toLowerCase());assert.equal(path.normalize(p.executable).toLowerCase(),path.normalize(runtime.php).toLowerCase());assert.equal(p.loadedIni,false);
 return {result:'PASS',nodeVersion:n.version,phpVersion:p.version,applicationRuntimesBundled:true,phpIniFromHostLoaded:false,windowsCrtStillSystemDependency:true};
}
export async function freePorts(){const listeners=[],ports=[];try{for(let i=0;i<4;i++){const s=createServer();await new Promise((resolve,reject)=>{s.once('error',reject);s.listen(0,'127.0.0.1',resolve);});listeners.push(s);ports.push(s.address().port);}return ports;}finally{for(const s of listeners)await new Promise(r=>s.close(r));}}
export async function installPortable(parent=path.join(packageRoot,'Etat-local')){
 assert.ok(packageRoot.length<=80,'EXTRACT_TO_SHORT_LOCAL_PATH_REQUIRED');await verifyPackage();await runtimePreflight();
 assert.ok(path.isAbsolute(parent)&&path.relative(packageRoot,parent)&&!path.relative(packageRoot,parent).startsWith('..'),'PRIVATE_PACKAGE_INSTALL_PARENT_REQUIRED');
 await mkdir(parent,{recursive:true});const directory=path.join(parent,'cwvehicles-'+randomUUID()),ports=await freePorts();
 const result=await initializeEmptySuite({directory,node:runtime.node,php:runtime.php,mysqld:runtime.mysqld,sdkDirectory:runtime.sdkDirectory,sqlPort:ports[0],apiPort:ports[1],lockPort:ports[2],supervisorPort:ports[3]});
 assert.equal(result.result,'PASS');return {...result,planFile:path.join(directory,'service-plan.json'),applicationRuntimesBundled:true};
}
const reference=path.join(packageRoot,'Etat-local/installation.json');
async function readReference(){const ref=JSON.parse(await readFile(reference));assert.equal(ref.profile,'CW_PORTABLE_REFERENCE_V1');const plan=JSON.parse(await readFile(ref.planFile));assert.equal(path.dirname(ref.planFile),plan.directory);assert.ok(!path.relative(path.join(packageRoot,'Etat-local'),plan.directory).startsWith('..'),'OWN_PORTABLE_INSTALLATION_REQUIRED');for(const k of ['node','php','mysqld'])assert.equal(path.normalize(plan[k]).toLowerCase(),path.normalize(runtime[k]).toLowerCase(),'OWN_BUNDLED_RUNTIME_REQUIRED');return {ref,plan};}
async function main(action){
 restrictEnvironment();
 if(action==='install'){
  try{await lstat(reference);throw Error('INSTALLATION_ALREADY_CREATED_USE_STATUS');}catch(e){if(e.code!=='ENOENT')throw e;}
  console.log('Checking bundled runtimes. No EAI access or changes to existing databases.');
  const result=await installPortable();await writeFile(reference,JSON.stringify({profile:'CW_PORTABLE_REFERENCE_V1',planFile:result.planFile},null,2),{flag:'wx'});return result;
 }
 let ref,plan;
 try{({ref,plan}=await readReference());}catch(error){if(action==='access'&&error.code==='ENOENT')throw Error('INSTALLATION_REFERENCE_NOT_FOUND');throw error;}
 if(action==='access')return clientAccess(ref.planFile,{listOnly:process.argv.includes('--list')});
 if(action==='recover'){
  const child=spawn(runtime.node,[path.join(apiRoot,'tools/recover-local-sync.mjs'),ref.planFile,'--interactive'],{cwd:packageRoot,windowsHide:true,stdio:'inherit'});
  const [code]=await once(child,'exit');if(code!==0)throw Error('RECOVERY_NOT_CONFIRMED_REVIEW_REPORT');
  return {result:'PASS',message:'Recovery/review finished. No service was started automatically. Use Start and confirm synchronization health in Status.'};
 }
 if(action==='add-client'){const added=await onboardInteractive(ref.planFile);console.log(JSON.stringify(added));return {result:'PASS',message:'Read the verification result and the indicated private access sheet. No automatic retry.'};}
 if(action==='test'){
  // Old-compatible HTTP intentionally hides most error causes behind 503.
  // A read-only local check can explain an already-reserved minute BEFORE
  // issuing the smoke requests. It does not reset or modify any quota file.
  const cfg=JSON.parse(await readFile(path.join(plan.directory,'http-config.json')));let retryAfterSeconds=0;
  assert.equal(path.resolve(cfg.rateLimitDirectory),path.join(plan.directory,'rate-limit'),'OWN_RATE_LIMIT_DIRECTORY_REQUIRED');
  for(const a of Object.values(cfg.accounts))for(const endpoint of a.endpoints){const filename=path.join(cfg.rateLimitDirectory,createHash('sha256').update(a.tenant+'/'+endpoint).digest('hex')+'.limit');try{const stamp=(await readFile(filename,'utf8')).trim();assert.ok(/^\d{1,10}$/.test(stamp),'LOCAL_MINUTE_STATE_INVALID');retryAfterSeconds=Math.max(retryAfterSeconds,Math.ceil(Number(stamp)+Math.max(60,cfg.options.minIntervalSeconds)-Date.now()/1000));}catch(e){if(e.code!=='ENOENT')throw e;}}
  if(retryAfterSeconds>0)return {result:'WAIT_OR_SKIPPED',category:'LOCAL_MINUTE_PREFLIGHT',retryAfterSeconds,eaiCalls:0,httpRequestsSent:0};
  const {testLocalDelivery}=await import('./test-local-delivery.mjs');return testLocalDelivery(ref.planFile);
 }
 const map={start:'Start',stop:'Stop',status:'Status'};assert.ok(map[action],'ACTION_REQUIRED');
 if(action==='start'){assert.ok(plan.clients.length>0,'ADD_CLIENT_FIRST');assert.notEqual(plan.testFixtureOnly,true,'FICTITIOUS_TEST_SERVICE_START_FORBIDDEN');}
 const controlled=await controlSuite(ref.planFile,map[action]);
 return {...controlled,message:statusText(controlled)+(controlled.message?'\n'+controlled.message:'')};
}
if(process.argv[1]&&path.resolve(process.argv[1])===fileURLToPath(import.meta.url)){
 try{const result=await main(process.argv[2]);console.log(result.result==='PASS'?'VERIFIED':'REVIEW REQUIRED');if(result.directory)console.log('Installation: '+result.directory);if(result.message)console.log(result.message);if(result.reportPath)console.log('Private report: '+result.reportPath);if(result.result==='WAIT_OR_SKIPPED')console.log('The minute quota is active. Wait at least 60 seconds without refreshing, then run Test again. This test makes no EAI call.');if(process.argv.includes('--json'))console.log(JSON.stringify(result));if(result.result!=='PASS')process.exitCode=1;}
 catch(e){console.log('NOT CONFIRMED: '+(/^[A-Z0-9_]{1,100}$/.test(e.message)?e.message:'REVIEW_LOCAL_DIAGNOSTIC'));if(process.argv[2]==='access')console.log('Use the launchers linked to your installation. If this is a new package for an existing instance, follow 08-Update-existing first. Do not reinstall or add the same account again to find its access file.');else if(process.argv[2]==='add-client')console.log('The client was not activated. Keep the diagnostic and staging files. After reviewing the error and a quiet minute for this EAI account, use Add client with the same username and choose RESUME. Partial registry commits or damaged local files require review; do not remove locks or reinstall.');else console.log('Do not delete anything. If a VC component is missing, ask IT to review the installers in Runtime/Prerequis.');process.exitCode=1;}
}
