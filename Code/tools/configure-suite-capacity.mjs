// Apply capacity settings to a stopped installation using local configuration files.
import {readFile,writeFile,mkdir,lstat,realpath,open,rename,copyFile} from 'node:fs/promises';
import {createServer} from 'node:net';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {randomUUID} from 'node:crypto';
import assert from 'node:assert/strict';
import {serviceRoot,execute,sha} from '../service/runtime.mjs';

const normalize=x=>path.normalize(path.resolve(x)).toLowerCase();
const category=e=>{const first=String(e?.message||'').split(/[\r\n]/,1)[0].trim();return /^[A-Z0-9_]{1,100}$/.test(first)?first:'CAPACITY_CONFIGURATION_NOT_CONFIRMED';};
async function regular(filename,max){assert.ok(path.isAbsolute(filename||''),'ABSOLUTE_PRIVATE_PATH_REQUIRED');const s=await lstat(filename);assert.ok(s.isFile()&&!s.isSymbolicLink()&&s.size<=max,'REGULAR_BOUNDED_PRIVATE_FILE_REQUIRED');const bytes=await readFile(filename);assert.ok(bytes.length<=max,'PRIVATE_FILE_BOUND');return bytes;}
async function atomicReplace(target,bytes){const pending=target+'.'+randomUUID()+'.pending';await writeFile(pending,bytes,{flag:'wx',mode:0o600});await rename(pending,target);}
async function record(folder,value){await writeFile(path.join(folder,'review.json'),JSON.stringify(value,null,2)+'\n',{mode:0o600});}
async function bind(port){const s=createServer();await new Promise((resolve,reject)=>{s.once('error',()=>reject(Error('SERVICE_MUST_BE_STOPPED_PORT_OCCUPIED')));s.listen(port,'127.0.0.1',resolve);});return s;}
export function capacityConfigurationDependencies(){
    return {
        assertOwner:async(plan,directory)=>{const args=['-NoProfile','-ExecutionPolicy','Bypass','-File',path.join(path.dirname(fileURLToPath(import.meta.url)),'capacity-owner.ps1'),'-Directory',directory];if(plan.windowsOwnerSid!==undefined&&plan.ownerSid!==undefined)assert.equal(plan.windowsOwnerSid,plan.ownerSid,'PLAN_WINDOWS_OWNER_MISMATCH');const expected=plan.windowsOwnerSid??plan.ownerSid;if(expected!==undefined){assert.match(expected,/^S-[0-9-]{3,100}$/,'PLAN_WINDOWS_OWNER_INVALID');args.push('-ExpectedSid',expected);}await execute('powershell.exe',args);},
        holdStopped:async plan=>{const listeners=[];try{const control=JSON.parse(await readFile(path.join(plan.directory,'control.json')));assert.equal(control.requested,'stop','SERVICE_STOP_CONFIRMATION_REQUIRED');for(const key of ['sqlPort','apiPort','lockPort','supervisorPort'])listeners.push(await bind(plan[key]));}catch(e){for(const s of listeners)if(s.listening)await new Promise(r=>s.close(r));throw e;}return async()=>{for(const s of listeners)if(s.listening)await new Promise(r=>s.close(r));};},
        validatePolicy:(plan,bytes)=>execute(plan.php,['-n',path.join(path.dirname(fileURLToPath(import.meta.url)),'validate-suite-capacity.php')],bytes).then(r=>r.policy),
        readSecrets:directory=>execute('powershell.exe',['-NoProfile','-ExecutionPolicy','Bypass','-File',path.join(serviceRoot,'secrets.ps1'),'-Operation','Read','-Path',path.join(directory,'secrets.dpapi')]),
        writeSecrets:(filename,value)=>execute('powershell.exe',['-NoProfile','-ExecutionPolicy','Bypass','-File',path.join(serviceRoot,'secrets.ps1'),'-Operation','Write','-Path',filename],JSON.stringify(value)),
        replace:atomicReplace,
        checkpoint:async()=>{}
    };
}

