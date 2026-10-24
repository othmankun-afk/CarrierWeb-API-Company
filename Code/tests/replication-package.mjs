// Relocated installation tests use local MySQL/HTTP/DPAPI and fictitious EAI.
// Generated fixtures and closed lock files are retained for diagnosis.
import {readFile,writeFile,mkdir,mkdtemp,readdir,access,rename} from 'node:fs/promises';
import {randomUUID,randomBytes} from 'node:crypto';import {createServer,createConnection} from 'node:net';
import {once} from 'node:events';import path from 'node:path';import assert from 'node:assert/strict';
import {fileURLToPath} from 'node:url';import {apiRoot,execute,phpArgs,sha} from '../service/runtime.mjs';
import {tmpdir} from 'node:os';
import {suiteDatabase} from '../service/suite-runtime.mjs';
import {buildReplicationPackage,CODE_ALLOWLIST,assertNoPrivateContent} from '../tools/build-replication-package.mjs';
import {initializeEmptySuite} from '../tools/initialize-empty-suite.mjs';
import {addLiveClient,pendingClientReview} from '../tools/add-suite-client-core.mjs';
import {localDependencies} from '../tools/add-suite-client.mjs';
import {configureSuiteCapacity} from '../tools/configure-suite-capacity.mjs';

const port=async()=>{const server=createServer();await new Promise((resolve,reject)=>{server.once('error',reject);server.listen(0,'127.0.0.1',resolve);});const value=server.address().port;await new Promise(r=>server.close(r));return value;};
async function closed(value){return new Promise(resolve=>{const socket=createConnection({host:'127.0.0.1',port:value});socket.setTimeout(1000);socket.once('connect',()=>{socket.destroy();resolve(false);});socket.once('error',e=>{socket.destroy();resolve(e.code==='ECONNREFUSED');});socket.once('timeout',()=>{socket.destroy();resolve(false);});});}
async function assertPackage(directory){
    const manifest=JSON.parse(await readFile(path.join(directory,'PACKAGE-MANIFEST.json')));assert.equal(manifest.containsCredentials,false);assert.equal(manifest.containsCustomerAccounts,false);
    assert.equal(manifest.files.length,CODE_ALLOWLIST.length);for(const file of manifest.files){const bytes=await readFile(path.join(directory,file.path));assert.equal(sha(bytes),file.sha256);assertNoPrivateContent(file.path,bytes);}
    return manifest.files.length;
}
async function child(options){
    let checks=0;const check=ok=>{assert.ok(ok,'REPLICATION_CHECK_FAILED');checks++;};
    const directory=path.join(options.parent,'cwvehicles-'+randomUUID()),usedPorts=[];while(usedPorts.length<4){const n=await port();if(!usedPorts.includes(n))usedPorts.push(n);}
    const initialized=await initializeEmptySuite({directory,node:process.execPath,php:options.php,mysqld:options.mysqld,sdkDirectory:options.sdkDirectory,sqlPort:usedPorts[0],apiPort:usedPorts[1],lockPort:usedPorts[2],supervisorPort:usedPorts[3]});
    check(initialized.result==='PASS'&&initialized.clients===0&&initialized.eaiCalls===0&&initialized.shutdownConfirmed);
    const planFile=path.join(directory,'service-plan.json'),deps=localDependencies();let deliveries=0;
    const staged=[];const captures=new Map();
    let failedOnce=false,httpFailedOnce=false;
    const bound={...deps,verifyHttp:async(...args)=>{if(options.resumeFailed&&options.failResumeHttpOnce&&failedOnce&&!httpFailedOnce){httpFailedOnce=true;throw Error('FICTITIOUS_HTTP_FAILURE');}return deps.verifyHttp(...args);},collect:async(plan,client,password,cursors,preflight)=>{
        if(preflight)return deps.collect(plan,client,password,cursors,true);
        deliveries++;if(options.failedUsername===client.username&&(!options.resumeFailed||!failedOnce)){failedOnce=true;throw Error('FICTITIOUS_EAI_FAILURE_NO_RETRY');}
        const capture=(await execute(options.php,phpArgs(options.php,path.join(apiRoot,'tests/replication-onboarding-source.php')),JSON.stringify({tenant:client.tenant,vehicleId:String(100+deliveries)}))).capture;captures.set(client.tenant,capture);return capture;
    }};
    const makeInput=i=>({name:'Fictitious Customer '+i,eaiUsername:'customer-'+i+'.fixture',eaiPassword:'fictitious-eai-'+randomBytes(16).toString('hex'),approvedUnknownPolicy:'legacy-markers-v1',approvedCounterPolicy:'approved-compatibility-zero-empty-v1',noKnownEaiCallInLastMinute:true});
    for(let i=0;i<options.clients;i++){
        const input=makeInput(i),result=await addLiveClient(planFile,input,bound);check(result.result==='PASS'&&result.firstPublicationVerified&&result.httpEndpointsVerified===5);
        const sheet=JSON.parse(await readFile(result.accessSheetPath));check(sheet.password!==input.eaiPassword&&sheet.username!==input.eaiUsername&&Object.keys(sheet.urls).length===5);
        check(!JSON.stringify(sheet).includes(input.eaiPassword)&&!JSON.stringify(sheet).includes(input.eaiUsername));
        check(Object.values(sheet.urls).every(url=>new URL(url).searchParams.get('password')===sheet.password));
        staged.push({result,password:sheet.password});
    }
    check(new Set(staged.map(s=>s.password)).size===staged.length);check(new Set(staged.map(s=>s.result.account)).size===staged.length);
    let config=JSON.parse(await readFile(path.join(directory,'http-config.json'))),plan=JSON.parse(await readFile(planFile));
    check(plan.clients.length===options.clients&&config.options.minIntervalSeconds===60&&config.options.enforceMinuteRule===true);
    check(new Set(Object.values(config.source.tenants).map(c=>c.database)).size===options.clients);
    if(options.failedUsername){
        const watched=[planFile,path.join(directory,'http-config.json'),path.join(directory,'secrets.dpapi')],before=await Promise.all(watched.map(f=>readFile(f)));
        let failed=false;try{await addLiveClient(planFile,{...makeInput(999),eaiUsername:options.failedUsername},bound);}catch(e){failed=e.message==='FICTITIOUS_EAI_FAILURE_NO_RETRY';}check(failed);
        for(let i=0;i<watched.length;i++)check((await readFile(watched[i])).equals(before[i]));
        const prior=deliveries;let noRetry=false;try{await addLiveClient(planFile,{...makeInput(999),eaiUsername:options.failedUsername},bound);}catch(e){noRetry=e.message==='EAI_ACCOUNT_STAGED_REVIEW_REQUIRED_NO_RETRY';}check(noRetry&&deliveries===prior);
        if(options.resumeFailed){
            const pending=await pendingClientReview(planFile,options.failedUsername),stageFolder=path.join(directory,'client-onboarding');
            const pendingFolder=(await readdir(stageFolder,{withFileTypes:true})).filter(x=>x.isDirectory()).map(x=>path.join(stageFolder,x.name));let saved;
            for(const folder of pendingFolder){const state=JSON.parse(await readFile(path.join(folder,'state.json')));if(state.tenant===pending.tenant){saved=folder;break;}}
            check(!!saved&&pending.phase==='FIRST_EAI_ATTEMPT');
            if(options.legacyStage){
                await rename(path.join(saved,'client.json'),path.join(saved,'client-legacy-fixture-retained.json'));
                const meta=JSON.parse(await readFile(path.join(saved,'state.json')));delete meta.secretFile;delete meta.attemptCount;delete meta.lastAttemptUtc;delete meta.transportReserved;
                await writeFile(path.join(saved,'state.json'),JSON.stringify(meta));
            }
            const secrets=await deps.readSecrets(directory);await deps.startSql(plan,secrets);let state;
            try{const stagedSecrets=await deps.readSecrets(saved,'staged-secrets.dpapi');state=await suiteDatabase(plan,stagedSecrets.publisherConnection,'state',{tenant:pending.tenant});}finally{await deps.stopSql();}
            const wait=Math.max(0,Date.parse(state.lastAttemptUtc.replace(' ','T')+'Z')+62000-Date.now());if(wait)await new Promise(r=>setTimeout(r,wait));
            const resume={...makeInput(999),eaiUsername:options.failedUsername,resumeApproval:'RESUME_STAGED_CLIENT',resumeTenant:pending.tenant};
            if(options.failResumeHttpOnce){await assert.rejects(addLiveClient(planFile,resume,bound),/FICTITIOUS_HTTP_FAILURE/);checks++;check(deliveries===prior+1);for(let i=0;i<watched.length;i++)check((await readFile(watched[i])).equals(before[i]));}
            const result=await addLiveClient(planFile,resume,bound);check(result.resumed&&result.tenant===pending.tenant&&result.httpEndpointsVerified===5);check(result.eaiCalls===(options.failResumeHttpOnce?0:1));check(deliveries===prior+1);
            const sheet=JSON.parse(await readFile(result.accessSheetPath));staged.push({result,password:sheet.password});
            config=JSON.parse(await readFile(path.join(directory,'http-config.json')));plan=JSON.parse(await readFile(planFile));
            check(plan.clients.length===options.clients+1&&Object.keys(config.accounts).length===options.clients+1);
            const oldPlan=JSON.parse(before[0]),oldConfig=JSON.parse(before[1]);for(const client of oldPlan.clients)check(JSON.stringify(plan.clients.find(c=>c.tenant===client.tenant))===JSON.stringify(client));for(const [account,value] of Object.entries(oldConfig.accounts))check(JSON.stringify(config.accounts[account])===JSON.stringify(value));for(const [tenant,value] of Object.entries(oldConfig.source.tenants))check(JSON.stringify(config.source.tenants[tenant])===JSON.stringify(value));
            check(await pendingClientReview(planFile,options.failedUsername)===null);check((await readdir(stageFolder)).length===options.clients+1);
        }
    }
    // A quota review uses real ACL/DPAPI and stopped ports, but an explicitly
    // fictitious evidence file. It never changes a production capacity choice.
    const evidenceFile=path.join(directory,'fictitious-capacity-evidence.txt');
    const evidence=Buffer.from('FICTITIOUS LOCAL REPLICATION TEST ONLY; NOT A PRODUCTION MEASUREMENT\n');
    await writeFile(evidenceFile,evidence,{flag:'wx'});
    const capacityPolicy={version:'EAI_SUITE_CAPACITY_V1',maxTenantEvents:100002,maxTenantPayloadBytes:100000000,maxPublicationEvents:1000,warningPercent:80,evidence:{reference:'FICTITIOUS-REPLICATION-ONLY',sha256:sha(evidence),approvedBy:'synthetic-test-owner',approvedUtc:'2026-10-20 00:00:00',measuredHistoryBytesPerEvent:1000,historyBudgetBytes:200000000,safetyMarginPercent:20}};
    const policyFile=path.join(directory,'fictitious-capacity-policy.json');await writeFile(policyFile,JSON.stringify(capacityPolicy),{flag:'wx'});
    const capacityResult=await configureSuiteCapacity({planFile,tenant:staged[0].result.tenant,policyFile,evidenceFile});
    check(capacityResult.result==='PASS'&&capacityResult.evidenceHashVerified&&capacityResult.databaseConnections===0&&capacityResult.eaiCalls===0);
    plan=JSON.parse(await readFile(planFile));config=JSON.parse(await readFile(path.join(directory,'http-config.json')));
    check(config.source.tenants[staged[0].result.tenant].capacityPolicy.maxTenantEvents===100002);
    check(plan.clients.find(c=>c.tenant===staged[0].result.tenant).capacityPolicy.evidence.sha256===sha(evidence));
    check(Object.entries(config.source.tenants).every(([t,c])=>t===staged[0].result.tenant||c.capacityPolicy===undefined));
    const secrets=await deps.readSecrets(directory);
    check(secrets.clients[staged[0].result.tenant].publisherConnection.capacityPolicy.maxTenantEvents===100002);
    await deps.startSql(plan,secrets);
    try{
        const rows=Object.entries(config.source.tenants);
        if(rows.length>1)for(let i=0;i<rows.length;i++){
            const own=rows[i][1],other=rows[(i+1)%rows.length][1];const proof=await execute(options.php,phpArgs(options.php,path.join(apiRoot,'tests/replication-sql-isolation.php')),JSON.stringify({directory,connection:own,otherSchema:other.database}));check(proof.checks===3);
        }
        for(const [tenant,connection] of rows){const state=await suiteDatabase(plan,{...connection,tenant},'state',{tenant});check(state.generation===1&&!state.inFlight);}
    }finally{await deps.stopSql();}
    await assert.rejects(access(path.join(directory,'registration.lock')));checks++;
    const historyFiles=await readdir(directory);check(historyFiles.filter(f=>f.startsWith('registration.lock.closed-')).length>=options.clients);
    check(await closed(plan.sqlPort));
    return {result:'PASS',checks,installedCodeRoot:apiRoot,directory,successfulFictitiousClients:plan.clients.length,failedFictitiousAttempt:!!options.failedUsername,explicitResumeVerified:!!options.resumeFailed,legacyStageResumeVerified:!!options.legacyStage,httpFailureResumedWithoutAnotherEaiCall:!!options.failResumeHttpOnce,fictitiousDeliveries:deliveries,realEaiCalls:0,sourceSdkPreflightTransportAttempts:0,firstHttpFiveRoutesAndMinuteVerifiedForEveryClient:true,capacityConfigurationRealAclDpapiVerified:true,capacityEvidenceEntirelyFictitious:true,failedRegistryUnchangedVerified:!!options.failedUsername,sqlIsolationClients:plan.clients.length>1?plan.clients.length:0,fixtureSqlPortClosedAfterShutdown:true,closedRegistrationLocksRetained:true,filesDeleted:0,productionReady:false};
}
if(process.argv[2]==='--child'){
    try{let input='';for await(const chunk of process.stdin)input+=chunk;const options=JSON.parse(input),result=await child(options);if(options.reportPath){assert.ok(path.isAbsolute(options.reportPath));await writeFile(options.reportPath,JSON.stringify(result,null,2)+'\n',{flag:'wx'});}console.log(JSON.stringify(result));}
    catch(e){const first=String(e.message).split('\n')[0];console.log(JSON.stringify({result:'FAIL',category:/^[A-Z0-9_]+$/.test(first)?first:'RELOCATED_INSTALLATION_FAILED',filesDeleted:0}));process.exitCode=1;}
}else{
    const [php,mysqld,sdkDirectory,suffix,artifactParent=tmpdir()]=process.argv.slice(2);assert.ok(path.isAbsolute(php)&&path.isAbsolute(mysqld)&&path.isAbsolute(sdkDirectory)&&path.isAbsolute(artifactParent)&&/^[A-Za-z0-9_-]+$/.test(suffix));
    assert.ok(artifactParent.length<=80,'SHORT_LOCAL_ARTIFACT_PARENT_REQUIRED_LEGACY_PHP');
    await mkdir(path.join(apiRoot,'test-results'),{recursive:true});const directory=await mkdtemp(path.join(artifactParent,'cwrepl-'));
    let report={result:'FAIL',suite:'code-only-relocated-two-installations',directory,eaiCalls:0,oldApiCalls:0,realCustomerDataUsed:false,filesDeleted:0,actualOtherMachineTested:false,productionReady:false};
    try{
        const built=await buildReplicationPackage(path.join(directory,'code-only-package'));await assertPackage(built.directory);const runs=[];
        for(let i=0;i<2;i++){
            const relocated=path.join(directory,'relocated-'+i);await execute('powershell.exe',['-NoProfile','-ExecutionPolicy','Bypass','-File',path.join(apiRoot,'tools/package-archive.ps1'),'-Action','Expand','-Source',built.archive,'-Target',relocated]);
            const count=await assertPackage(relocated);const result=await execute(process.execPath,[path.join(relocated,'tests/replication-package.mjs'),'--child'],JSON.stringify({parent:directory,php,mysqld,sdkDirectory,clients:i===0?3:1,failedUsername:i===0?'failed.fixture':null}),240000,relocated);
            runs.push({...result,codeFilesVerified:count});
        }
        report={...report,result:'PASS',package:built,runs,successfulFictitiousClients:runs.reduce((n,r)=>n+r.successfulFictitiousClients,0),checks:runs.reduce((n,r)=>n+r.checks,0),credentialsGeneratedDistinctly:true,existingInstallationsOpened:0,sourceWorkspaceCodeDependency:false,externalRuntimePrerequisitesRequired:true};
    }catch(e){const code=String(e.message).split('\n')[0];report.failureCategory=/^[A-Z0-9_]+$/.test(code)?code:(e.code==='ENOENT'?'PACKAGE_FILE_MISSING':'REPLICATION_NOT_CONFIRMED');process.exitCode=1;}
    report.utc=new Date().toISOString();await writeFile(path.join(apiRoot,'test-results/replication-'+suffix+'.json'),JSON.stringify(report,null,2)+'\n',{flag:'wx'});console.log(JSON.stringify(report));
}
