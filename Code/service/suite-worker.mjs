import {readFile,writeFile,appendFile,access} from 'node:fs/promises';import {spawn} from 'node:child_process';import {once} from 'node:events';import {createServer} from 'node:net';import http from 'node:http';import path from 'node:path';import assert from 'node:assert/strict';
import {apiRoot,serviceRoot,execute,spawnDatabase} from './runtime.mjs';import {collectSuite,suiteDatabase} from './suite-runtime.mjs';
import {statusWriter} from './status-writer.mjs';
import {publicBaseForPlan} from './deployment-config.mjs';
import {collectionHealth} from './collection-health.mjs';
const file=path.resolve(process.argv[2]),plan=JSON.parse(await readFile(file));assert.equal(plan.profile,'LOCAL_EAI_SUITE_V1');assert.equal(path.dirname(file),plan.directory);assert.ok(plan.intervalSeconds>=60&&plan.clients.length>=1&&plan.clients.length<=100);
publicBaseForPlan(plan);const managedHttp=!plan.deployment||plan.deployment.httpMode==='managed-local';
const httpConfiguration=JSON.parse(await readFile(path.join(plan.directory,'http-config.json')));
let stop=false,sql,web,secrets,guard,owned=false,stage='STARTING',shutdownConfirmed=false;const states={};const writeStatus=statusWriter(path.join(plan.directory,'service-status.json'));const status=()=>{const clients=Object.fromEntries(Object.entries(states).map(([tenant,s])=>[tenant,collectionHealth(s,{maxAgeSeconds:httpConfiguration.source?.tenants?.[tenant]?.maxSnapshotAgeSeconds??300})]));const degraded=Object.values(clients).some(s=>!s.synchronizationHealthy);return writeStatus({profile:plan.profile,stage,pid:process.pid,utc:new Date().toISOString(),clients,dataHealth:degraded?'DEGRADED':'HEALTHY',children:{sql:sql?.pid??null,http:web?.pid??null},shutdownConfirmed,rateLimitSeconds:plan.intervalSeconds,eaiFaultsAutomaticallyRetried:false,productionReady:false});};
const timer=setInterval(async()=>{try{if(JSON.parse(await readFile(path.join(plan.directory,'control.json'))).requested==='stop')stop=true;}catch{stop=true;}},500);for(const signal of ['SIGINT','SIGTERM'])process.on(signal,()=>stop=true);
// A slow remote page must not make a healthy worker look dead to the watchdog.
const heartbeat=setInterval(()=>{if(owned)status().catch(()=>stop=true);},5000);
try{
    try{await access(path.join(plan.directory,'registration.lock'));throw Error('CLIENT_REGISTRATION_IN_PROGRESS');}catch(error){if(error.code!=='ENOENT')throw error;}
    guard=createServer(socket=>socket.destroy());await new Promise((resolve,reject)=>{guard.once('error',reject);guard.listen(plan.lockPort,'127.0.0.1',resolve);});owned=true;
    await status();
    // Refuse an existing listener; never replace or stop an unrelated service.
    if(managedHttp){const reserve=createServer();reserve.listen(plan.apiPort,'127.0.0.1');await once(reserve,'listening');await new Promise(r=>reserve.close(r));}
    secrets=await execute('powershell.exe',['-NoProfile','-ExecutionPolicy','Bypass','-File',path.join(serviceRoot,'secrets.ps1'),'-Operation','Read','-Path',path.join(plan.directory,'secrets.dpapi')]);
    const root={host:'127.0.0.1',port:plan.sqlPort,username:'root',password:secrets.rootPassword};sql=spawnDatabase(plan);let ready=false;for(let i=0;i<50;i++){if(sql.exitCode!==null)throw Error('OWN_SQL_EXITED');try{await suiteDatabase(plan,root,'ready');ready=true;break;}catch{await new Promise(r=>setTimeout(r,200));}}assert.ok(ready,'OWN_SQL_NOT_READY');
    if(managedHttp){web=spawn(plan.php,['-n','-d',`extension_dir="${path.join(path.dirname(plan.php),'ext').replaceAll('\\','/')}"`,'-d','extension=php_mysqli.dll','-d','expose_php=0','-S',`127.0.0.1:${plan.apiPort}`,'-t',path.join(apiRoot,'public'),path.join(apiRoot,'public/router.php')],{cwd:apiRoot,windowsHide:true,stdio:'ignore',env:{...process.env,FLEET_API_CONFIG:path.join(plan.directory,'http-config.json'),FLEET_API_LOCAL_TEST:'1',FLEET_API_DEMO:'',FLEET_API_AUDIT_LOG:''}});
        await new Promise(r=>setTimeout(r,500));if(web.exitCode!==null)throw Error('OWN_HTTP_EXITED');}
    // Read every persisted account state before advertising readiness. An
    // empty initial list must not briefly report healthy synchronization.
    for(const client of plan.clients)states[client.tenant]=await suiteDatabase(plan,secrets.clients[client.tenant].publisherConnection,'state',{tenant:client.tenant});
    stage='RUNNING';await status();
    while(!stop){
        if(sql.exitCode!==null||(managedHttp&&web.exitCode!==null))throw Error('OWN_SERVER_EXITED');
        for(const client of plan.clients){if(stop)break;const secret=secrets.clients[client.tenant];try{
            const previous=await suiteDatabase(plan,secret.publisherConnection,'state',{tenant:client.tenant});states[client.tenant]={...previous,lastCycle:states[client.tenant]?.lastCycle};
            if(previous.inFlight||previous.status.startsWith('FAULT_')){states[client.tenant].attention='MANUAL_REVIEW_REQUIRED_NO_AUTOMATIC_RETRY';continue;}
            if(previous.lastAttemptUtc&&Date.now()-Date.parse(previous.lastAttemptUtc.replace(' ','T')+'Z')<(plan.intervalSeconds+1)*1000)continue;
            const capacity=await suiteDatabase(plan,secret.publisherConnection,'capacity-preflight',{tenant:client.tenant});states[client.tenant].capacity=capacity;
            await suiteDatabase(plan,secret.publisherConnection,'dispatch',{tenant:client.tenant,intervalSeconds:plan.intervalSeconds});states[client.tenant].status='COLLECTING';await status();
            const capture=await collectSuite(plan,client,secret.eaiPassword,previous.generation===0?client.initialCursors:previous.cursors);
            // Preserve the exact last received page privately before mapping.
            // This bounded local checkpoint avoids another external request
            // merely to investigate a failed mapping. History lives in SQL.
            await writeFile(path.join(client.directory,'pending-capture.json'),JSON.stringify(capture));
            const accepted=await suiteDatabase(plan,secret.publisherConnection,'accept',{tenant:client.tenant,capture,ruleProfilePath:client.ruleProfilePath,ruleProfileSha256:client.ruleProfileSha256});states[client.tenant]={...accepted,status:'PUBLISHED',lastCycle:accepted};
        }catch(e){const category=/^[a-zA-Z][a-zA-Z0-9_]{0,47}$/.test(e.message)?e.message.toUpperCase():'SUITE_CYCLE_FAILED';states[client.tenant]={...states[client.tenant],status:'FAULT_'+category,attention:'MANUAL_REVIEW_REQUIRED_NO_AUTOMATIC_RETRY'};await appendFile(path.join(plan.directory,'collection-incidents.ndjson'),JSON.stringify({utc:new Date().toISOString(),tenant:client.tenant,category,automaticRetry:false})+'\n').catch(()=>{});try{await suiteDatabase(plan,secret.publisherConnection,'fault',{tenant:client.tenant,category});}catch{states[client.tenant].faultPersistenceFailed=true;}}
        await status();}await status();await new Promise(r=>setTimeout(r,5000));
    }stage='STOPPING';await status();
}catch(e){stage='FAULTED';states.service={status:/^[a-zA-Z0-9_]+$/.test(e.message)?e.message:'SUITE_STARTUP_FAILED'};await appendFile(path.join(plan.directory,'service-lifecycle.ndjson'),JSON.stringify({utc:new Date().toISOString(),stage,code:/^[A-Z0-9_]{1,64}$/.test(e.code||'')?e.code:states.service.status})+'\n').catch(()=>{});process.exitCode=1;}
finally{
    clearInterval(timer);clearInterval(heartbeat);if(web&&web.exitCode===null){web.kill();await Promise.race([once(web,'exit'),new Promise(r=>setTimeout(r,3000))]);}
    if(sql&&sql.exitCode===null&&secrets){try{await suiteDatabase(plan,{host:'127.0.0.1',port:plan.sqlPort,username:'root',password:secrets.rootPassword},'shutdown');await Promise.race([once(sql,'exit'),new Promise(r=>setTimeout(r,5000))]);if(sql.exitCode===null)throw Error();}catch{stage='SHUTDOWN_UNCONFIRMED';process.exitCode=1;}}
    // Node reports a terminated HTTP child through signalCode on Windows.
    // A null exitCode alone must not mislabel that confirmed exit as running.
    shutdownConfirmed=(!sql||sql.exitCode!==null)&&(!web||web.exitCode!==null||web.signalCode!==null);if(!shutdownConfirmed){stage='SHUTDOWN_UNCONFIRMED';process.exitCode=1;}
    secrets=null;if(guard?.listening)guard.close();if(stage==='STOPPING')stage='STOPPED';if(owned)await status();else console.log('{"result":"FAIL","category":"SHARED_SERVICE_LOCK_UNAVAILABLE","eaiCalls":0}');
}