export async function configureSuiteCapacity({planFile,tenant,policyFile,evidenceFile},deps=capacityConfigurationDependencies()){
    assert.ok(path.isAbsolute(planFile||'')&&path.basename(planFile)==='service-plan.json','ABSOLUTE_SERVICE_PLAN_REQUIRED');assert.match(tenant||'',/^[a-z0-9_-]{1,64}$/,'TENANT_REQUIRED');
    const directory=path.dirname(planFile),dirStat=await lstat(directory);assert.ok(dirStat.isDirectory()&&!dirStat.isSymbolicLink(),'OWN_REGULAR_INSTALLATION_REQUIRED');assert.equal(normalize(await realpath(directory)),normalize(directory),'OWN_REGULAR_INSTALLATION_REQUIRED');assert.match(path.basename(directory),/^cwvehicles-[a-f0-9-]{36}$/,'OWN_BOOTSTRAP_INSTALLATION_REQUIRED');
    const marker=JSON.parse(await regular(path.join(directory,'INITIALISATION.json'),65536));assert.equal(marker.profile,'EMPTY_LOCAL_SUITE_INITIALIZATION_V1','OWN_BOOTSTRAP_INSTALLATION_REQUIRED');
    const initialPlan=JSON.parse(await regular(planFile,1048576));assert.equal(normalize(initialPlan.directory),normalize(directory),'PLAN_DIRECTORY_MISMATCH');assert.equal(initialPlan.profile,'LOCAL_EAI_SUITE_V1','SUITE_PLAN_REQUIRED');await deps.assertOwner(initialPlan,directory);
    const lockFile=path.join(directory,'registration.lock');let lock;try{lock=await open(lockFile,'wx');}catch{throw Error('REGISTRATION_LOCK_EXISTS_REVIEW_REQUIRED');}
    let release,reviewFolder,committing=false,phase='VALIDATE';
    const audit={profile:'CAPACITY_POLICY_CONFIGURATION_REVIEW_V1',tenant,utc:new Date().toISOString(),eaiCalls:0,databaseConnections:0,rowsDeleted:0,filesDeleted:0,retentionActivated:false,automaticRecovery:false,automaticRetry:false,productionReady:false};
    try{
        const planBytes=await regular(planFile,1048576),plan=JSON.parse(planBytes),configFile=path.join(directory,'http-config.json'),configBytes=await regular(configFile,1048576),config=JSON.parse(configBytes),secretFile=path.join(directory,'secrets.dpapi'),secretBefore=await regular(secretFile,2097152);
        assert.equal(normalize(plan.directory),normalize(directory),'PLAN_DIRECTORY_MISMATCH');assert.equal(plan.profile,'LOCAL_EAI_SUITE_V1','SUITE_PLAN_REQUIRED');await deps.assertOwner(plan,directory);assert.ok(Array.isArray(plan.clients),'SUITE_CLIENTS_REQUIRED');
        const portNames=['sqlPort','apiPort','lockPort','supervisorPort'];for(const name of portNames)assert.ok(Number.isInteger(plan[name])&&plan[name]>=1024&&plan[name]<=65535,'FOUR_EXPLICIT_SERVICE_PORTS_REQUIRED');assert.equal(new Set(portNames.map(k=>plan[k])).size,4,'DISTINCT_SERVICE_PORTS_REQUIRED');
        assert.equal(config.source?.driver,'eai-suite-live-mysqli','LIVE_SUITE_REQUIRED');assert.ok(config.options?.minIntervalSeconds>=60&&plan.intervalSeconds>=60,'MINUTE_RULE_REQUIRED');const clients=plan.clients.filter(c=>c.tenant===tenant);assert.equal(clients.length,1,'ONE_EXISTING_TENANT_REQUIRED');assert.ok(config.source.tenants&&Object.hasOwn(config.source.tenants,tenant),'TENANT_READER_REQUIRED');
        const controlBytes=await regular(path.join(directory,'control.json'),65536);assert.equal(JSON.parse(controlBytes).requested,'stop','SERVICE_STOP_CONFIRMATION_REQUIRED');release=await deps.holdStopped(plan);
        const policyBytes=await regular(policyFile,65536),evidenceBytes=await regular(evidenceFile,16777216),policy=await deps.validatePolicy(plan,policyBytes);assert.equal(policy?.version,'EAI_SUITE_CAPACITY_V1','CAPACITY_POLICY_VALIDATION_REQUIRED');assert.equal(sha(evidenceBytes),policy.evidence?.sha256,'CAPACITY_EVIDENCE_SHA256_MISMATCH');
        const secrets=await deps.readSecrets(directory);assert.ok(secrets&&secrets.clients&&Object.hasOwn(secrets.clients,tenant),'TENANT_PUBLISHER_REQUIRED');const publisher=secrets.clients[tenant].publisherConnection,reader=config.source.tenants[tenant];assert.ok(publisher&&publisher.database===reader.database&&publisher.host==='127.0.0.1'&&publisher.port===plan.sqlPort&&reader.host==='127.0.0.1'&&reader.port===plan.sqlPort,'OWN_TENANT_CONNECTION_REQUIRED');if(publisher.tenant!==undefined)assert.equal(publisher.tenant,tenant,'PUBLISHER_TENANT_MISMATCH');
        phase='STAGE';reviewFolder=path.join(directory,'capacity-policy-reviews',randomUUID());await mkdir(path.join(reviewFolder,'before'),{recursive:true,mode:0o700});await record(reviewFolder,{...audit,result:'STAGED',phase,evidenceSha256:sha(evidenceBytes),policySha256:sha(policyBytes)});
        await copyFile(planFile,path.join(reviewFolder,'before','service-plan.json'));await copyFile(configFile,path.join(reviewFolder,'before','http-config.json'));await copyFile(secretFile,path.join(reviewFolder,'before','secrets.dpapi'));await writeFile(path.join(reviewFolder,'policy-input.json'),policyBytes,{flag:'wx',mode:0o600});await writeFile(path.join(reviewFolder,'evidence.bin'),evidenceBytes,{flag:'wx',mode:0o600});
        const afterPlan=structuredClone(plan),afterConfig=structuredClone(config),afterSecrets=structuredClone(secrets);afterPlan.clients.find(c=>c.tenant===tenant).capacityPolicy=policy;afterConfig.source.tenants[tenant].capacityPolicy=policy;afterSecrets.clients[tenant].publisherConnection.capacityPolicy=policy;
        const stagedSecret=path.join(reviewFolder,'secrets-next.dpapi');await deps.writeSecrets(stagedSecret,afterSecrets);const stagedSecretBytes=await regular(stagedSecret,2097152);await writeFile(path.join(reviewFolder,'service-plan-next.json'),JSON.stringify(afterPlan,null,2)+'\n',{flag:'wx',mode:0o600});await writeFile(path.join(reviewFolder,'http-config-next.json'),JSON.stringify(afterConfig)+'\n',{flag:'wx',mode:0o600});
        await deps.checkpoint('STAGED',{planFile,configFile,secretFile,reviewFolder});assert.equal(sha(await readFile(planFile)),sha(planBytes),'PLAN_CHANGED_DURING_CAPACITY_REVIEW');assert.equal(sha(await readFile(configFile)),sha(configBytes),'CONFIG_CHANGED_DURING_CAPACITY_REVIEW');assert.equal(sha(await readFile(secretFile)),sha(secretBefore),'SECRETS_CHANGED_DURING_CAPACITY_REVIEW');assert.equal(sha(await readFile(path.join(directory,'control.json'))),sha(controlBytes),'SERVICE_CONTROL_CHANGED_DURING_CAPACITY_REVIEW');
        phase='COMMIT';committing=true;await record(reviewFolder,{...audit,result:'COMMITTING',phase,registryReviewRequired:true});await deps.replace(secretFile,stagedSecretBytes);await deps.replace(configFile,await readFile(path.join(reviewFolder,'http-config-next.json')));await deps.replace(planFile,await readFile(path.join(reviewFolder,'service-plan-next.json')));
        await record(reviewFolder,{...audit,result:'PASS',phase:'CONFIGURED',registryReviewRequired:false,evidenceSha256:sha(evidenceBytes),maxTenantEvents:policy.maxTenantEvents,maxTenantPayloadBytes:policy.maxTenantPayloadBytes,maxPublicationEvents:policy.maxPublicationEvents,existingPendingCaptureUnchanged:true,serviceRestartRequired:true});committing=false;
        return {result:'PASS',tenant,reviewFolder,evidenceHashVerified:true,configurationsUpdated:3,serviceRestartRequired:true,failedCaptureRecovered:false,automaticRetry:false,retentionActivated:false,eaiCalls:0,databaseConnections:0,filesDeleted:0,productionReady:false};
    }catch(e){if(reviewFolder)await record(reviewFolder,{...audit,result:'FAIL',phase,category:category(e),registryReviewRequired:committing,serviceMustRemainStopped:committing}).catch(()=>{});throw Error(category(e));}
    finally{
        let releaseFailed=false;try{if(release)await release();}catch{releaseFailed=true;committing=true;if(reviewFolder)await record(reviewFolder,{...audit,result:'FAIL',phase:'PORT_RELEASE',registryReviewRequired:true,serviceMustRemainStopped:true}).catch(()=>{});}
        await lock.close();if(!committing)await rename(lockFile,lockFile+'.closed-'+randomUUID());
        if(releaseFailed)throw Error('CAPACITY_STOP_PORT_RELEASE_REVIEW_REQUIRED');
    }
}

if(process.argv[1]&&path.resolve(process.argv[1])===fileURLToPath(import.meta.url)){
    try{const [planFile,tenant,policyFile,evidenceFile]=process.argv.slice(2);assert.ok(planFile&&tenant&&policyFile&&evidenceFile&&process.argv.length===6,'PLAN_TENANT_POLICY_EVIDENCE_ARGUMENTS_REQUIRED');console.log(JSON.stringify(await configureSuiteCapacity({planFile,tenant,policyFile,evidenceFile})));}
    catch(e){console.log(JSON.stringify({result:'FAIL',category:category(e),credentialsExposed:false,eaiCalls:0,databaseConnections:0,filesDeleted:0,automaticRetry:false,productionReady:false}));process.exitCode=1;}
}
