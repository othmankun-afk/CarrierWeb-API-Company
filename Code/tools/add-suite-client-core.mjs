// Register a client after a validated EAI publication and five authenticated HTTP checks.
// Credentials are retained in memory or the installation's private files.
import {readFile,writeFile,mkdir,readdir,open,rename,copyFile} from 'node:fs/promises';
import path from 'node:path';
import {randomBytes,randomUUID} from 'node:crypto';
import assert from 'node:assert/strict';
import {sha} from '../service/runtime.mjs';
import {apiAccount} from './suite-registry.mjs';
import {publicBaseForPlan} from '../service/deployment-config.mjs';
import {recoveryReview} from './recovery-policy.mjs';

export const SUITE_ENDPOINTS=['vehicles','vehiclesBase','positions','canbus','telemetry'];
export const SUITE_FEEDS=['vehicles','divisions','positions','canbushistory','telemetry','wiredtelemetry'];
export function genericProfile(tenant){
    assert.match(tenant,/^[a-z0-9_-]{1,64}$/);
    const signal={source:'eai_signals',operation:'eai_current_signal',policy:'documented-eai-v1',tenant,selection:'unique-physical-source',anchor:'capturedUtc',maxAgeSeconds:null,unknownPolicy:'legacy-markers-v1'};
    const counter={source:'contract_compatibility',operation:'legacy_vehicle_counter',policy:'approved-compatibility-zero-empty-v1',tenant};
    return {endpoint:'vehicles',customer:tenant,wireContractMode:'carrierweb-legacy',status:'EAI_SOURCE_POLICY_WITH_APPROVED_UNKNOWN_MARKERS',productionReady:false,legacyProviderParity:false,
        ruleOverrides:{DivisionDesc:{source:'divisions',column:'Description',operation:'text',missingKeyValues:{'id:0':'-'}},Location:{source:'vehicles',column:'LastLocation',operation:'text'},
            ...Object.fromEntries(['Door','Temperature','Battery','Engine','Plugged','PluggedType'].map(k=>[k,{...signal}])),EngineRunningTime:{...counter},EngineRunningTimeHHMM:{...counter}},
        observations:{scope:{mode:'eai-account'},legacyEvidenceAvailable:false},counterPolicyDecision:'Vehicle runtime fields use compatibility constants 0 and empty text; measured CANbus counters remain separate',
        unknownMarkerDecision:{configured:true,scope:'Missing, ambiguous or unavailable measurements use the configured unknown markers'}};
}
export function validateRequest(input,plan,config,stages=[]){
    assert.equal(plan.profile,'LOCAL_EAI_SUITE_V1','SUITE_PLAN_REQUIRED');
    assert.ok(Number.isInteger(plan.intervalSeconds)&&plan.intervalSeconds>=60,'MINUTE_RULE_REQUIRED');
    assert.equal(config.source?.driver,'eai-suite-live-mysqli','LIVE_SUITE_REQUIRED');
    publicBaseForPlan(plan); // reject a malformed address before any upstream attempt
    assert.equal(input.approvedUnknownPolicy,'legacy-markers-v1','UNKNOWN_POLICY_APPROVAL_REQUIRED');
    assert.equal(input.approvedCounterPolicy,'approved-compatibility-zero-empty-v1','COUNTER_POLICY_APPROVAL_REQUIRED');
    assert.equal(input.noKnownEaiCallInLastMinute,true,'EAI_QUIET_MINUTE_CONFIRMATION_REQUIRED');
    for(const key of ['name','eaiUsername','eaiPassword'])assert.ok(typeof input[key]==='string'&&input[key].length>0&&input[key].length<=256&&!/[\r\n\0]/.test(input[key]),'CLIENT_INPUT_INVALID');
    const username=input.eaiUsername.trim().toLowerCase();assert.equal(input.eaiUsername,input.eaiUsername.trim(),'EAI_USERNAME_WHITESPACE');
    assert.ok(!plan.clients.some(c=>c.username.toLowerCase()===username),'EAI_ACCOUNT_ALREADY_REGISTERED');
    const base=apiAccount(input.name);assert.ok(base,'CLIENT_NAME_INVALID');
    const pending=stages.filter(c=>c.eaiUsername?.toLowerCase()===username&&!c.enabled);
    assert.ok(!stages.some(c=>c.enabled&&c.eaiUsername?.toLowerCase()===username),'ONBOARDING_REGISTRY_REVIEW_REQUIRED');
    assert.ok(pending.length<=1,'ONBOARDING_MULTIPLE_STAGES_REVIEW_REQUIRED');
    if(pending.length){
        const previous=pending[0];
        assert.equal(input.resumeApproval,'RESUME_STAGED_CLIENT','EAI_ACCOUNT_STAGED_REVIEW_REQUIRED_NO_RETRY');
        assert.equal(input.resumeTenant,previous.tenant,'ONBOARDING_RESUME_IDENTITY_CHANGED');
        assert.ok(!previous.registryReviewRequired&&previous.phase!=='REGISTRY_COMMIT','ONBOARDING_REGISTRY_REVIEW_REQUIRED');
        assert.ok(['PREPARED','SQL_PROVISION','FIRST_EAI_ATTEMPT','FIRST_PUBLICATION','FIRST_HTTP_VERIFICATION'].includes(previous.phase),'ONBOARDING_LOCAL_PREPARATION_REVIEW_REQUIRED');
        return {tenant:previous.tenant,base,previous};
    }
    assert.ok(!input.resumeApproval&&!input.resumeTenant,'ONBOARDING_RESUME_STAGE_MISSING');
    assert.ok(plan.clients.length<100&&stages.filter(s=>!s.enabled).length+plan.clients.length<100,'LOCAL_CLIENT_BOUND');
    const tenant=base.slice(0,40)+'-'+randomBytes(6).toString('hex');
    assert.ok(!plan.clients.some(c=>c.tenant===tenant)&&!config.source.tenants[tenant],'TENANT_ALREADY_REGISTERED');
    return {tenant,base};
}
export function requireFirstCapture(capture,tenant){
    assert.equal(capture?.result,'PASS','FIRST_CAPTURE_REQUIRED');assert.equal(capture.tenant,tenant,'FIRST_CAPTURE_TENANT');
    assert.equal(capture.transportAttempts,1,'SINGLE_EAI_CALL_REQUIRED');assert.equal(capture.serverWrites,0,'READ_ONLY_EAI_REQUIRED');
    assert.ok(capture.bundle?.tenant===tenant&&capture.bundle.timeBasis==='UTC','FIRST_CAPTURE_IDENTITY');
    // Wired telemetry enriches vehicle signals, but has no separate public
    // endpoint. Its absence may use the approved unknown markers. Missing
    // public history feeds cannot be advertised as a working endpoint.
    for(const feed of SUITE_FEEDS.filter(f=>f!=='wiredtelemetry'))assert.ok(Array.isArray(capture.bundle.feeds?.[feed]),'FIRST_CAPTURE_ALL_FEEDS_REQUIRED');
    assert.ok(capture.bundle.feeds.vehicles.length>0,'FIRST_AUTHORIZED_VEHICLE_REQUIRED');
}
async function stageRecords(directory){
    const folder=path.join(directory,'client-onboarding');let list=[];try{list=await readdir(folder,{withFileTypes:true});}catch(e){if(e.code!=='ENOENT')throw e;}
    const out=[];for(const entry of list){
        assert.ok(!entry.isSymbolicLink(),'ONBOARDING_STATE_REVIEW_REQUIRED');if(!entry.isDirectory())continue;
        assert.match(entry.name,/^[a-f0-9-]{36}$/,'ONBOARDING_STATE_REVIEW_REQUIRED');
        try{const stageDirectory=path.join(folder,entry.name),state=JSON.parse(await readFile(path.join(stageDirectory,'state.json')));assert.match(state.tenant,/^[a-z0-9_-]{1,64}$/);assert.equal(typeof state.eaiUsername,'string');out.push({...state,stageDirectory});}catch{throw Error('ONBOARDING_STATE_REVIEW_REQUIRED');}
    }
    return out;
}
export async function pendingClientReview(planFile,username){
    assert.ok(path.isAbsolute(planFile||''),'ABSOLUTE_PLAN_REQUIRED');assert.equal(typeof username,'string','CLIENT_INPUT_INVALID');
    const found=(await stageRecords(path.dirname(planFile))).filter(s=>!s.enabled&&s.eaiUsername.toLowerCase()===username.toLowerCase());
    assert.ok(found.length<=1,'ONBOARDING_MULTIPLE_STAGES_REVIEW_REQUIRED');if(!found.length)return null;
    const {tenant,phase,category,registryReviewRequired}=found[0];
    return {tenant,phase:/^[A-Z_]{1,64}$/.test(phase)?phase:'LOCAL_STATE_REVIEW_REQUIRED',category:/^[A-Z0-9_]{1,80}$/.test(category)?category:'INTERRUPTED_LOCAL_ONBOARDING',registryReviewRequired:!!registryReviewRequired};
}
async function writeState(stage,value){await atomicReplace(path.join(stage,'state.json'),JSON.stringify(value,null,2)+'\n');}
async function atomicReplace(target,bytes){const temp=target+'.'+randomUUID()+'.pending';await writeFile(temp,bytes,{flag:'wx',mode:0o600});await rename(temp,target);}
function errorCategory(error){const first=String(error?.message).split('\n')[0];return /^[A-Z0-9_]{1,80}$/.test(first)?first:'ONBOARDING_NOT_CONFIRMED';}
async function optionalBytes(filename){try{return await readFile(filename);}catch(e){if(e.code==='ENOENT')return null;throw e;}}
async function stagedClient(stage,meta,username){
    const manifestPath=path.join(stage,'source-manifest.json'),ruleProfilePath=path.join(stage,'rules.json');
    const manifestBytes=await readFile(manifestPath),profileBytes=await readFile(ruleProfilePath),manifest=JSON.parse(manifestBytes);
    assert.equal(manifest.customer,meta.tenant,'ONBOARDING_RESUME_IDENTITY_CHANGED');assert.equal(manifest.eaiUsername,username,'ONBOARDING_RESUME_IDENTITY_CHANGED');
    const saved=await optionalBytes(path.join(stage,'client.json'));
    const client=saved?JSON.parse(saved):{tenant:meta.tenant,username,directory:stage,manifestPath,manifestSha256:sha(manifestBytes),ruleProfilePath,ruleProfileSha256:sha(profileBytes),initialCursors:{positions:{0:'0'},canbushistory:'0',telemetry:{0:'0'},wiredtelemetry:{0:'0'}}};
    assert.equal(client.tenant,meta.tenant,'ONBOARDING_RESUME_IDENTITY_CHANGED');assert.equal(client.username,username,'ONBOARDING_RESUME_IDENTITY_CHANGED');
    for(const [key,value] of Object.entries({directory:stage,manifestPath,ruleProfilePath,manifestSha256:sha(manifestBytes),ruleProfileSha256:sha(profileBytes)}))assert.equal(client[key],value,'ONBOARDING_STAGE_CHANGED');
    return client;
}
function secretFilename(meta){const filename=meta.secretFile||'staged-secrets.dpapi';assert.match(filename,/^(?:staged-secrets|attempt-[a-f0-9-]{36})\.dpapi$/,'ONBOARDING_SECRET_PATH_INVALID');return filename;}
function checkSql(plan,client,sql){
    assert.equal(sql.tenant,client.tenant,'ONBOARDING_SECRET_IDENTITY_CHANGED');
    for(const connection of [sql.readerConnection,sql.publisherConnection]){assert.equal(connection?.host,'127.0.0.1','OWN_SQL_REQUIRED');assert.equal(connection.port,plan.sqlPort,'OWN_SQL_REQUIRED');assert.match(connection.database,/^suite_[a-f0-9]{16}$/,'OWN_SQL_REQUIRED');}
    assert.equal(sql.readerConnection.database,sql.publisherConnection.database,'ONBOARDING_SCHEMA_CHANGED');assert.equal(sql.publisherConnection.tenant,client.tenant,'ONBOARDING_SECRET_IDENTITY_CHANGED');
}
async function savedCapture(stage,meta,tenant){
    const filename=meta.captureFile||'pending-capture.json';assert.match(filename,/^(?:pending-capture|capture-[a-f0-9-]{36})\.json$/,'ONBOARDING_CAPTURE_PATH_INVALID');
    const bytes=await optionalBytes(path.join(stage,filename));if(!bytes)return null;
    if(meta.captureSha256)assert.equal(sha(bytes),meta.captureSha256,'ONBOARDING_CAPTURE_CHANGED');
    const capture=JSON.parse(bytes);assert.equal(capture?.tenant,tenant,'ONBOARDING_CAPTURE_IDENTITY_CHANGED');
    try{requireFirstCapture(capture,tenant);}catch{return null;}return capture;
}

