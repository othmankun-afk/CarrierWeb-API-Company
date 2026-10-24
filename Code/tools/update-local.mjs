// Update application files in a stopped installation under the same Windows user.
// Database state and bundled runtimes are preserved.
import {readFile,writeFile,mkdir,lstat,copyFile,realpath,readdir,rename} from 'node:fs/promises';
import {createInterface} from 'node:readline/promises';
import {createServer} from 'node:net';import {randomUUID,createHash} from 'node:crypto';
import path from 'node:path';import {fileURLToPath} from 'node:url';import assert from 'node:assert/strict';
import {verifiedManifest} from './windows-launcher.mjs';import {execute} from '../service/runtime.mjs';
const ownRoot=path.dirname(path.dirname(path.dirname(fileURLToPath(import.meta.url))));
const hash=b=>createHash('sha256').update(b).digest('hex');
async function absent(p){try{await lstat(p);return false;}catch(e){if(e.code==='ENOENT')return true;throw e;}}
export async function updateLocal({source=ownRoot,planFile,approve=false,allowLastKnown=false,injectFailureAfter=null}={}){
 assert.equal(approve,true,'EXPLICIT_LOCAL_UPDATE_APPROVAL_REQUIRED');assert.equal(allowLastKnown,true,'LAST_KNOWN_READ_POLICY_APPROVAL_REQUIRED');
 assert.ok(path.isAbsolute(source)&&path.isAbsolute(planFile||''),'ABSOLUTE_UPDATE_PATHS_REQUIRED');
 const plan=JSON.parse(await readFile(planFile));assert.equal(plan.profile,'LOCAL_EAI_SUITE_V1');assert.equal(path.dirname(planFile),plan.directory);
 const target=path.dirname(path.dirname(path.dirname(plan.node)));
 assert.equal(path.dirname(plan.directory),path.join(target,'Etat-local'),'OWN_INSTALLED_STATE_REQUIRED');
 assert.match(path.basename(plan.directory),/^cwvehicles-[a-f0-9-]{36}$/);
 for(const [key,rel] of [['node','Runtime/node/node.exe'],['php','Runtime/php/php.exe'],['mysqld','Runtime/mysql/bin/mysqld.exe']])assert.equal(path.resolve(plan[key]).toLowerCase(),path.join(target,rel).toLowerCase(),'OWN_BUNDLED_RUNTIME_REQUIRED');
 assert.notEqual(path.resolve(source).toLowerCase(),path.resolve(target).toLowerCase(),'SEPARATE_UPDATE_PACKAGE_REQUIRED');
 assert.ok(await absent(path.join(source,'Etat-local/installation.json'))&&await absent(path.join(source,'Etat-local/managed-update.json')),'UNINITIALIZED_OPERATOR_PACKAGE_REQUIRED');
 const systemPowerShell=path.join(process.env.WINDIR||process.env.SystemRoot,'System32/WindowsPowerShell/v1.0/powershell.exe');
 const owner=await execute(systemPowerShell,['-NoProfile','-NonInteractive','-Command',"@{result='PASS';sid=[Security.Principal.WindowsIdentity]::GetCurrent().User.Value}|ConvertTo-Json -Compress"]);
 assert.equal(owner.sid,plan.windowsOwnerSid,'BOOTSTRAP_WINDOWS_IDENTITY_REQUIRED');
 const before=await verifiedManifest(target),after=await verifiedManifest(source);
 for(const f of before.manifest.files.filter(f=>/^(Runtime|Licences)\//.test(f.path)))assert.equal(after.manifest.files.find(n=>n.path===f.path)?.sha256,f.sha256,'RUNTIME_UPDATE_REQUIRES_SEPARATE_IT_REVIEW');
 for(const f of after.manifest.files.filter(f=>/^(Runtime|Licences)\//.test(f.path)))assert.equal(before.manifest.files.find(n=>n.path===f.path)?.sha256,f.sha256,'NEW_RUNTIME_REQUIRES_SEPARATE_IT_REVIEW');
 const control=JSON.parse(await readFile(path.join(plan.directory,'control.json')));assert.equal(control.requested,'stop','STOP_SERVICE_BEFORE_UPDATE');
 const maintenanceFile=path.join(plan.directory,'registration.lock');assert.ok(await absent(maintenanceFile),'EXISTING_MAINTENANCE_REQUIRES_REVIEW');
 const operation=randomUUID(),guards=[];let backup,changed=[],configurationBefore,receiptBefore,receiptExisted=false,committed=false,ownsMarker=false;
 try{
  for(const port of [plan.lockPort,plan.supervisorPort,plan.apiPort,plan.sqlPort]){const server=createServer(s=>s.destroy());await new Promise((resolve,reject)=>{server.once('error',()=>reject(Error('OWN_PORT_NOT_STOPPED_NO_UPDATE')));server.listen(port,'127.0.0.1',resolve);});guards.push(server);}
  assert.equal(JSON.parse(await readFile(path.join(plan.directory,'control.json'))).requested,'stop','STOP_SERVICE_BEFORE_UPDATE');
  backup=path.join(plan.directory,'maintenance','update-'+randomUUID());await mkdir(backup,{recursive:true});
  await writeFile(maintenanceFile,JSON.stringify({profile:'OWN_LOCAL_UPDATE_V1',operation,backup,utc:new Date().toISOString()}),{flag:'wx'});ownsMarker=true;
  await writeFile(path.join(backup,'manifest-before.json'),before.bytes,{flag:'wx'});
  const configurationPath=path.join(plan.directory,'http-config.json');configurationBefore=await readFile(configurationPath);await writeFile(path.join(backup,'http-config-before.json'),configurationBefore,{flag:'wx'});
  const receiptPath=path.join(target,'MANAGED-PACKAGE.json');receiptExisted=!(await absent(receiptPath));if(receiptExisted){receiptBefore=await readFile(receiptPath);await writeFile(path.join(backup,'managed-before.json'),receiptBefore,{flag:'wx'});}
  const old=new Map(before.manifest.files.map(f=>[f.path,f]));let writes=0;
  for(const f of after.manifest.files){
   if(old.get(f.path)?.sha256===f.sha256)continue;
   const to=path.join(target,f.path),existed=!(await absent(to));
   if(existed&&!old.has(f.path)){assert.equal(hash(await readFile(to)),f.sha256,'UNINVENTORIED_TARGET_FILE_NO_OVERWRITE');continue;}
   if(existed){const saved=path.join(backup,'files',f.path);await mkdir(path.dirname(saved),{recursive:true});await copyFile(to,saved);}
   const bytes=await readFile(path.join(source,f.path));assert.equal(hash(bytes),f.sha256,'UPDATE_SOURCE_CHANGED');changed.push({path:f.path,existed});await mkdir(path.dirname(to),{recursive:true});await writeFile(to,bytes,existed?{}:{flag:'wx'});
   writes++;if(injectFailureAfter!==null&&writes>=injectFailureAfter)throw Error('FICTITIOUS_UPDATE_FAILURE');
  }
  await writeFile(path.join(target,'MANIFEST.json'),after.bytes);
  await verifiedManifest(target);
  const configuration=JSON.parse(configurationBefore);for(const c of Object.values(configuration.source.tenants))c.staleReadPolicy='last-known-with-warning';await writeFile(configurationPath,JSON.stringify(configuration,null,2)+'\n');
  await writeFile(receiptPath,JSON.stringify({profile:'CW_API_MANAGED_PACKAGE_V1',sourceRoot:source,manifestSha256:after.sha256,utc:new Date().toISOString(),privateStateCopied:false,updatedExistingInstance:true,previousManifestSha256:before.sha256,eaiCalls:0,filesDeleted:0},null,2)+'\n');
  await mkdir(path.join(source,'Etat-local'),{recursive:true});
  await writeFile(path.join(source,'Etat-local/managed-update.json'),JSON.stringify({profile:'CW_API_MANAGED_UPDATE_REFERENCE_V1',installedRoot:target,sourceManifestSha256:after.sha256,installedManifestSha256:after.sha256,windowsOwnerSid:plan.windowsOwnerSid},null,2)+'\n',{flag:'wx'});
  committed=true;
  const result={result:'PASS',profile:'STOPPED_SAME_USER_LOCAL_UPDATE_V1',utc:new Date().toISOString(),changedFiles:changed.length,backupDirectory:backup,operatorPackage:source,existingInstancePreserved:true,apiAccessPreserved:true,databaseOpened:false,cursorsChanged:false,eaiCalls:0,filesDeleted:0,serviceStarted:false,productionReady:false};
  await writeFile(path.join(backup,'RESULT.json'),JSON.stringify(result,null,2)+'\n',{flag:'wx'});return result;
 }catch(e){
  if(!committed&&backup){for(const f of changed.filter(f=>f.existed))await copyFile(path.join(backup,'files',f.path),path.join(target,f.path));await writeFile(path.join(target,'MANIFEST.json'),before.bytes);if(configurationBefore)await writeFile(path.join(plan.directory,'http-config.json'),configurationBefore);if(receiptExisted)await writeFile(path.join(target,'MANAGED-PACKAGE.json'),receiptBefore);
   await writeFile(path.join(backup,'UPDATE-FAILED.json'),JSON.stringify({result:'FAIL',restoredPreviousInventoriedFiles:true,newUnusedFilesRetained:true,filesDeleted:0,eaiCalls:0},null,2)+'\n',{flag:'wx'});}
  throw e;
 }finally{try{if(ownsMarker){const marker=JSON.parse(await readFile(maintenanceFile));assert.equal(marker.operation,operation,'UPDATE_MARKER_OWNERSHIP_CHANGED');await rename(maintenanceFile,path.join(backup,committed?'UPDATE-COMPLETE.lock':'UPDATE-FAILED.lock'));}}finally{for(const guard of guards)if(guard.listening)await new Promise(r=>guard.close(r));}}
}
if(process.argv[1]&&path.resolve(process.argv[1])===fileURLToPath(import.meta.url)){
 try{const flags=process.argv.slice(2);let planFile=flags.find(f=>f.startsWith('--plan='))?.slice(7),approve=flags.includes('--approve-local-update'),allowLastKnown=flags.includes('--allow-last-known');
  if(flags.includes('--interactive')){
   const local=process.env.LOCALAPPDATA;assert.ok(local&&path.isAbsolute(local),'LOCAL_APPDATA_REQUIRED');const candidates=[];
   for(const entry of await readdir(path.join(local,'CWAPI'),{withFileTypes:true})){if(!entry.isDirectory()||entry.isSymbolicLink())continue;const ref=path.join(local,'CWAPI',entry.name,'Etat-local/installation.json');if(await absent(ref))continue;const value=JSON.parse(await readFile(ref));if(value.profile==='CW_PORTABLE_REFERENCE_V1')candidates.push(value.planFile);}
   assert.ok(candidates.length>0,'NO_MANAGED_INSTALLATION_FOUND');
   const prompt=createInterface({input:process.stdin,output:process.stdout});try{candidates.forEach((c,i)=>console.log((i+1)+'. '+c));const selected=candidates.length===1?0:Number(await prompt.question('Installation number: '))-1;assert.ok(Number.isInteger(selected)&&selected>=0&&selected<candidates.length,'EXPLICIT_INSTALLATION_SELECTION_REQUIRED');planFile=candidates[selected];console.log('Stop the selected installation first. Code/config backup is retained. URLs, passwords, database and cursors stay in place. During EAI outages, verified last-known data is returned with STALE headers; this is not live data.');approve=(await prompt.question('Approve this local update and last-known-data policy? Type UPDATE: '))==='UPDATE';allowLastKnown=approve;}finally{prompt.close();}
  }
  console.log(JSON.stringify(await updateLocal({planFile,approve,allowLastKnown})));}
 catch(e){console.log(JSON.stringify({result:'FAIL',category:/^[A-Z0-9_]{1,100}$/.test(e.message)?e.message:'LOCAL_UPDATE_REQUIRES_REVIEW',eaiCalls:0,filesDeleted:0,productionReady:false}));process.exitCode=1;}
}