// Dependencies provide transport/SQL/DPAPI and HTTP. Unit tests inject bounded
// offline fixtures; the CLI binds actual local SQL + the hash-pinned SDK.
export async function addLiveClient(planFile,input,deps){
    const directory=path.dirname(planFile);assert.ok(path.isAbsolute(planFile),'ABSOLUTE_PLAN_REQUIRED');
    const lockFile=path.join(directory,'registration.lock');let lock;try{lock=await open(lockFile,'wx');}catch{throw Error('REGISTRATION_LOCK_EXISTS_REVIEW_REQUIRED');}
    let client,stage,phase='INPUT',committing=false,sqlStarted=false,eaiCalls=0;const meta={result:'STAGED',enabled:false,eaiConnectionVerified:false,productionReady:false,automaticRetry:false};
    try{
        const planBytes=await readFile(planFile),plan=JSON.parse(planBytes);assert.equal(plan.directory,directory,'PLAN_DIRECTORY_MISMATCH');
        const configFile=path.join(directory,'http-config.json'),configBytes=await readFile(configFile),config=JSON.parse(configBytes);
        const identity=validateRequest(input,plan,config,await stageRecords(directory));
        await deps.assertStopped(plan); // SQL, API and worker/supervisor locks must be free.
        if(identity.previous){
            const {stageDirectory,...previous}=identity.previous;stage=stageDirectory;phase=previous.phase;Object.assign(meta,previous,{result:'STAGED',name:previous.name||input.name});
            client=await stagedClient(stage,meta,input.eaiUsername);
            await deps.verifySources(JSON.parse(await readFile(client.manifestPath)));
        }else{
        phase='STAGE';stage=path.join(directory,'client-onboarding',randomUUID());await mkdir(path.join(stage,'scratch'),{recursive:true,mode:0o700});
        Object.assign(meta,{tenant:identity.tenant,eaiUsername:input.eaiUsername,name:input.name,phase,utc:new Date().toISOString()});await writeState(stage,meta);
        const templatePath=input.sdkManifestPath||plan.sdkManifestPath||plan.clients[0]?.manifestPath;assert.ok(path.isAbsolute(templatePath||''),'SDK_MANIFEST_REQUIRED');
        const template=JSON.parse(await readFile(templatePath));await deps.verifySources(template);
        const manifest={customer:identity.tenant,eaiUsername:input.eaiUsername,sourceDirectory:template.sourceDirectory,archiveSha256:template.archiveSha256,sourceSha256:template.sourceSha256,bridgeRootSha256:template.bridgeRootSha256};
        const manifestPath=path.join(stage,'source-manifest.json'),manifestBytes=JSON.stringify(manifest);await writeFile(manifestPath,manifestBytes,{flag:'wx',mode:0o600});
        const extra=await deps.sdkExtra(plan,input);assert.ok(typeof extra==='string'&&!extra.includes('<storeFormat part="positions"><noStore'),'POSITIONS_FEED_REQUIRED');await writeFile(path.join(stage,'extra.xml'),extra,{flag:'wx',mode:0o600});
        const profile=genericProfile(identity.tenant),profileBytes=JSON.stringify(profile),ruleProfilePath=path.join(stage,'rules.json');await writeFile(ruleProfilePath,profileBytes,{flag:'wx',mode:0o600});
        client={tenant:identity.tenant,username:input.eaiUsername,directory:stage,manifestPath,manifestSha256:sha(manifestBytes),ruleProfilePath,ruleProfileSha256:sha(profileBytes),initialCursors:{positions:{0:'0'},canbushistory:'0',telemetry:{0:'0'},wiredtelemetry:{0:'0'}}};
        if(input.capacityPolicy!==undefined)client.capacityPolicy=input.capacityPolicy;
        await writeFile(path.join(stage,'client.json'),JSON.stringify(client),{flag:'wx',mode:0o600});
        meta.phase='PREPARED';await writeState(stage,meta);
        }
        const ruleProfilePath=client.ruleProfilePath;
        phase='SDK_PREFLIGHT';const pre=await deps.collect(plan,client,'PREFLIGHT_NO_TRANSPORT',client.initialCursors,true);assert.equal(pre.transportAttempts,0,'SDK_PREFLIGHT_MUST_NOT_CALL_EAI');
        const secrets=await deps.readSecrets(directory);assert.ok(!Object.hasOwn(secrets.clients,client.tenant),'SECRET_TENANT_ALREADY_EXISTS');
        phase='SQL_START';sqlStarted=true;await deps.startSql(plan,secrets);
        phase='SQL_PROVISION';let sql;
        if(identity.previous&&identity.previous.phase!=='PREPARED')sql=await deps.readSecrets(stage,secretFilename(meta));
        else{
            meta.phase=phase;await writeState(stage,meta); // No second schema after an ambiguous provision.
            const provisioned=await deps.provision(plan,secrets,client);sql={...provisioned,tenant:client.tenant,eaiPassword:input.eaiPassword};meta.schema=sql.readerConnection.database;
            await deps.writeSecrets(path.join(stage,'staged-secrets.dpapi'),sql);
            Object.assign(meta,{phase,secretFile:'staged-secrets.dpapi'});await writeState(stage,meta);
        }
        checkSql(plan,client,sql);
        const state=await deps.database(plan,sql.publisherConnection,'state',{tenant:client.tenant});assert.ok(state.generation===0||state.generation===1,'ONBOARDING_PUBLICATION_REVIEW_REQUIRED');
        let capture=identity.previous?await savedCapture(stage,meta,client.tenant):null;
        const captureTime=capture?Date.parse(capture.bundle.capturedUtc.replace(' ','T')+'Z'):NaN;
        if(state.generation===0&&capture&&Number.isFinite(captureTime)&&Date.now()-captureTime<=3590000&&Date.now()>=captureTime&&state.inFlight){
            // A saved successful response is published locally, not fetched again.
        }else if(state.generation===0){
        if(state.inFlight||String(state.status).startsWith('FAULT_'))await deps.database(plan,sql.publisherConnection,'recover',{tenant:client.tenant,review:recoveryReview(state,{intervalSeconds:plan.intervalSeconds})});
        if(identity.previous){
            const filename='attempt-'+randomUUID()+'.dpapi';sql={...sql,eaiPassword:input.eaiPassword};await deps.writeSecrets(path.join(stage,filename),sql);meta.secretFile=filename;
        }
        phase='FIRST_EAI_ATTEMPT';await deps.database(plan,sql.publisherConnection,'dispatch',{tenant:client.tenant,intervalSeconds:Math.max(60,plan.intervalSeconds)});
        // Attempt metadata is persisted before transport. Failure/crash never
        // turns into a hidden retry or a second registration of this account.
        Object.assign(meta,{phase,transportReserved:true,attemptCount:(meta.attemptCount||0)+1,lastAttemptUtc:new Date().toISOString()});await writeState(stage,meta);
        eaiCalls++;capture=await deps.collect(plan,client,sql.eaiPassword,client.initialCursors,false);
        const captureFile=await optionalBytes(path.join(stage,'pending-capture.json'))?'capture-'+randomUUID()+'.json':'pending-capture.json',captureBytes=JSON.stringify(capture);
        await writeFile(path.join(stage,captureFile),captureBytes,{flag:'wx',mode:0o600});Object.assign(meta,{captureFile,captureSha256:sha(captureBytes)});await writeState(stage,meta);requireFirstCapture(capture,client.tenant);
        }
        requireFirstCapture(capture,client.tenant);
        if(state.generation===0){
        phase='FIRST_PUBLICATION';const accepted=await deps.database(plan,sql.publisherConnection,'accept',{tenant:client.tenant,capture,ruleProfilePath,ruleProfileSha256:client.ruleProfileSha256});assert.equal(accepted.generation,1,'FIRST_GENERATION_REQUIRED');assert.ok(accepted.vehicleRows>0,'FIRST_PUBLICATION_EMPTY');
        }else assert.equal(capture.bundle.capturedUtc,state.capturedUtc,'ONBOARDING_PUBLICATION_CHANGED');
        meta.eaiConnectionVerified=true;meta.generation=1;await writeState(stage,{...meta,phase});
        let access;const accessFile=path.join(stage,'api-access.dpapi');
        if(await optionalBytes(accessFile))access=await deps.readSecrets(stage,'api-access.dpapi');
        else{
            let apiPassword;do{apiPassword=randomBytes(32).toString('hex');}while(apiPassword===sql.eaiPassword);
            access={tenant:client.tenant,account:identity.base.slice(0,40)+'-'+randomBytes(6).toString('hex'),apiPassword};await deps.writeSecrets(accessFile,access);
        }
        const {account,apiPassword}=access;assert.equal(access.tenant,client.tenant,'ONBOARDING_API_IDENTITY_CHANGED');assert.match(account,/^[a-z0-9_-]{1,64}$/,'ONBOARDING_API_IDENTITY_CHANGED');assert.match(apiPassword,/^[a-f0-9]{64}$/,'ONBOARDING_API_IDENTITY_CHANGED');assert.ok(!Object.hasOwn(config.accounts,account),'API_USERNAME_ALREADY_REGISTERED');
        // History access remains unconfigured until a deployment policy is supplied.
        const reader={...sql.readerConnection,staleReadPolicy:'last-known-with-warning'};
        const accountConfig={tenant:client.tenant,tokenSha256:sha(apiPassword),vehicleIds:[],scopeMode:'eai-account',endpoints:SUITE_ENDPOINTS};
        const proposed=structuredClone(config);proposed.options={...proposed.options,minIntervalSeconds:Math.max(60,proposed.options.minIntervalSeconds||60),enforceMinuteRule:true};proposed.source.historyDiagnosticEnabled=true;proposed.accounts[account]=accountConfig;proposed.source.tenants[client.tenant]=reader;
        phase='FIRST_HTTP_VERIFICATION';await writeState(stage,{...meta,phase});const httpStage=path.join(stage,'http-'+randomUUID());await mkdir(httpStage,{mode:0o700});await deps.verifyHttp(plan,httpStage,{...proposed,accounts:{[account]:accountConfig},source:{...proposed.source,tenants:{[client.tenant]:reader}}},{account,apiPassword,tenant:client.tenant,capture});
        // No credentials are exposed before actual mapping, SQL and HTTP all
        // succeed. The sheet is local/private; company EAI/SQL access excluded.
        client.account=account;client.legacyProviderComparison=false;const publicBase=publicBaseForPlan(plan);
        const sampleId=String(capture.bundle.feeds.vehicles[0].CWVehicleID),stamp=capture.bundle.capturedUtc.slice(0,19).replace('T',' ');
        const urls=Object.fromEntries(SUITE_ENDPOINTS.map(endpoint=>[endpoint,publicBase+'/cweai/'+account+'/'+endpoint+'?password='+encodeURIComponent(apiPassword)+(['positions','canbus','telemetry'].includes(endpoint)?'&vehicleid='+encodeURIComponent(sampleId)+'&ymdfrom='+stamp.slice(0,10)+'&ymdto='+encodeURIComponent(stamp):'')]));
        const sheet={profile:'LOCAL_EAI_CLIENT_ACCESS_V1',name:meta.name,username:account,password:apiPassword,urls,historyParameters:{vehicleid:'Replace with an authorized EAI vehicle ID',ymdfrom:'UTC inclusive start; no automatic EAI backfill',ymdto:'UTC inclusive end; update for new measurements'},rateLimitSeconds:60,historyPolicy:'NOT_CONFIGURED_UPSTREAM_AVAILABILITY_UNVERIFIED',historyCoverage:'COLLECTED_PAGES_NOT_COMPLETE_HISTORY',firstPublicationVerified:true,firstVerificationTransport:'LOCAL_LOOPBACK',publicUrlVerified:false,firstRequestNotBeforeUtc:new Date(Date.now()+61000).toISOString(),legacyProviderParity:false,productionReady:false};
        const sheetPath=path.join(stage,'ACCES-CLIENT-PRIVE.json');
        const afterSecrets=structuredClone(secrets);afterSecrets.clients[client.tenant]={eaiPassword:sql.eaiPassword,apiPassword,publisherConnection:sql.publisherConnection};
        phase='REGISTRY_COMMIT';assert.equal(sha(await readFile(planFile)),sha(planBytes),'PLAN_CHANGED_DURING_ONBOARDING');assert.equal(sha(await readFile(configFile)),sha(configBytes),'CONFIG_CHANGED_DURING_ONBOARDING');
        const backup=path.join(stage,'registry-before-'+randomUUID());await mkdir(backup);await copyFile(planFile,path.join(backup,'service-plan.json'));await copyFile(configFile,path.join(backup,'http-config.json'));await copyFile(path.join(directory,'secrets.dpapi'),path.join(backup,'secrets.dpapi'));
        const nextSecrets=path.join(backup,'secrets-next.dpapi');await deps.writeSecrets(nextSecrets,afterSecrets);
        committing=true;await writeState(stage,{...meta,phase,registryReviewRequired:true});await atomicReplace(path.join(directory,'secrets.dpapi'),await readFile(nextSecrets));await atomicReplace(configFile,JSON.stringify(proposed));
        plan.clients.push(client);await atomicReplace(planFile,JSON.stringify(plan,null,2));
        await writeFile(sheetPath,JSON.stringify(sheet,null,2)+'\n',{flag:'wx',mode:0o600});await writeState(stage,{...meta,result:'PASS',enabled:true,phase:'REGISTERED',account,firstPublicationVerified:true,httpEndpointsVerified:SUITE_ENDPOINTS,filesDeleted:0});
        committing=false;
        return {result:'PASS',tenant:client.tenant,account,accessSheetPath:sheetPath,apiPort:plan.apiPort,firstPublicationVerified:true,httpEndpointsVerified:5,eaiCalls,resumed:!!identity.previous,automaticRetry:false,serviceRestartRequired:true,productionReady:false};
    }catch(e){if(stage){const checkpoint=['SDK_PREFLIGHT','SQL_START','SQL_PROVISION'].includes(phase)&&meta.phase?meta.phase:phase;await writeState(stage,{...meta,result:'FAIL',enabled:false,phase:checkpoint,lastFailurePhase:phase,category:errorCategory(e),registryReviewRequired:committing||!!meta.registryReviewRequired,filesDeleted:0}).catch(()=>{});}throw Error(errorCategory(e));}
    finally{
        let shutdownConfirmed=!sqlStarted;try{if(sqlStarted){await deps.stopSql();shutdownConfirmed=true;}}finally{await lock.close();if(!committing&&shutdownConfirmed)await rename(lockFile,lockFile+'.closed-'+randomUUID());}
    }
}
